<?php
/**
 * Transform the response body from a JSON string to an associative array.
 * A failure here should be presented to the caller (e.g. Electric) like "our vendor gave us bad output"
 * and not "you gave me bad input", that's why we re-throw a BadResponseException exception with status "Bad Gateway"
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Traits;

use Carsdotcom\ApiRequest\Exceptions\UpstreamException;
use Carsdotcom\ApiRequest\Helpers;

trait ParseResponseJSONOrThrow
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
            return json_decode($responseString, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UpstreamException(
                'Problem in ' . Helpers::friendlyClassName(static::class) . ', response was unreadable.',
                $e,
            );
        }
    }

    /**
     * Tell the server that we will be parsing the request as JSON
     */
    public function setAcceptHeaders(): void
    {
        $this->headers['Accept'] = 'application/json';
    }
}
