<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Carsdotcom\ApiRequest\AbstractRequest;
use Carsdotcom\ApiRequest\Exceptions\ToDoException;
use Carsdotcom\ApiRequest\Exceptions\UpstreamException;
use Carsdotcom\ApiRequest\Testing\GuzzleTapper;
use Carsdotcom\ApiRequest\Testing\MocksGuzzleInstance;
use Carsdotcom\ApiRequest\Testing\RequestClassAssertions;
use Carsdotcom\ApiRequest\Traits\EncodeRequestJSON;
use Carsdotcom\ApiRequest\Traits\ParseResponseJSON;
use Carsdotcom\ApiRequest\Traits\ParseResponseJSONOrThrow;
use Carsdotcom\ApiRequest\Traits\ParseResponseJSONSchemaOrThrow;
use Carsdotcom\JsonSchemaValidation\Exceptions\JsonSchemaValidationException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\RejectionException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\BaseTestCase;
use Tests\MockClasses\ConcreteRequest;
use TiMacDonald\Log\LogEntry;
use TiMacDonald\Log\LogFake;

class AbstractRequestTest extends BaseTestCase
{
    use MocksGuzzleInstance;
    use RequestClassAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('api-logs');
    }

    protected function mockRequestWithLog()
    {
        return new class extends ConcreteRequest {
            use ParseResponseJSON;

            protected bool $shouldLog = true;

            public function getLogFolder(): string
            {
                return 'one/two';
            }
        };
    }

    public function testCacheMissUsesGuzzle(): void
    {
        $this->mockGuzzleWithTapper();
        $this->tapper->addMatchBody('POST', '/.*?/', '{"awesome":"sauce"}');

        $requestClass = $this->mockRequestWithLog();

        $result = $requestClass->sync();

        self::assertStringContainsString('"sauce"', $requestClass->getLastLogContents());
        $this->expectTotalRequestCount(1);
        $this->assertTapperRequestLike('POST', '/.*?/', 1);

        // Result is the decoded body from the response
        self::assertSame(['awesome' => 'sauce'], $result);
        // Result was cached
        self::assertTrue($requestClass->canBeFulfilledByCache());
        self::assertRequestCacheBodyContains('sauce', $requestClass);
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

    public function testCacheHitHasAccessToOriginalLog(): void
    {
        Storage::fake('api-logs');
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('POST', '/awesome/', '{"awesome":"sauce"}');

        $firstLogTime = '2018-01-01T00:00:00.000000+00:00';
        Carbon::setTestNow($firstLogTime);
        $firstRequest = $this->mockRequestWithLog();
        $firstRequest->sync();
        self::assertTrue($firstRequest->canBeFulfilledByCache());
        self::assertSame($firstRequest->getLastLogFile(), "one/two/{$firstLogTime}");
        self::assertSame(1, $tapper->getCountLike('POST', '/awesome/'));

        // Regenerate the request
        $secondLogTime = '2018-01-01T00:02:02.000000+00:00';
        Carbon::setTestNow($secondLogTime);
        $secondRequest = $this->mockRequestWithLog();
        self::assertTrue($secondRequest->canBeFulfilledByCache());
        // Both requests have same key
        self::assertSame($firstRequest->cacheKey(), $secondRequest->cacheKey());

        $secondRequest->sync();
        self::assertSame(1, $tapper->getCountLike('POST', '/awesome/'));
        // Result was in cache
        self::assertTrue($secondRequest->isFromCache());
        // Second request can retrieve log from original time with original contents
        self::assertSame($secondRequest->getLastLogFile(), "one/two/{$firstLogTime}");
        self::assertStringContainsString('"sauce"', $secondRequest->getLastLogContents());
        // Second request did not log to disk, only the first request
        self::assertSame(["one/two/{$firstLogTime}"], Storage::disk('api-logs')->files('one/two'));
    }

    public function testCachedLogFileContainsFolder(): void
    {
        Storage::fake('api-logs');
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('POST', '/awesome/', '{"awesome":"sauce"}');

        $firstRequestTime = '2018-01-01T00:00:00.000000+00:00';
        Carbon::setTestNow($firstRequestTime);
        $firstRequest = $this->mockRequestWithLogMutatingFolder('first');
        self::assertSame('root/first', $firstRequest->getLogFolder());
        $firstRequest->sync();
        self::assertTrue($firstRequest->canBeFulfilledByCache());
        self::assertSame("root/first/{$firstRequestTime}", $firstRequest->getLastLogFile());

        $secondRequestTime = '2018-01-01T00:00:00.000000+00:00';
        Carbon::setTestNow($secondRequestTime);
        $secondRequest = $this->mockRequestWithLogMutatingFolder('second');
        self::assertSame('root/second', $secondRequest->getLogFolder());
        $secondRequest->sync();
        self::assertTrue(getProperty($secondRequest, 'responseIsFromCache'));
        self::assertSame("root/first/{$firstRequestTime}", $secondRequest->getLastLogFile(), "The log should be from the first request's folder and time");
    }

    /**
     * In this request class, the log folder uses a value that is not present in the cache key
     */
    protected function mockRequestWithLogMutatingFolder(string $subfolder): ConcreteRequest
    {
        return new class($subfolder) extends ConcreteRequest {
            use ParseResponseJSON;
            public function __construct(public string $subfolder)
            {
            }

            protected bool $shouldLog = true;

            public function getLogFolder(): string
            {
                return 'root/' . $this->subfolder;
            }
        };
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

    public function testCacheDisabledWrite(): void
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
        // Still not in cache
        self::assertFalse($request->canBeFulfilledByCache());

        // But if something *else* caches it, we'll read from it
        self::mockRequestCachedResponse($request, '{"awesome":"possum"}');
        $response = $request->sync();
        self::assertSame('{"awesome":"possum"}', $response, "Should receive cache value, not Tapper value");
        self::assertSame(1, $tapper->getCountLike('POST', '/awesome/'));
    }

    public function testCacheDisabledRead(): void
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('POST', '/awesome/', '{"awesome":"sauce"}');

        $request = new ConcreteRequest();
        $request->setReadCache(false);

        // Not in cache, never been called
        self::assertFalse($request->canBeFulfilledByCache());
        self::assertSame(0, $tapper->getCountLike('POST', '/awesome/'));

        $response = $request->sync();
        self::assertSame('{"awesome":"sauce"}', $response, "Should receive Tapper value, ignoring cache");
        self::assertSame(1, $tapper->getCountLike('POST', '/awesome/'));
        self::assertTrue($request->canBeFulfilledByCache());

        // But if the value in cache is manipulated
        self::mockRequestCachedResponse($request, '{"awesome":"possum"}');
        $response = $request->sync();
        self::assertSame('{"awesome":"sauce"}', $response, "Should receive Tapper value, ignoring cache");
        // Makes a second request even though a hit was in cache
        self::assertSame(2, $tapper->getCountLike('POST', '/awesome/'));
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
        self::assertRequestCacheBodyContains('42', $request);
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
        $this->expectExceptionMessageMatches('/To enable request logging, .+ will have to implement the method getLogFolder/');
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
        try {
            $request->getLastLogFile();
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
        self::assertSame($request->getLastLogFile(), "one/two/{$firstLogTime}");
        self::assertSame($request->getLastLogContents(), Storage::disk('api-logs')->get("one/two/{$firstLogTime}"));

        // Make a second log, see that it's now returned

        $tapper->addMatchBody('POST', '/awesome/', 'false');
        $secondLogTime = '2018-01-01T00:00:00.000000+00:00';
        Carbon::setTestNow($secondLogTime);
        $request->sync();
        Storage::disk('api-logs')->assertExists("one/two/{$secondLogTime}");
        self::assertSame($request->getLastLogFile(), "one/two/{$secondLogTime}");
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
        Log::assertLogged(
            fn(LogEntry $log) => $log->level === 'notice' &&
                $log->message ===
                    'Deprecation notice: Anonymous Descendent of Concrete Request has an `expires` property defined for expiration. Please update it to override the `cacheExpiresTime` method instead.',
        );
    }

    public static function provideUseExpiresPropToSetCacheExpiresTime(): array
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
            ->andReturn(['logs' => [], 'response' => [200, [], '42']]);
        // It is NOT written back to cache
        Cache::shouldReceive('put')->never();

        self::assertSame(42, $request->sync());
    }

    // https://github.com/carsdotcom/php-request-class/issues/35
    // A response that looks fine (e.g. HTTP 200) but fails parsing should NOT be logged as a plain success.
    public function testParseFailureIsLogged(): void
    {
        Storage::fake('api-logs');
        $request = new class extends ConcreteRequest {
            use ParseResponseJSONOrThrow;
            protected bool $shouldLog = true;
            public function getLogFolder(): string
            {
                return 'parse-failures';
            }
        };
        $this->mockGuzzleWithTapper()->addMatchBody('POST', '/awesome/', '{"bogus', 200);

        try {
            $request->sync();
            self::fail('Should have thrown UpstreamException');
        } catch (UpstreamException) {
            $contents = $request->getLastLogContents();
            // The actual (apparently fine) response is still visible in the log...
            self::assertStringContainsString('Response Status Code 200', $contents);
            self::assertStringContainsString('{"bogus', $contents);
            // ...alongside the exception that explains why it wasn't actually usable
            self::assertStringContainsString('response was unreadable', $contents);
        }
    }

    // https://github.com/carsdotcom/php-request-class/issues/35
    // This is the exact scenario from the ticket: a 200 response that reads as valid JSON but doesn't
    // match RESPONSE_SCHEMA. The log must show it failed, and must include the schema errors -- not just
    // log the response as if it were a plain, unremarkable success.
    public function testSchemaValidationFailureIsLoggedWithExtendedExceptionData(): void
    {
        Storage::fake('api-logs');
        // carsdotcom/laravel-json-schema doesn't merge its own config defaults (only publishes them),
        // so tests that never ran `vendor:publish` need to set this themselves.
        config([
            'json-schema.base_url' => 'file://localhost',
            'json-schema.local_base_prefix' => sys_get_temp_dir(),
            'json-schema.local_base_prefix_tests' => sys_get_temp_dir(),
        ]);
        $request = new class extends ConcreteRequest {
            use ParseResponseJSONSchemaOrThrow;
            const RESPONSE_SCHEMA = '{"type":"object","required":["zipRegion"],"properties":{"zipRegion":{"type":"string"}}}';
            protected bool $shouldLog = true;
            public function getLogFolder(): string
            {
                return 'schema-failures';
            }
        };
        // zipRegion is present but null, not a string -- fails RESPONSE_SCHEMA
        $this->mockGuzzleWithTapper()->addMatchBody(
            'POST',
            '/awesome/',
            '{"zipRegion":null,"filters":null,"offers":null}',
            200,
        );

        try {
            $request->sync();
            self::fail('Should have thrown JsonSchemaValidationException');
        } catch (JsonSchemaValidationException) {
            // Full-content match (transfer time is the only non-deterministic part, hence %s):
            // the apparently-fine response is still visible, but so is the exception, including its
            // structured errors (HasExtendedExceptionData::getExtendedData / ->errors()).
            self::assertStringMatchesFormat(
                <<<LOG
                POST https://awesome-api.com/url

                Response Status Code 200

                {
                    "zipRegion": null,
                    "filters": null,
                    "offers": null
                }

                Exception thrown: Carsdotcom\JsonSchemaValidation\Exceptions\JsonSchemaValidationException
                Unexpected problem with Anonymous Descendent of Concrete Request call: Response does not match expected schema
                {
                    "errors": {
                        "zipRegion": [
                            "The data (null) must match the type: string"
                        ]
                    }
                }


                Transfer time: %ss

                LOG,
                $request->getLastLogContents(),
            );
        }
    }

    // Before this change, a cache-miss request always logged once, right after the Guzzle transfer
    // completed -- before postProcess() ever ran. So a postProcess() failure didn't go unlogged, it
    // logged as a false success: the entry was already written before the failure happened.
    // Now that single log entry is deferred until postProcess() has had its chance to run, so it
    // reflects the real, final outcome -- consistent with it also being excluded from the cache
    // (see testDontCachePostprocessFailures).
    public function testPostProcessFailureIsLogged(): void
    {
        Storage::fake('api-logs');
        $request = new class extends ConcreteRequest {
            use ParseResponseJSONOrThrow;
            protected bool $shouldLog = true;
            public function getLogFolder(): string
            {
                return 'postprocess-failures';
            }
            public function postProcess($parsed)
            {
                return new RejectedPromise(new \Exception("Cannot process {$parsed}"));
            }
        };
        $this->mockGuzzleWithTapper()->addMatch('POST', '/awesome/', new Response(200, [], '"bogus"'));

        try {
            $request->sync();
            self::fail('Should have thrown Exception');
        } catch (\Exception) {
            self::assertStringContainsString('Cannot process bogus', $request->getLastLogContents());
        }
    }

    /**
     * Cache hits are not logged by default (log() returns early when responseIsFromCache).
     * Children can override log() to handle that case explicitly -- e.g. to write a short log
     * that points back at the original request's log -- by calling writeLog() directly,
     * which bypasses that guard.
     */
    public function testLogCanBeOverriddenToHandleCacheHits(): void
    {
        Storage::fake('api-logs');
        $this->mockGuzzleWithTapper()->addMatchBody('POST', '/awesome/', '{"awesome":"sauce"}');

        $makeRequest = fn() => new class extends ConcreteRequest {
            use ParseResponseJSON;
            protected bool $shouldLog = true;
            public array $logCalls = [];
            public function getLogFolder(): string
            {
                return 'one/two';
            }
            public function log($outcome): void
            {
                $this->logCalls[] = $this->responseIsFromCache ? 'cache-hit' : 'fresh';
                if ($this->responseIsFromCache && $this->shouldLog) {
                    $this->writeLog('Cache hit, previous log was ' . $this->getLastLogFile());
                }
                parent::log($outcome); // no-op on a cache hit, parent::log() already guards on that
            }
        };

        Carbon::setTestNow('2018-01-01T00:00:00.000000+00:00');
        $first = $makeRequest();
        $first->sync();
        self::assertSame(['fresh'], $first->logCalls);
        self::assertCount(1, Storage::disk('api-logs')->allFiles());
        $firstLogFile = $first->getLastLogFile();

        // Second instance, same cache key: a cache hit. Freeze time to a different instant than the
        // first request, so the two log files get distinct, differentiable names.
        Carbon::setTestNow('2018-01-01T00:00:01.000000+00:00');
        $second = $makeRequest();
        $second->sync();
        self::assertSame(['cache-hit'], $second->logCalls);

        // The override still logged -- it just wrote a *second*, distinct file rather than reusing
        // (or skipping) the first, and that file points back at the original.
        self::assertCount(2, Storage::disk('api-logs')->allFiles());
        $secondLogFile = $second->getLastLogFile();
        self::assertNotSame($firstLogFile, $secondLogFile);
        self::assertNotSame(
            Storage::disk('api-logs')->get($firstLogFile),
            Storage::disk('api-logs')->get($secondLogFile),
            'Cache-hit log content should differ from the original log it references',
        );
        self::assertStringContainsString(
            "Cache hit, previous log was {$firstLogFile}",
            $second->getLastLogContents(),
        );
    }

    public function testRequestLogHasTransferTime(): void
    {
        Storage::fake('api-logs');
        $request = new class extends ConcreteRequest {
            use ParseResponseJSON;
            protected bool $shouldLog = true;
            public function getLogFolder(): string
            {
                return 'one/two';
            }
        };
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('POST', '/awesome/', 'true');
        $request->sync();

        self::assertMatchesRegularExpression('/Transfer time: \d+\.*\d*s/', $request->getLastLogContents());
    }

    /**
     * @dataProvider provideSetBodyIfNotEmpty
     */
    public function testSetBodyIfNotEmpty($value, bool $shouldExist): void
    {
        $request = new ConcreteRequest();
        $request->setBodyIfNotEmpty('key', $value);
        if ($shouldExist) {
            self::assertArrayHasKey('key', $request->getBody());
            self::assertSame($value, $request->getBody()['key']);
        } else {
            self::assertArrayNotHasKey('key', $request->getBody());
        }
    }

    public static function provideSetBodyIfNotEmpty(): array
    {
        return [
            ['', false],
            [null, false],
            [false, false],
            [0, false], // This one stresses me out a little, but it's not relevant today
            ['Jeremy', true],
            ['68144', true],
        ];
    }

    public function testTimeout(): void
    {
        $request = new ConcreteRequest();
        $defaultOptions = getProperty($request, 'guzzleOptions');
        self::assertSame(30, $defaultOptions['timeout']);

        $request->setTimeout(CarbonInterval::weeks(2));
        // 1209600 seconds in two weeks (60 * 60 * 24 * 7 * 2)
        self::assertSame(1209600, (int) getProperty($request, 'guzzleOptions')['timeout']);
    }

    public function testLogsTimeout(): void
    {
        Storage::fake('api-logs');
        Carbon::setTestNow('2023-02-03 00:00:00');
        $request = new class extends ConcreteRequest {
            protected bool $shouldLog = true;
            public function getLogFolder(): string
            {
                return 'timeout';
            }
        };
        $this->mockGuzzleWithTapper()->addMatch('POST', '/awesome/', function (Request $request) {
            // Guzzle MockHandler doesn't actually observe timeouts, so we're faking behavior
            throw new ConnectException(
                'cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://awesome-api.com/url',
                $request,
            );
        });
        try {
            $request->sync();
            self::fail('Should have thrown exception');
        } catch (ConnectException $e) {
            self::assertSame(
                <<<LOG
                POST https://awesome-api.com/url

                Exception thrown: GuzzleHttp\Exception\ConnectException
                cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://awesome-api.com/url


                null

                LOG
                ,
                $request->getLastLogContents(),
            );
        }
    }

    /**
     * In this example, $requestWithCustomTapper always uses a mocked response, even when the Guzzle Client in the
     * App's dependency store does not.  This can be useful when you're building a Request class around documentation,
     * and the endpoint you *will* call is not yet ready to be called.
     * The getGuzzleClient method knows that it's returning mock data, every other part of your application acts
     * as if the request is completely legitimate -- even logging and caching!
     */
    public function testRequestCanReturnStaticResponseWithoutAffectingDependencyStore(): void
    {
        $requestWithCustomTapper = new class extends ConcreteRequest {
            // $requestWithCustomTapper and $requestWithSystemGuzzle are identical, so make sure cache doesn't confuse this test outcome
            protected bool $shouldWriteCache = false;
            protected function getGuzzleClient(): Client
            {
                $tapper = new GuzzleTapper();
                $tapper->addMatchBody('POST', '/awesome/', 'Static data as documented', 200);
                return $tapper->makeMockedGuzzleClient();
            }
        };
        $this->mockGuzzleWithTapper()->addMatchBody('POST', '/awesome/', 'This method is not implemented', 500);

        $resultFromRequestTapper = $requestWithCustomTapper->sync();
        self::assertSame('Static data as documented', $resultFromRequestTapper);

        $requestWithSystemGuzzle = new ConcreteRequest();
        try {
            $requestWithSystemGuzzle->sync();
            self::fail("Should have thrown ServerException");
        } catch (ServerException $exception) {
            self::assertSame(500, $exception->getCode());
            self::assertSame("Server error: `POST https://awesome-api.com/url` resulted in a `500 Internal Server Error` response:\nThis method is not implemented\n", $exception->getMessage());
        }
    }

    /**
     * This test should start failing if you change the format of writeResponseToCache
     * This is a reminder to increment CACHE_KEY_SEED before you fix this test,
     */
    public function testCacheStructureCanary(): void
    {
        $firstLogTime = '2018-01-01T00:00:00.000000+00:00';
        Carbon::setTestNow($firstLogTime);

        $this->mockGuzzleWithTapper()->addMatchBody('POST', '/awesome/', '{"awesome":"sauce"}');
        $request = $this->mockRequestWithLog();
        $request->setWriteCache(true)->sync();
        self::assertTrue($request->canBeFulfilledByCache());

        $cached = Cache::tags([])->get($request->cacheKey());
        self::assertIsArray($cached);
        self::assertSame(['logs', 'response'], array_keys($cached)); // No new keys, no removed keys
        self::assertSame([
            0 => 200,
            1 => [],
            2 => '{"awesome":"sauce"}',
            3 => '1.1',
            4 => 'OK',
        ], $cached['response']);
        self::assertSame([ "one/two/2018-01-01T00:00:00.000000+00:00" ], $cached['logs']);

        // If you modified this test in any way, you need to change the CACHE_KEY_SEED
        self::assertSame('v2024.8.6', AbstractRequest::CACHE_KEY_SEED);
    }

    /**
     * https://github.com/carsdotcom/php-request-class/issues/19
     * Shim for cached results <=1.4.0 that have a filename but no folder
     * This won't work as well as modern folder-inclusive storage,
     * but it's consistent with the old behavior, so better than nothing
     */
    public function testCacheLogFilesNotFolders(): void
    {
        $firstLogTime = '2018-01-01T00:00:00.000000+00:00';
        Carbon::setTestNow($firstLogTime);

        $this->mockGuzzleWithTapper()->addMatchBody('POST', '/awesome/', '{"awesome":"sauce"}');
        $request = $this->mockRequestWithLog();
        Cache::tags([])->put($request->cacheKey(), [
            'logs' => [ "2018-01-01T00:00:00.000000+00:00" ],
            'response' => [
                0 => 200,
                1 => [],
                2 => '{"awesome":"sauce"}',
                3 => '1.1',
                4 => 'OK',
            ]
        ]);

        $request->setReadCache(true)->sync();
        self::assertTrue($request->isFromCache());

        self::assertSame(
            [ "one/two/2018-01-01T00:00:00.000000+00:00" ],
            getProperty($request, 'sentLogs'),
            "Shim is compartmentalized to cache rehydration, private prop is set in the standard way"
        );
        self::assertSame("one/two/2018-01-01T00:00:00.000000+00:00" , $request->getLastLogFile());
    }
}
