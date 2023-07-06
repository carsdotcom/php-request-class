<?php

namespace Tests\Feature;

use Carsdotcom\ApiRequest\Exceptions\UpstreamException;
use Carsdotcom\ApiRequest\Exceptions\ToDoException;
use Carsdotcom\ApiRequest\Traits\EncodeRequestJSON;
use Carsdotcom\ApiRequest\Traits\ParseResponseJSON;
use Carsdotcom\ApiRequest\Traits\ParseResponseJSONOrThrow;
use Carbon\Carbon;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\RejectionException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\BaseTestCase;
use Tests\MockClasses\ConcreteRequest;
use Carsdotcom\ApiRequest\Testing\MocksGuzzleInstance;
use TiMacDonald\Log\LogEntry;
use TiMacDonald\Log\LogFake;

class AbstractRequestTest extends BaseTestCase
{
    use MocksGuzzleInstance;

    protected function mockRequestWithLog()
    {
        return new class extends ConcreteRequest {
            use ParseResponseJSON;

            protected bool $shouldLog = true;

            public $logged;
            public function log($outcome): void
            {
                $this->logged = $outcome;
            }

            public function isFromCache(): bool
            {
                return $this->responseIsFromCache;
            }
        };
    }

    public function testCacheMissUsesGuzzle(): void
    {
        $this->mockGuzzleWithTapper();
        $expectedResponse = new Response(200, [], '{"awesome":"sauce"}');
        $this->tapper->addMatch('POST', '/.*?/', $expectedResponse);

        $requestClass = $this->mockRequestWithLog();

        $result = $requestClass->sync();

        self::assertSame($expectedResponse, $requestClass->logged);
        $this->expectTotalRequestCount(1);
        $this->assertTapperRequestLike('POST', '/.*?/', 1);

        // Result is the decoded body from the response
        self::assertSame(['awesome' => 'sauce'], $result);
        // Result was cached
        self::assertTrue($requestClass->canBeFulfilledByCache());
    }

    public function testCacheHitAfterFirstRequest(): void
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('POST', '/awesome/', '{"awesome":"sauce"}');

        $firstRequest = $this->mockRequestWithLog();
        // Not in cache, never been called
        self::assertFalse($firstRequest->canBeFulfilledByCache());
        self::assertFalse($firstRequest->isFromCache());
        self::assertSame(0, $tapper->getCountLike('POST', '/awesome/'));

        $firstRequest->sync();

        self::assertTrue($firstRequest->canBeFulfilledByCache());
        self::assertSame(1, $tapper->getCountLike('POST', '/awesome/'));

        // Regenerate the request
        $secondRequest = $this->mockRequestWithLog();
        self::assertTrue($secondRequest->canBeFulfilledByCache());
        // Both requests have same key
        self::assertSame($firstRequest->cacheKey(), $secondRequest->cacheKey());

        $secondRequest->sync();
        self::assertSame(1, $tapper->getCountLike('POST', '/awesome/'));

        // Result was in cache
        self::assertTrue($secondRequest->isFromCache());

        // Result matches cache
        self::assertTrue($secondRequest->canBeFulfilledByCache());
    }

    public function testDontCacheErrorStatus(): void
    {
        $firstRequest = $this->mockRequestWithPostProcessor();
        $this->mockGuzzleWithTapper()->addMatchBody('POST', '/awesome/', '{"bogus":"sauce"}', 500);

        try {
            $firstRequest->sync();
            self::fail('Should have thrown ServerException');
        } catch (ServerException) {
            self::assertFalse($firstRequest->canBeFulfilledByCache());
        }
    }

    // ONSHOP-7508, problem first observed with Dashboard
    public function testDontCacheUnparseableBody(): void
    {
        $firstRequest = $this->mockRequestWithPostProcessor();
        $this->mockGuzzleWithTapper()->addMatchBody('POST', '/awesome/', '{"bogus', 200);

        try {
            $firstRequest->sync();
            self::fail('Should have thrown UpstreamException (caused by parse error)');
        } catch (UpstreamException) {
            self::assertFalse($firstRequest->canBeFulfilledByCache());
        }
    }

    public function testDontCachePostprocessFailures(): void
    {
        $firstRequest = $this->mockRequestWithPostProcessor();
        $this->mockGuzzleWithTapper()->addMatchBody('POST', '/awesome/', '"bogus"', 200);

        try {
            $firstRequest->sync();
            self::fail('Should have thrown Exception (unexpected string)');
        } catch (\Exception) {
            self::assertFalse($firstRequest->canBeFulfilledByCache());
        }
    }

    public function testPurgeCache(): void
    {
        $this->mockGuzzleWithTapper()->addMatchBody('POST', '/awesome/', '{"awesome":"sauce"}');

        $firstRequest = new ConcreteRequest();
        // Not in cache, never been called
        self::assertFalse($firstRequest->canBeFulfilledByCache());
        self::assertTapperRequestLike('POST', '/awesome/', 0);

        // Warm up the cache with the first hit
        $firstRequest->sync();
        self::assertTrue($firstRequest->canBeFulfilledByCache());
        self::assertTapperRequestLike('POST', '/awesome/', 1);

        //Cache hit:
        $firstRequest->sync();
        self::assertTapperRequestLike('POST', '/awesome/', 1);

        // Purge cache
        $firstRequestPurged = $firstRequest->purgeCache();
        self::assertFalse($firstRequest->canBeFulfilledByCache());
        self::assertSame($firstRequest, $firstRequestPurged);

        // Cache miss
        $firstRequest->sync();
        self::assertTapperRequestLike('POST', '/awesome/', 2);
        self::assertTrue($firstRequest->canBeFulfilledByCache());
    }

    public function testCacheDisabledInChildClass(): void
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('POST', '/awesome/', '{"awesome":"sauce"}');

        $request = new ConcreteRequest();
        $request->setWriteCache(false);

        // Not in cache, never been called
        self::assertFalse($request->canBeFulfilledByCache());
        self::assertSame(0, $tapper->getCountLike('POST', '/awesome/'));

        $request->sync();

        self::assertSame(1, $tapper->getCountLike('POST', '/awesome/'));
        self::assertFalse($request->canBeFulfilledByCache());
    }

    public function testCacheMissRequestFails(): void
    {
        $this->mockGuzzleWithTapper();
        $expectedResponse = new Response(500, [], '{"trouble":"River City"}');
        $this->tapper->addMatch('POST', '/.*?/', $expectedResponse);

        $requestClass = $this->mockRequestWithLog();

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('Internal Server Error');
        $result = $requestClass->sync();

        // The failing request was logged
        self::assertSame($expectedResponse, $requestClass->logged);

        // Result was not cached
        self::assertFalse($requestClass->canBeFulfilledByCache());
    }

    public function mockRequestWithPostProcessor()
    {
        return new class extends ConcreteRequest {
            use ParseResponseJSONOrThrow;
            public function postProcess($parsed)
            {
                if (is_numeric($parsed)) {
                    return 2 * $parsed;
                } else {
                    return new RejectedPromise(new \Exception("Cannot process {$parsed}"));
                }
            }
        };
    }

    public function testPostProcessMutatesResult(): void
    {
        $request = $this->mockRequestWithPostProcessor();
        $this->mockGuzzleWithTapper();
        $this->tapper->addMatch('POST', '/.*?/', new Response(200, [], '42'));

        $result = $request->sync();
        // API returned JSON 42, postProcess doubles it
        self::assertSame(84, $result);
        // API result (not processed outcome!) was cached
        self::requestCacheBodyContains('42', $request);
    }

    public function testPostProcessCanReject(): void
    {
        $request = $this->mockRequestWithPostProcessor();
        $this->mockGuzzleWithTapper();
        $this->tapper->addMatch('POST', '/.*?/', new Response(200, [], '"forty-two"'));

        $this->expectExceptionMessage('Cannot process forty-two');
        $request->sync();
    }

    public function testCacheHitGetsPostProcessed(): void
    {
        $request = $this->mockRequestWithPostProcessor();

        $this->mockZeroGuzzleRequests();

        self::mockRequestCachedResponse($request, '42');

        $result = $request->sync();

        // Result matches cache plus post-process
        self::assertSame(84, $result);
    }

    protected function mockRequestWithLogAndPostprocess()
    {
        return new class extends ConcreteRequest {
            use EncodeRequestJSON, ParseResponseJSONOrThrow;
            protected bool $shouldLog = true;
            public $logged;
            public function log($outcome): void
            {
                $this->logged = $outcome;
            }
            public function postProcess($parsed)
            {
                return strtoupper($parsed);
            }
        };
    }

    public function testDisableCacheWrite(): void
    {
        $expectedResponse = new Response(200, [], '"forty-two"');
        $this->mockGuzzleWithTapper();
        $this->tapper->addMatch('POST', '/.*?/', $expectedResponse);

        $request = $this->mockRequestWithLogAndPostprocess()->setReadCache(false);

        $request->setWriteCache(false)->sync();
        self::assertFalse($request->canBeFulfilledByCache());

        $request->setWriteCache(true)->sync();
        self::assertTrue($request->canBeFulfilledByCache());
    }

    public function testDisableCacheRead(): void
    {
        $expectedResponse = new Response(200, [], '"forty-two"');
        $this->mockGuzzleWithTapper();
        $this->tapper->addMatch('POST', '/.*?/', $expectedResponse);

        $request = $this->mockRequestWithLogAndPostprocess()->setWriteCache(false);
        self::mockRequestCachedResponse($request, '"sixty-nine"');

        $result = $request->setReadCache(false)->sync();
        self::assertSame('FORTY-TWO', $result, 'should match expectedResponse after post-processing');

        $result = $request->setReadCache(true)->sync();
        self::assertSame('SIXTY-NINE', $result, 'should match value we inserted into cache after post-processing');
    }

    public function testMissingGetLogFolderImplementation(): void
    {
        Storage::fake('api-logs');
        $request = new class extends ConcreteRequest {
            protected bool $shouldLog = true;
        };

        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('POST', '/awesome/', 'true');

        $this->expectException(ToDoException::class);
        $request->sync();
    }

    public function testLogsWhenEnabledWhereYouTellIt(): void
    {
        Carbon::setTestNow('2018-01-01 00:00:00');
        Storage::fake('api-logs');
        $request = new class extends ConcreteRequest {
            protected bool $shouldReadCache = false;
            protected bool $shouldWriteCache = false;
            public function getLogFolder(): string
            {
                return 'one/two';
            }
        };

        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('POST', '/awesome/', 'true');

        $request->setLog(false);
        $request->sync();
        self::assertSame([], Storage::disk('api-logs')->allFiles());

        $request->setLog(true);
        $request->sync();
        // Note file name is LogFile::NAME_FORMAT
        Storage::disk('api-logs')->assertExists('one/two/2018-01-01T00:00:00.000000+00:00');
    }

    public function testGetLastLogContents(): void
    {
        Storage::fake('api-logs');
        $request = new class extends ConcreteRequest {
            protected bool $shouldLog = true;
            public function getLogFolder(): string
            {
                return 'one/two';
            }
        };

        try {
            $request->getLastLogContents();
            self::fail('Should have thrown');
        } catch (\DomainException $exception) {
            self::assertSame('No log files have been saved by this instance.', $exception->getMessage());
        }

        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('POST', '/awesome/', 'true');

        $firstLogTime = '2018-01-01T00:00:00.000000+00:00';
        Carbon::setTestNow($firstLogTime);
        $request->sync();
        Storage::disk('api-logs')->assertExists("one/two/{$firstLogTime}");
        self::assertSame($request->getLastLogContents(), Storage::disk('api-logs')->get("one/two/{$firstLogTime}"));

        // Make a second log, see that it's now returned

        $tapper->addMatchBody('POST', '/awesome/', 'false');
        $secondLogTime = '2018-01-01T00:00:00.000000+00:00';
        Carbon::setTestNow($secondLogTime);
        $request->sync();
        Storage::disk('api-logs')->assertExists("one/two/{$secondLogTime}");
        self::assertSame($request->getLastLogContents(), Storage::disk('api-logs')->get("one/two/{$secondLogTime}"));
    }

    public function testPrerequisiteCanChangeRequestBody(): void
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('POST', '/awesome/', 'true');

        $request = new class extends ConcreteRequest {
            use EncodeRequestJSON;
            public function __construct()
            {
                $this->body = ['prereq_finished' => false];
            }

            public function prerequisites(): PromiseInterface
            {
                $promise = new Promise(function () use (&$promise) {
                    $this->body['prereq_finished'] = true;
                    $promise->resolve('waited');
                });
                return $promise;
            }
        };

        self::assertSame('{"prereq_finished":false}', $request->encodeBody());
        $keyAtInstantiation = $request->cacheKey();

        $request->sync();

        self::assertSame('{"prereq_finished":true}', $request->encodeBody());

        self::assertNotSame($keyAtInstantiation, $request->cacheKey());
    }

    public function testPrerequisiteCanHaltRequest(): void
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('POST', '/awesome/', 'true');

        $request = new class extends ConcreteRequest {
            public function prerequisites(): PromiseInterface
            {
                return new RejectedPromise('kaboom');
            }
        };

        try {
            $request->sync();
            self::assertTrue(false, "Shouldn't get here, sync should throw exception");
        } catch (\Throwable $e) {
            self::assertInstanceOf(RejectionException::class, $e);
        }

        self::assertSame(0, $tapper->getCountAll(), 'Failing prerequisite means no requests are sent');
    }

    protected function mockRequestWithOtherwise()
    {
        return new class extends ConcreteRequest {
            use ParseResponseJSON;
            public function otherwise($reason)
            {
                if ($reason instanceof ClientException) {
                    return 'otherwise transformed';
                }
                return new RejectedPromise($reason); // Continue to reject
            }
        };
    }

    public function testRequestWithOtherwiseHandlerPassesThroughSuccess()
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatch('POST', '/url/', new Response(200, [], '"Everything is awesome"'));

        $successRequest = $this->mockRequestWithOtherwise();
        self::assertSame('Everything is awesome', $successRequest->sync());
    }

    public function testRequestWithOtherwiseHandlerChangesDesiredExceptions()
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatch(
            'POST',
            '/url/',
            new Response(400, [], '4xx status is turned into ClientException by Guzzle'),
        );

        $successRequest = $this->mockRequestWithOtherwise();
        self::assertSame('otherwise transformed', $successRequest->sync());
    }

    public function testRequestWithOtherwiseHandlerPassesExceptionAtItsDiscretion()
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatch(
            'POST',
            '/url/',
            new Response(500, [], '5xx status is turned into ServerException by Guzzle'),
        );

        $request = $this->mockRequestWithOtherwise();
        $this->expectException(ServerException::class);
        $request->sync();
    }

    public function testRequestEncodingNone()
    {
        $request = new class extends ConcreteRequest {
            public function __construct()
            {
                $this->body = 'Raw text';
            }
        };

        self::assertNull($request->encodeBody());
        $guzzleRequest = $request->toGuzzle();
        self::assertEmpty($guzzleRequest->getHeader('Content-Type'));
        self::assertSame(0, $guzzleRequest->getBody()->getSize());
    }

    /**
     * The easiest way to change cache timeout is just to override the expires prop,
     * test that it changes the behavior of cacheExpiresTime()  without overriding that method
     * @param int $minutes
     * @param string $expected
     * @dataProvider provideUseExpiresPropToSetCacheExpiresTime
     */
    public function testUseExpiresPropToSetCacheExpiresTime(int $minutes, string $expected): void
    {
        LogFake::bind();
        $request = new class extends ConcreteRequest {
            public int $expires;
            public function setExpires(int $minutes)
            {
                $this->expires = $minutes;
            }
        };
        Carbon::setTestNow('2020-02-02T00:00:00+00:00');
        $request->setExpires($minutes);
        self::assertSame($expected, $request->cacheExpiresTime()->format('c'));
        Log::assertLogged(fn (LogEntry $log) =>
            $log->level === 'notice'
            && $log->message === 'Deprecation notice: Anonymous Descendent of Concrete Request has an `expires` property defined for expiration. Please update it to override the `cacheExpiresTime` method instead.'
        );
    }

    public function provideUseExpiresPropToSetCacheExpiresTime(): array
    {
        return [
            [1, '2020-02-02T00:01:00+00:00'],
            [60, '2020-02-02T01:00:00+00:00'],
            [1440, '2020-02-03T00:00:00+00:00'],
        ];
    }

    public function testResponseIsFromCachePreventsWritesToCache(): void
    {
        $this->mockZeroGuzzleRequests();
        $request = $this->mockRequestWithLog();

        Cache::shouldReceive('tags')
            ->with([])
            ->andReturnSelf();
        //  If the result is read from cache
        Cache::shouldReceive('get')
            ->once()
            ->with($request->cacheKey())
            ->andReturn([200, [], '42']);
        // It is NOT written back to cache
        Cache::shouldReceive('put')->never();

        self::assertSame(42, $request->sync());
    }
}