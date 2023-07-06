<?php
/**
 * Transform the response body from a JSON string to an associative array
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Traits;

trait ParseResponseJSON
{
    /**
     * Given a string response body from Guzzle, parse it into an associative array
     * @param string $responseString
     * @return mixed    Any JSON primitive, usually an associative array but could be null, bool, or string, too
     */
    public function parseResponseString(string $responseString): mixed
    {
        return json_decode($responseString, true);
    }

    /**
     * Tell the server that we will be parsing the request as JSON
     */
    public function setAcceptHeaders(): void
    {
        $this->headers['Accept'] = 'application/json';
    }
}
