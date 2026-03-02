<?php
/**
 * Unit test the AbstractUseStaleRequest request class.
 */
declare(strict_types=1);

namespace Tests\Feature;

use Carbon\Carbon;
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

    public function testCacheBehaviorUnderHeavyLoad(): void
    {
        Queue::fake();
        $this->mockGuzzleWithTapper()->addMatchBody('GET', '/test/', 'constant');
        $request = new ConcreteUseStaleRequest('thing');

        // Never called
        self::assertTrue($request->needsRefresh());
        self::assertFalse($request->canBeFulfilledByCache());

        // First called
        Carbon::setTestNow('2026-01-01 00:00:00');
        self::assertSame('constant', $request->sync());
        self::assertFalse($request->needsRefresh());
        self::assertTrue($request->canBeFulfilledByCache());
        Queue::assertNothingPushed();
        $this->assertAllTapperRequestsLike([['GET', '#test/thing#']]);

        // Called before refreshAfter, still in cache
        Carbon::setTestNow('2026-01-01 00:01:00');
        self::assertFalse($request->needsRefresh());
        self::assertTrue($request->canBeFulfilledByCache());
        self::assertSame('constant', $request->sync());
        Queue::assertNothingPushed(); // No deferred refresh
        $this->assertAllTapperRequestsLike([['GET', '#test/thing#']]); // no additional calls

        // Called after refreshAfter, still in cache
        Carbon::setTestNow('2026-01-01 00:16:00');
        self::assertTrue($request->needsRefresh());
        self::assertTrue($request->canBeFulfilledByCache());
        self::assertSame('constant', $request->sync());
        Queue::assertPushed(CallQueuedClosure::class);
        $this->assertAllTapperRequestsLike([['GET', '#test/thing#']]); // no additional calls (because we broke the job)
        self::assertFalse($request->needsRefresh()); // Gets deferred by waitBetweenRefreshes (5 min)
        self::assertTrue($request->canBeFulfilledByCache());

        // Called after the refresh job failed, but before waitBetweenRefreshes
        Queue::fake(); // Discard the "failing" refresh job
        Carbon::setTestNow('2026-01-01 00:17:00');
        self::assertFalse($request->needsRefresh());
        self::assertTrue($request->canBeFulfilledByCache());
        self::assertSame('constant', $request->sync());
        Queue::assertNothingPushed(); // No deferred refresh
        $this->assertAllTapperRequestsLike([['GET', '#test/thing#']]); // no additional calls (because we broke the job)

        // Called after waitBetweenRefreshes
        Carbon::setTestNow('2026-01-01 00:22:00');
        self::assertTrue($request->needsRefresh());
        self::assertTrue($request->canBeFulfilledByCache());
        self::assertSame('constant', $request->sync());
        Queue::assertPushed(function (CallQueuedClosure $job) use (&$callOnOurTerms) {
            // deferred refresh
            $callOnOurTerms = $job->closure->getClosure();
            return true;
        });
        $this->assertAllTapperRequestsLike([['GET', '#test/thing#']]); // no additional calls (the job is waiting for us)

        // Job happens!
        Carbon::setTestNow('2026-01-01 00:22:01');
        $callOnOurTerms();
        self::assertFalse($request->needsRefresh());
        self::assertTrue($request->canBeFulfilledByCache());
        $this->assertAllTapperRequestsLike([['GET', '#test/thing#'], ['GET', '#test/thing#']]); // A second call!

        // Another call, after the job and after waitBetweenRefreshes, but before refreshAfter
        Carbon::setTestNow('2026-01-01 00:35:01');
        self::assertFalse($request->needsRefresh()); // I won't need another refresh until refreshAfter
        self::assertTrue($request->canBeFulfilledByCache());
        self::assertSame('constant', $request->sync());
        $this->assertAllTapperRequestsLike([['GET', '#test/thing#'], ['GET', '#test/thing#']]); // no third call, was in cache

        // Another call, after the job and refreshAfter
        Carbon::setTestNow('2026-01-01 00:37:02');
        self::assertTrue($request->needsRefresh()); // I won't need another refresh until refreshAfter
        self::assertTrue($request->canBeFulfilledByCache());
        self::assertSame('constant', $request->sync());
        Queue::assertPushed(function (CallQueuedClosure $job) use (&$callOnOurTerms) {
            // deferred refresh
            $callOnOurTerms = $job->closure->getClosure();
            return true;
        });
        $this->assertAllTapperRequestsLike([['GET', '#test/thing#'], ['GET', '#test/thing#']]); // no third call, was in cache

        // Second job succeeds
        $callOnOurTerms();
        self::assertFalse($request->needsRefresh());
        self::assertTrue($request->canBeFulfilledByCache());
        $this->assertAllTapperRequestsLike([['GET', '#test/thing#'], ['GET', '#test/thing#'], ['GET', '#test/thing#']]); // A third call!

        // Called well after cacheExpiresTime
        Queue::fake(); // Discard the jobs above
        Carbon::setTestNow('2026-01-05 00:00:00');
        self::assertTrue($request->needsRefresh());
        self::assertFalse($request->canBeFulfilledByCache());
        self::assertSame('constant', $request->sync());
        Queue::assertNothingPushed(); // No deferred refresh
        $this->assertAllTapperRequestsLike([['GET', '#test/thing#'], ['GET', '#test/thing#'], ['GET', '#test/thing#'], ['GET', '#test/thing#']]); // Immediately calls
    }
}
