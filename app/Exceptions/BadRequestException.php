<?php
/**
 * This should be used when data is missing to make a proper request.
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Response;

class BadRequestException extends HttpException
{
    /**
     * BadRequestException constructor.
     * @param string $message
     */
    public function __construct(string $message)
    {
        parent::__construct(Response::HTTP_BAD_REQUEST, $message);
    }
}
