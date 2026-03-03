<?php
/**
 * Concrete class for use unit testing AbstractUseStaleRequest
 * (our usual process of creating anonymous descendants won't work because we need to be able to serialize them for the deferred job
 */
declare(strict_types=1);

namespace Tests\MockClasses;

use Carbon\Carbon;
use Carsdotcom\ApiRequest\AbstractUseStaleRequest;

class ConcreteUseStaleRequest extends AbstractUseStaleRequest
{
    protected string $method = 'GET';

    public function __construct(protected string $param)
    {
    }

    public function getURL(): string
    {
        return "https://example.com/test/{$this->param}";
    }

    public function refreshAfter(): Carbon
    {
        return Carbon::now()->addMinutes(15);
    }

    public function getLogFolder(): string
    {
        return 'concrete/use_stale';
    }

    public function __serialize(): array
    {
        return [
            'param' => $this->param,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->param = $data['param'];
    }
}
