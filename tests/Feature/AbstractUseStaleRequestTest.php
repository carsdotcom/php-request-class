<?php
/**
 * Unit test the AbstractUseStaleRequest request class.
 */
declare(strict_types=1);

namespace Tests\Feature;

use Carsdotcom\ApiRequest\Testing\MocksGuzzleInstance;
use Carsdotcom\ApiRequest\Testing\RequestClassAssertions;
use GuzzleHttp\Psr7\Response;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\MockClasses\ConcreteUseStaleRequest;
use Tests\BaseTestCase;

/**
 * Class AbstractUseStaleRequestTest
 * @package Tests\Feature\Requests
 */
class AbstractUseStaleRequestTest extends BaseTestCase
{
    use MocksGuzzleInstance;
    use RequestClassAssertions;

    public function testUsesCachedResults(): void
    {
        $this->mockGuzzleWithTapper()->addMatchBody('GET', '/test/', 'kablooey', 500);
        $request = new ConcreteUseStaleRequest('thing');

        self::mockRequestCachedResponse($request, 'All good');
        Cache::put($request->cacheKey(), new Response(body: 'All good'));

        self::assertSame('All good', $request->sync());
    }

    public function testNextRequestHasFreshData(): void
    {
        $this->mockGuzzleWithTapper()->addMatchBody('GET', '/test/', 'new hotness');
        $request = new ConcreteUseStaleRequest('thing');

        self::mockRequestCachedResponse($request, 'Antique');
        self::assertTrue($request->needsRefresh());

        self::assertSame('Antique', $request->sync());
        self::assertSame('new hotness', $request->sync());
        self::assertFalse($request->needsRefresh());
        self::assertSame('new hotness', $request->sync());

        $this->assertTapperRequestLike('GET', '#test/thing#', 1);
        $this->expectTotalRequestCount(1);
    }

    public function testDeferredClosure(): void
    {
        Queue::fake();
        $this->mockGuzzleWithTapper()->addMatchBody('GET', '/test/', 'new hotness');
        $request = new ConcreteUseStaleRequest('thing');

        self::mockRequestCachedResponse($request, 'Antique');
        self::assertTrue($request->needsRefresh());

        self::assertSame('Antique', $request->sync());
        self::assertFalse(
            $request->needsRefresh(),
            'Refresh job is queued, we "snooze" needsRefresh to give it a chance to run.',
        );
        self::assertSame('Antique', $request->sync(), 'Refresh job hasn\'t run yet, returning stale data.');

        Queue::assertPushed(function (CallQueuedClosure $job) use ($request) {
            $job->closure->getClosure()();
            self::assertSame('new hotness', $request->sync(), 'Refresh job complete, requests return fresh data.');

            return true;
        });
    }

    public function testAbsentFromCacheGetsImmediately(): void
    {
        Queue::fake();
        $this->mockGuzzleWithTapper()->addMatchBody('GET', '/test/', 'new hotness');
        $request = new ConcreteUseStaleRequest('thing');

        self::assertSame('new hotness', $request->sync());
        $this->assertTapperRequestLike('GET', '#test/thing#', 1);
        $this->expectTotalRequestCount(1);
        Queue::assertNothingPushed();
    }

    public function testPurgesAllCacheKeys(): void
    {
        $this->mockGuzzleWithTapper()->addMatchBody('GET', '/test/', 'new hotness');
        $request = new ConcreteUseStaleRequest('thing');
        self::assertFalse($request->canBeFulfilledByCache());
        self::assertTrue($request->needsRefresh());

        $request->sync();
        self::assertTrue($request->canBeFulfilledByCache());
        self::assertFalse($request->needsRefresh());

        $request->purgeCache();
        self::assertFalse($request->canBeFulfilledByCache());
        self::assertTrue($request->needsRefresh());
    }

    public function testRefreshOnNextRequest(): void
    {
        $this->mockGuzzleWithTapper()->addMatchBody('GET', '/test/', 'new hotness');
        $request = new ConcreteUseStaleRequest('thing');
        self::assertFalse($request->canBeFulfilledByCache());
        self::assertTrue($request->needsRefresh());

        $request->sync();
        self::assertTrue($request->canBeFulfilledByCache());
        self::assertFalse($request->needsRefresh());

        $request->refreshOnNextRequest();
        self::assertTrue($request->canBeFulfilledByCache(), 'Cache is still available!');
        self::assertTrue($request->needsRefresh(), 'But we want to refresh opportunistically');
    }
}
