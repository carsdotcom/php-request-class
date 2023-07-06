<?php
/**
 * This should be used if a resource isn't found by the remote resource.
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class NotFoundException extends HttpException
{
    /**
     * Create an exception, descended from HttpException for throwing.
     * It is highly suggested that you customize the message with the name of the resource that isn't found.
     * @param string $message
     */
    public function __construct(string $message = null, ?Throwable $previous = null)
    {
        parent::__construct(Response::HTTP_NOT_FOUND, $message ?: 'Not found', $previous);
    }
}
