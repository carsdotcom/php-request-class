<?php
/**
 * Abstract request that uses the pattern "use stale while refetching"
 * Concrete classes *must* implement a PHP 8.1 compatible serialization contract (__serialize and __unserialize) for the dispatched jobs to work.
 *
 * Your refreshAfter time should be much shorter than your cacheExpiresTime
 * You could even choose to have cacheExpiresTime return null,
 * so any good value is cached indefinitely and only replaced when the re-request succeeds.
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest;

use Carbon\Carbon;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

abstract class AbstractUseStaleRequest extends AbstractRequest
{
    protected function responseFromCache(): ?Response
    {
        $cachedResponse = parent::responseFromCache();
        if ($cachedResponse && $this->needsRefresh()) {
            Cache::tags($this->getCacheTags())->put(
                $this->refreshCacheKey(),
                self::CACHE_STALE_REFRESH_IS_QUEUED,
                $this->waitBetweenRefreshes(),
            );
            $reRequest = clone $this;
            dispatch(function () use ($reRequest) {
                try {
                    if ($reRequest->cacheIsCurrentlyFresh()) {
                        return;
                    }
                    $reRequest
                        ->setReadCache(false)
                        ->setWriteCache(true)
                        ->sync();
                } catch (\Throwable $e) {
                    $reRequest->onBackgroundRefreshFailed($e);
                }
            });
        }
        return $cachedResponse;
    }

    protected function writeResponseToCache(): void
    {
        if (!$this->shouldWriteResponseToCache()) {
            return;
        }
        Cache::tags($this->getCacheTags())->put($this->refreshCacheKey(), self::CACHE_IS_NOT_STALE, $this->refreshAfter());
        parent::writeResponseToCache();
    }

    abstract public function refreshAfter(): Carbon;

    public function waitBetweenRefreshes(): Carbon
    {
        return Carbon::now()->addMinutes(5);
    }

    public function refreshCacheKey(): string
    {
        return $this->cacheKey() . ':REFRESH';
    }

    public const CACHE_IS_NOT_STALE = 'refresh after';
    public const CACHE_STALE_REFRESH_IS_QUEUED = 'Wait between refreshes';

    public function needsRefresh(): bool
    {
        return !Cache::tags($this->getCacheTags())->has($this->refreshCacheKey());
    }

    public function cacheIsCurrentlyFresh(): bool
    {
        return Cache::tags($this->getCacheTags())->get($this->refreshCacheKey()) === self::CACHE_IS_NOT_STALE;
    }

    public function refreshOnNextRequest(): self
    {
        Cache::tags($this->getCacheTags())->forget($this->refreshCacheKey());
        return $this;
    }

    public function purgeCache(): self
    {
        $this->refreshOnNextRequest();
        return parent::purgeCache();
    }

    protected function onBackgroundRefreshFailed(\Throwable $e): void {}

    // Children of this class are *required* to thoughtfully implement their own PHP 8.1+ style serialization,
    // to work with `dispatch` in `responseFromCache`
    // https://php.watch/versions/8.1/serializable-deprecated
    abstract public function __serialize(): array;

    abstract public function __unserialize(array $data): void;
}
