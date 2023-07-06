<?php
/**
 * Transform the response body from a JSON string to an associative array.
 * This is like ParseResponseJsonOrThrow, except we also validate the response from the vendor against a schema.
 * A failure here should be presented to the caller (e.g. Electric) like "our vendor gave us bad output"
 * and not "you gave me bad input", that's why we re-throw a BadResponseException exception with status "Bad Gateway"
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Traits;

use Carsdotcom\ApiRequest\Exceptions\UpstreamException;
use Carsdotcom\ApiRequest\Helpers;
use Carsdotcom\JsonSchemaValidation\SchemaValidator;
use Symfony\Component\HttpFoundation\Response;

trait ParseResponseJSONSchemaOrThrow
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
        if (!defined(static::class . '::RESPONSE_SCHEMA') || !static::RESPONSE_SCHEMA) {
            throw new \DomainException(
                static::class . ' must define a const RESPONSE_SCHEMA to use ParseResponseJSONSchemaOrThrow',
            );
        }

        try {
            $parsed = json_decode(json: $responseString, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UpstreamException(
                'Problem in ' . Helpers::friendlyClassName(static::class) . ', response was unreadable.',
                $e,
            );
        }

        SchemaValidator::validateOrThrow(
            $parsed,
            static::RESPONSE_SCHEMA,
            'Unexpected problem with ' .
            Helpers::friendlyClassName(static::class) .
            ' call: Response does not match expected schema',
            failureHttpStatusCode: Response::HTTP_BAD_GATEWAY, // status code blames upstream API, we are but a gateway
        );
        return $parsed;
    }

    /**
     * Tell the server that we will be parsing the request as JSON
     */
    public function setAcceptHeaders(): void
    {
        $this->headers['Accept'] = 'application/json';
    }
}
