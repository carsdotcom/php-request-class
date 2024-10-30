<?php
/**
 * Write a log file a Storage facade disk,
 * using a standardized file name (ISO-8601 with microseconds)
 * lots of helpful formatters, especially around Guzzle Requests, Responses, and Exceptions
 * and using a log structure provided by the caller.
 *
 * If you're thoughtful about building metadata into the log folder structure, it's easy to build a GUI to list and retrieve those logs
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest;

use Carbon\Carbon;
use Carsdotcom\JsonSchemaValidation\Contracts\HasExtendedExceptionData;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LogFile
{
    /** @const  Carbon format for log file names, ISO-8601 with microseconds */
    const NAME_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * Simple getter for the correct Laravel storage engine we use for log files
     * @return Filesystem    The correct Storage, usually local or S3
     */
    public static function disk(): Filesystem
    {
        return Storage::disk(config('api-request.logs_storage_disk_name'));
    }

    /**
     * Given a folder and an array of contents, create a new log file (named based on date and time) and upload it to the cloud.
     * Note, the caller should be making semantic decisions about the folder structure that make it easy to identify and retrieve.
     * @param  string $folder
     * @param  array $contents
     * @return ?string     generated file name or null for unrecoverable errors
     */
    public static function put(string $folder, array $contents): ?string
    {
        // Make sure folder ends with a /
        $folder = Str::finish($folder, '/');

        $filename = Carbon::now()->format(static::NAME_FORMAT);

        // You can pass in objects and we'll stringify them in the most awesome way we know how (or fall back to JSON)
        $lines = array_map(static::stringify_body(...), $contents);
        $file_body = implode("\n\n", $lines) . "\n";

        try {
            static::disk()->put($folder . $filename, $file_body);
            return $filename;
        } catch (\Exception $e) {
            // An error in the logging server ABSOLUTELY MAY NOT halt normal operations
            Log::error("Problem saving a LogFile in {$folder} : " . $e->getMessage());
            return null;
        }
    }

    /**
     * Given... something, figure out the best way to turn it into a string.
     * Strings pass through
     * objects we want a special representation for we'll handle (like Guzzle requests, responses, and exceptions)
     * non-special objects we'll try to use the magic  __toString() method (Eloquent Models, for example)
     * everything else falls back to JSON
     * @param mixed $body
     * @return string
     * @throws \JsonException
     */
    public static function stringify_body($body): string
    {
        if (is_string($body)) {
            return $body;
        } elseif ($body instanceof Request) {
            $string = $body->getMethod() . ' ' . $body->getUri() . "\n\n";
            if (!empty($requestHeaders = static::interestingRequestHeaders($body))) {
                $string .= "Request headers include:\n";
                foreach ($requestHeaders as $key => $values) {
                    foreach ($values as $value) {
                        $string .= "$key: $value\n";
                    }
                }
                $string .= "\n";
            }

            $request_body = (string) $body->getBody();
            if ($request_body) {
                $string .= static::beautifyIfJson($request_body);
            }
            return trim($string);
        } elseif ($body instanceof Response) {
            $string = 'Response Status Code ' . $body->getStatusCode() . "\n\n";
            if (!empty($responseHeaders = static::interestingResponseHeaders($body))) {
                $string .= "Response headers include:\n";
                foreach ($responseHeaders as $key => $values) {
                    foreach ($values as $value) {
                        $string .= "$key: $value\n";
                    }
                }
                $string .= "\n";
            }
            $response_body = (string) $body->getBody();
            if (empty($response_body)) {
                $string .= 'Empty Response Body';
            } else {
                $string .= static::beautifyIfJson($response_body);
            }
            return $string;
        } elseif ($body instanceof RequestException) {
            $string = 'Request Exception: ' . $body->getMessage();

            if ($body->hasResponse()) {
                $string .= "\n\n" . static::stringify_body($body->getResponse());
            }
            return $string;
        } elseif ($body instanceof \Throwable) {
            $string = 'Exception thrown: ' . get_class($body) . "\n" . $body->getMessage() . "\n";
            if ($body instanceof HasExtendedExceptionData) {
                $string .= json_encode($body->getExtendedData(), JSON_PRETTY_PRINT) . "\n";
            }
            return $string;
        } elseif ($body instanceof TransferStats) {
            return sprintf('Transfer time: %ss', $body->getTransferTime());
        } elseif (is_object($body) && method_exists($body, ' __toString')) {
            return $body->toString();
        }

        return json_encode($body, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Any AbstractRequest can implement a descendent of this class and override this method to start logging interesting Response headers
     * We log no headers by default because in most requests it's a combination of staggeringly boring (Accept, Content-Type)
     * and must-not-be-logged (Authentication)
     *
     * But we do have partners who put vital information in headers (trace-id, x-ciq-request-id) so implementers
     * can make a thoughtful choice to log headers appropriate to be logged.
     */
    public static function interestingResponseHeaders(Response $response): array
    {
        return []; // Log no headers by default
        // A typical implementation may look like:
        // return Arr::only($response->getHeaders(), ['x-ciq-request-id']);
    }

    public static function interestingRequestHeaders(Request $request): array
    {
        return []; // Log no headers by default
        // A typical implementation may look like:
        // return Arr::only($request->getHeaders(), ['x-ciq-request-id']);
    }

    /**
     * If a string is JSON, decode it and re-encode it with pretty-print.
     * If the string is *not* JSON, just pass through.
     */
    public static function beautifyIfJson(string $string): string
    {
        try {
            return json_encode(json_decode($string, flags: JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT);
        } catch (\Throwable) {
        }
        return $string;
    }

    /**
     * If you need regex matching on files in a specific folder, this is your baby. Note, this could be unimaginably expensive, it's like running `find | egrep` ... over a network.
     * @param  string     $path   Please try to limit your search as much as possible. This param requires a literal, non-pattern. "" is valid but not recommended.
     * @param  string     $regex NOTE: The argument is Regular Expression syntax over the entire file path, *not* glob.
     * @return Collection
     */
    public static function files_like(string $path, string $regex): Collection
    {
        return collect(static::disk()->allFiles($path))
            ->filter(function ($filename) use ($regex) {
                return preg_match($regex, $filename);
            })
            ->values(); //Re-index, helps JSON stringifying
    }

    /**
     * Given a path in static::disk(), return all files' basename
     * @param string $folder
     * @return Collection
     */
    public static function filesInFolder(string $folder): Collection
    {
        return collect(static::disk()->files($folder))->map(function ($full_name) {
            return basename($full_name);
        });
    }
}
