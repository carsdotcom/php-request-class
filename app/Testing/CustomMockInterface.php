<?php

namespace Carsdotcom\ApiRequest\Testing;

interface CustomMockInterface
{
    /**
     * @param array ...$function
     * @return \Mockery\Expectation
     */
    public function shouldReceive(...$function);
}