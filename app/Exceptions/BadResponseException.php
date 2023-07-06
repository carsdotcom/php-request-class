<?php

namespace Carsdotcom\ApiRequest\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * This should be used for any responses that are not received in the desired format or if an external API call doesn't
 * work as intended.
 */
class BadResponseException extends HttpException
{

}
