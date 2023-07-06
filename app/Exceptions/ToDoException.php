<?php

namespace Carsdotcom\ApiRequest\Exceptions;

use Exception;

class ToDoException extends Exception
{
    /**
     * ToDoException constructor.  Unlike baseline Exception, a string message is mandatory.
     * @param string $message
     */
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}