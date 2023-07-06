<?php
/**
 * The HTTP status code 502 is defined as:
 * The server, while acting as a gateway or proxy, received an invalid response from the upstream server it accessed in attempting to fulfill the request.
 *
 * When possible, you should attach the previous exception to give future troubleshooters a leg up.
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UpstreamException extends HttpException
{
    /**
     * UpstreamException constructor.
     * @param string $message
     * @param \Throwable|null $previous
     * @param array $headers
     * @param int|null $code
     */
    public function __construct(string $message, \Throwable $previous = null, array $headers = [], ?int $code = 0)
    {
        parent::__construct(Response::HTTP_BAD_GATEWAY, $message, $previous, $headers, $code);
    }
}
