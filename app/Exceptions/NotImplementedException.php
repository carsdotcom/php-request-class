<?php
/**
 * This should be used when a method or API call is not implemented.
 * This could be temporary, in which case the $message prop can function like a TODO or contain the ticket number
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Exceptions;

use Exception;
use Throwable;

class NotImplementedException extends Exception
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        if (!$message) {
            $caller = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2)[1];
            $message =
                class_basename($caller['object'] ?? $caller['class']) .
                '->' .
                $caller['function'] .
                '()' .
                ' is not implemented.';
        }
        parent::__construct($message, $code, $previous);
    }
}
