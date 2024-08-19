<?php
/**
 * Shared assertions about test cases.
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Testing;

use Carsdotcom\ApiRequest\AbstractRequest;
use Illuminate\Support\Facades\Cache;

trait RequestClassAssertions
{
    /**
     * Create a fake cache entry for a given request.
     */
    protected static function mockRequestCachedResponse(
        AbstractRequest $request,
        string $body,
        int $status = 200,
        array $headers = [],
        array $logs = [],
    ): void {
        $tags = getProperty($request, 'cacheTags');
        Cache::tags($tags)->put($request->cacheKey(), [
            'logs' => $logs,
            'response' => [$status, $headers, $body],
        ]);
    }

    /**
     * Fetch the cached response to a request and assert that it contains a substring
     */
    protected static function assertRequestCacheBodyContains(
        string $substring,
        AbstractRequest $request,
        string $message = '',
    ): void {
        $cached = callMethod($request, 'responseFromCache');
        self::assertNotNull($cached, 'Cache should not be empty for request.');
        self::assertStringContainsString($substring, (string) $cached->getBody(), $message);
    }
}
