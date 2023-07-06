<?php

namespace Carsdotcom\ApiRequest\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Our authentication to the API failed
 */
class RemoteAuthentication extends HttpException
{
    /**
     * Create an exception, descended from HttpException for throwing.
     * Optional message is a good way to elaborate what, if anything, you can do to troubleshoot this. (e.g., change value X in integration Y)
     * @param string $message
     */
    public function __construct(string $message = null)
    {
        parent::__construct(
            Response::HTTP_UNAUTHORIZED,
            $message ?: "Could not authenticate to the remote service"
        );
    }
}
