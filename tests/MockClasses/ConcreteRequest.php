<?php
/**
 * Fill in the AbstractRequest abstract methods with boring defaults for unit tests.
 */
declare(strict_types=1);

namespace Tests\MockClasses;

use Carsdotcom\ApiRequest\AbstractRequest;

/**
 * Class ConcreteRequest
 * @package Tests\MockClasses
 */
class ConcreteRequest extends AbstractRequest
{
    protected bool $shouldLog = false;

    public function getURL(): string
    {
        return 'https://awesome-api.com/url';
    }
}
