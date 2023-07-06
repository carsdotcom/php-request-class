<?php
/**
 * Transform the response body from a GraphQL-flavored JSON string to an associative array of just the response `data`.
 * A failure here should be presented to the caller (e.g. Electric) like "our vendor gave us bad output"
 * and not "you gave me bad input", that's why we re-throw UpstreamException with status "Bad Gateway"
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Traits;

use Carsdotcom\ApiRequest\Exceptions\UpstreamException;
use Carsdotcom\ApiRequest\Helpers;
use Illuminate\Support\Arr;

trait ParseResponseGraphQL
{
    /**
     * Given a string response body from Guzzle, parse it into an associative array
     *
     * @param string $responseString
     * @return mixed    Any JSON primitive, usually an associative array but could be null, bool, or string, too
     * @throws UpstreamException that correctly identifies the remote party as being at fault
     */
    public function parseResponseString(string $responseString): mixed
    {
        try {
            $parsed = json_decode($responseString, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UpstreamException(
                'Problem in ' . Helpers::friendlyClassName(static::class) . ', response was unreadable.',
                $e,
            );
        }

        if (Arr::get($parsed, 'errors', [])) {
            //Bugsnag::leaveBreadcrumb('GraphQL Error', metaData: $parsed['errors'][0]);
            $message = Arr::get($parsed, 'errors.0.message', 'Unspecified GraphQL error');
            throw new UpstreamException('Trouble calling ' . Helpers::friendlyClassName($this) . ': ' . $message);
        }

        if (!Arr::has($parsed, 'data')) {
            throw new UpstreamException(Helpers::friendlyClassName($this) . ' received no data from the server!');
        }
        return $parsed['data'];
    }
}
