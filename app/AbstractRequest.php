<?php
/**
 * A standard structure for requests to outside APIs, includes functionality we wish all requests had:
 *
 * Caching: automatically cache results where the URL and request body are identical. (On by default)
 * Logging: (if configured) log the exact URL, request body, and response status codes, response body, and any exceptions
 * Postprocessing: (if configured) after caching, perform additional data massaging on the response, using local state
 *     e.g., popping the first value off an array or converting response JSON into internal objects
 *
 * What do children need?
 * Children MUST implement the getURL() method (uses object state to generate a suitable URL)
 * Children SHOULD update the $method attribute to one of POST (the default), GET, HEAD, PATCH, etc
 * Children MAY implement getLogFolder() to indicate where logs should be stored (if omitted, logging is disabled)
 * Children MAY implement postProcess() to turn the parsed response body into a more useful format for our application
 */
namespace Carsdotcom\ApiRequest;

use Carsdotcom\ApiRequest\Exceptions\ToDoException;
use Carbon\Carbon;
use DomainException;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

abstract class AbstractRequest
{
    protected bool $shouldLog = true;
    protected bool $shouldReadCache = true;
    protected bool $shouldWriteCache = true;

    /** @var array Tags to be used when inserting to cache */
    protected array $cacheTags = [];

    /** @var string Request method, for use in toGuzzle */
    protected string $method = 'POST';

    /** @var array of Headers to attach to the request. Children can override the definition here or add/remove at any time before toGuzzle */
    protected array $headers = [];

    /** @var array  key-value array that will be transformed into query parameters */
    protected array $arguments = [];

    /** @var array Configuration options for the underlying Guzzle client */
    protected array $guzzleOptions = [];

    // Array of file names (relative to getLogFolder()) that have been saved by this request instance.
    protected array $sentLogs = [];

    // The most recent response, if this instance has been sent.
    protected ?Response $response = null;
    protected bool $responseIsFromCache = false;

    /**
     * Use object state to generate an appropriate URL for the request.
     * Must be implemented by the child.
     * @return string
     */
    abstract public function getURL(): string;

    /**
     * Fluent setter for shouldWriteCache
     */
    public function setWriteCache(bool $setting): self
    {
        $this->shouldWriteCache = $setting;
        return $this;
    }

    /**
     * Fluent setter for shouldReadCache
     */
    public function setReadCache(bool $setting): self
    {
        $this->shouldReadCache = $setting;
        return $this;
    }

    /**
     * Fluent setter for shouldLog
     */
    public function setLog(bool $setting): self
    {
        $this->shouldLog = $setting;
        return $this;
    }

    /**
     * Returns a string folder name, within LogFile::disk() -- which in turn is api-logs in config/filesystems.php
     * This method must be overridden by children classes before they can use $this->shouldLog = true
     * Typically incorporates local state like vehicle VIN or deal UUID.
     * @return string
     * @throws ToDoException
     */
    public function getLogFolder(): string
    {
        throw new ToDoException(
            'To enable request logging, ' . get_class($this) . ' will have to implement the method getLogFolder',
        );
    }

    /**
     * Time-intensive tasks that are required before this request can proceed,
     * but don't have to be handled synchronously when the request is instantiated (unlike __construct)
     * @return PromiseInterface
     */
    public function prerequisites(): PromiseInterface
    {
        return new FulfilledPromise(true);
    }

    /**
     * Post-process the (already parsed) response body into a format the application cares about.
     * This implementation just passes through, should be overwritten by the child implementation as needed.
     * @param $parsed
     * @return mixed
     */
    public function postProcess($parsed)
    {
        return $parsed;
    }

    /**
     * This is handled last in the request chain, and offers an opportunity to catch and handle
     * any exception at any point in the process.
     * Unless overridden by the child implementation, this one is a no-op
     * @param $reason
     * @return mixed    Can return a value to be treated as success,
     *      or a RejectedPromise (including a different rejection reason than the incoming $reason,
     *      e.g. a more specific or colorful exception)
     */
    public function otherwise($reason)
    {
        return new RejectedPromise($reason); // Continue to reject
    }

    /******************************************************************************
     * Implementation details below this line.
     * If you're implementing a new child of this abstract,
     * it's not essential that you're intimately familiar with things below,
     * but please be familiar with everything above.
     *****************************************************************************/

    /**
     * @var mixed Body for the request.
     * Internal scratch-pad to assemble the request body. This will NOT be attached to the outgoing
     * request unless the request class implements encodeBody (e.g., by using the trait EncodeRequestJSON)
     */
    protected $body = [];

    /**
     * Simple getter, especially for unit tests.
     * @return mixed
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * Shortcut for Arr:set on $this->body.
     * @param string $key
     * @param        $value
     */
    public function setBodyKV(string $key, $value): void
    {
        Arr::set($this->body, $key, $value);
    }

    /**
     * Shortcut for Arr:set on $this->body, only if $value is not, in PHP's judgement, empty()
     * This can be really useful for things with default-null behavior, like $person->zip
     * @param string $key
     * @param        $value
     */
    public function setBodyIfNotEmpty(string $key, $value): void
    {
        if (!empty($value)) {
            Arr::set($this->body, $key, $value);
        }
    }

    /**
     * Can be overridden by trait or children to include Content-Type headers on $this->headers
     */
    public function setContentHeaders(): void
    {
    }

    /**
     * Can be overridden by trait or children to include Accept headers on $this->headers
     */
    public function setAcceptHeaders(): void
    {
    }

    /**
     * This should be overridden by the Encode traits in app/Requests/Traits to turn the request body into a suitable string
     */
    public function encodeBody(): ?string
    {
        return null;
    }

    /**
     * Return a Guzzle Request with all the set up you've done
     * @return Request
     */
    public function toGuzzle(): Request
    {
        $this->setContentHeaders();
        $this->setAcceptHeaders();

        return new Request($this->method, $this->getURL(), $this->headers, $this->encodeBody());
    }

    /**
     * Simple readonly getter to read the arguments so far.
     * @return array
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * Simple readonly getter to read the headers so far.
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Call this request asynchronously
     * @return PromiseInterface
     */
    public function async(): PromiseInterface
    {
        // It's expected that prereqs can alter the request body and cache key
        return $this->prerequisites()
            ->then(function () {
                if (null !== ($cached = $this->responseFromCache())) {
                    $this->responseIsFromCache = true;
                    $this->response = $cached;
                    $promise = new FulfilledPromise($cached);
                } else {
                    $promise = App::make('guzzle')
                        ->sendAsync($this->toGuzzle(), $this->guzzleOptions)
                        ->then(function (Response $response) {
                            $this->responseIsFromCache = false;
                            $this->log($response);
                            return $this->response = $response;
                        })
                        ->otherwise(function ($exception) {
                            $this->log($exception);
                            if ($exception instanceof BadResponseException) {
                                $this->response = $exception->getResponse();
                            }
                            return new RejectedPromise($exception);
                        });
                }

                return $promise
                    ->then($this->parseResponseBody(...))
                    ->then($this->postProcess(...))
                    // Don't cache unless parse and postprocess were *both* successful
                    ->then(function ($postProcessed) {
                        $this->writeResponseToCache();
                        return $postProcessed;
                    });
            })
            ->otherwise($this->otherwise(...));
    }

    public function purgeCache(): self
    {
        Cache::tags($this->cacheTags)->forget($this->cacheKey());
        return $this;
    }

    protected function shouldWriteResponseToCache(): bool
    {
        return $this->shouldWriteCache && !$this->responseIsFromCache;
    }

    protected function writeResponseToCache(): void
    {
        if (!$this->shouldWriteResponseToCache()) {
            return;
        }

        // Streams can't be cached by Laravel,
        // so we flatten the body to strings then rehydrate the Response class manually
        Cache::tags($this->cacheTags)->put(
            $this->cacheKey(),
            [
                // matches the argument order for Response constructor
                $this->response->getStatusCode(),
                $this->response->getHeaders(),
                (string) $this->response->getBody(),
                $this->response->getProtocolVersion(),
                $this->response->getReasonPhrase(),
            ],
            $this->cacheExpiresTime(),
        );
    }

    protected function responseFromCache(): ?Response
    {
        if (!$this->shouldReadCache) {
            return null;
        }

        $fromCache = Cache::tags($this->cacheTags)->get($this->cacheKey());
        return $fromCache ? new Response(...$fromCache) : null;
    }

    /**
     * For caching the response, how long should the cache be valid?
     * By default, this is one day. To customize, override this method.
     * i.e. If you need logic (e.g., read from an Expires: header in the response) override this method.
     */
    public function cacheExpiresTime(): ?Carbon
    {
        /*
         * Legacy usage of this class could define an $expires property instead of overriding this method.
         * If we find that property, notice about it (and use it for now).
         */
        if (property_exists(get_class($this), 'expires')) {
            Log::notice("Deprecation notice: " . Helpers::friendlyClassName($this) . " has an `expires` property defined for expiration. Please update it to override the `cacheExpiresTime` method instead.");
            return Carbon::now()->addMinutes($this->expires);
        }
        return Carbon::now()->addDay();
    }

    /**
     * Log this request and outcome to the folder returned by getLogFolder
     * Note, this *may* be overridden by children that don't want to use LogFile
     *     (e.g., AbstractShiftLeadRequest uses LeadLog instead)
     *
     * @param mixed $outcome typically a Response, can also be an Exception
     *
     * @return void
     */
    public function log($outcome): void
    {
        if (!$this->shouldLog) {
            return;
        }
        $logged = LogFile::put($this->getLogFolder(), [$this->toGuzzle(), $outcome]);
        if ($logged) {
            $this->sentLogs[] = $logged;
        }
    }

    /**
     * Get the log file for the last run of this instance of this request, using getLogFolder.
     * @throws DomainException if this instance has never logged (could mean never run, or $shouldLog is false)
     */
    public function getLastLogContents(): string
    {
        if (!$this->sentLogs) {
            throw new DomainException('No log files have been saved by this instance.');
        }
        return LogFile::disk()->get($this->getLastLogFile());
    }

    /**
     * Can be overridden by children or trait to parse the body in a known format (e.g. JSON or XML)
     * @param string $responseString
     * @return mixed (as dictated by traits like ParseResponseJson)
     */
    public function parseResponseString(string $responseString): mixed
    {
        return $responseString;
    }

    public function stringifyResponseBody(): string
    {
        return ((string) $this->response?->getBody()) ??
            throw new DomainException('Cannot parse response body, response is not set!');
    }

    /**
     * Given that a GuzzleResponse is set on $this->response, get the body and parse it.
     * @return mixed
     */
    public function parseResponseBody(): mixed
    {
        return $this->parseResponseString($this->stringifyResponseBody());
    }

    /**
     * Call this request synchronously
     * @return mixed parsed (and by default post-processed) result of the call
     */
    public function sync()
    {
        return $this->async()->wait();
    }

    /**
     * Returns the saved response object
     *
     * @return Response|null
     */
    public function getResponse(): ?Response
    {
        return $this->response;
    }

    /**
     * Return cache key string based on this class, URL, and request body
     * @return string
     */
    public function cacheKey(): string
    {
        return hash(
            'sha256',
            json_encode([
                self::class,
                $this->getURL(),
                $this->encodeBody(),
                config('api-request.cache_key_seed', 'v2022.4.12.0'),
            ]),
        );
    }

    public function canBeFulfilledByCache(): bool
    {
        return Cache::tags($this->cacheTags)->has($this->cacheKey());
    }

    /**
     * @return string
     */
    public function getLastLogFile(): string
    {
        return Str::finish($this->getLogFolder(), '/') . Arr::last($this->sentLogs);
    }
}