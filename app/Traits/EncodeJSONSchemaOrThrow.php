<?php

declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Traits;

use Carsdotcom\ApiRequest\Helpers;
use Carsdotcom\JsonSchemaValidation\SchemaValidator;
use Symfony\Component\HttpFoundation\Response;

trait EncodeJSONSchemaOrThrow
{
    /**
     * Include Content-Type headers on $this->headers
     */
    public function setContentHeaders(): void
    {
        $this->headers['Content-Type'] = 'application/json';
    }

    /**
     * validate the request body against the schema before json encoding
     * @throws \DomainException that correctly identifies the remote party as being at fault
     */
    public function encodeBody(): string
    {
        $requestBody = $this->body;
        if (!defined(static::class . '::REQUEST_SCHEMA') || !static::REQUEST_SCHEMA) {
            throw new \DomainException(
                static::class . ' must define a const REQUEST_SCHEMA to use EncodeJSONSchemaOrThrow',
            );
        }

        SchemaValidator::validateOrThrow(
            $requestBody,
            static::REQUEST_SCHEMA,
            'Unexpected problem with ' .
                Helpers::friendlyClassName(static::class) .
                ' call: Request does not match expected schema',
            failureHttpStatusCode: Response::HTTP_INTERNAL_SERVER_ERROR,
        );
        return json_encode($requestBody);
    }
}
