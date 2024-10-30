<?php
/**
 * Unit test the LogFile class and its interaction with AbstractRequest
 */
declare(strict_types=1);

namespace Tests\Feature;

use Carbon\Carbon;
use Carsdotcom\ApiRequest\LogFile;
use Carsdotcom\ApiRequest\Testing\MocksGuzzleInstance;
use Carsdotcom\ApiRequest\Testing\RequestClassAssertions;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Tests\BaseTestCase;
use Tests\MockClasses\ConcreteRequest;

class LogFileTest extends BaseTestCase
{
    use MocksGuzzleInstance;
    use RequestClassAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('api-logs');
    }

    public function testLogsNoRequestHeadersByDefault(): void
    {
        $request = new Request('GET', 'https://example.com', ['Authentication' => ['Bearer SuperSecret'], 'x-ciq-request-id' => ['0098b8f2-fd78-4e13-afb8-ce655b29bc34']]);
        $interesting = LogFile::interestingRequestHeaders($request);
        self::assertSame([], $interesting);
        $string = LogFile::stringify_body($request);
        self::assertStringNotContainsString('SuperSecret', $string);
        self::assertStringNotContainsString('0098b8f2', LogFile::stringify_body($request));
    }

    public function testLogsRequestedRequestHeaders(): void
    {
        $logFile = new class extends LogFile {
            public static function interestingRequestHeaders(Request $request): array
            {
                return Arr::only($request->getHeaders(), ['x-ciq-request-id']);
            }
        };
        $request = new Request('GET', 'https://example.com', ['Authentication' => ['Bearer SuperSecret'], 'x-ciq-request-id' => ['0098b8f2-fd78-4e13-afb8-ce655b29bc34']]);
        $interesting = $logFile::interestingRequestHeaders($request);
        self::assertSame(['x-ciq-request-id' => ['0098b8f2-fd78-4e13-afb8-ce655b29bc34']], $interesting);
        $string = $logFile::stringify_body($request);
        self::assertStringNotContainsString('SuperSecret', $string);
        self::assertStringContainsString("x-ciq-request-id: 0098b8f2-fd78-4e13-afb8-ce655b29bc34", $string);
    }

    public function testRequestHeadersInRequestClass(): void
    {
        $this->mockGuzzleWithTapper();
        $this->tapper->addMatchBody('GET', '/.*?/', '{"awesome":"sauce"}');

        $request = new class extends ConcreteRequest{
            protected bool $shouldLog = true;

            public function getLogFolder(): string
            {
                return 'loggy';
            }

            public function toGuzzle(): Request
            {
                return new Request('GET', 'https://example.com', ['Authentication' => ['Bearer SuperSecret'], 'x-ciq-request-id' => ['0098b8f2-fd78-4e13-afb8-ce655b29bc34']]);
            }

            public function getLogFileHelper(): LogFile
            {
                return new class extends LogFile {
                    public static function interestingRequestHeaders(Request $request): array
                    {
                        return Arr::only($request->getHeaders(), ['x-ciq-request-id']);
                    }
                };
            }
        };
        $firstLogTime = '2018-01-01T00:00:00.000000+00:00';
        Carbon::setTestNow($firstLogTime);
        $request->sync();

        self::assertStringNotContainsString('SuperSecret', $request->getLastLogContents());
        self::assertStringContainsString("x-ciq-request-id: 0098b8f2-fd78-4e13-afb8-ce655b29bc34", $request->getLastLogContents());
    }


    public function testLogsNoResponseHeadersByDefault(): void
    {
        $response = new Response(200, headers: ['Authentication' => ['Bearer SuperSecret'], 'x-ciq-request-id' => ['0098b8f2-fd78-4e13-afb8-ce655b29bc34']]);
        self::assertSame([], LogFile::interestingResponseHeaders($response));
        self::assertStringNotContainsString('SuperSecret', LogFile::stringify_body($response));
        self::assertStringNotContainsString('0098b8f2', LogFile::stringify_body($response));
    }


    public function testLogsRequestedResponseHeaders(): void
    {
        $logFile = new class extends LogFile {
            public static function interestingResponseHeaders(Response $response): array
            {
                return Arr::only($response->getHeaders(), ['x-ciq-request-id']);
            }
        };
        $response = new Response(200, headers: ['Authentication' => ['Bearer SuperSecret'], 'x-ciq-request-id' => ['0098b8f2-fd78-4e13-afb8-ce655b29bc34']]);
        $interesting = $logFile::interestingResponseHeaders($response);
        self::assertSame(['x-ciq-request-id' => ['0098b8f2-fd78-4e13-afb8-ce655b29bc34']], $interesting);
        $string = $logFile::stringify_body($response);
        self::assertStringNotContainsString('SuperSecret', $string);
        self::assertStringContainsString("x-ciq-request-id: 0098b8f2-fd78-4e13-afb8-ce655b29bc34", $string);
    }

    public function testResponseHeadersInRequestClass(): void
    {
        $this->mockGuzzleWithTapper();
        $this->tapper->addMatch(
            'POST',
            '/.*?/',
            new Response(200,['Authentication' => ['Bearer SuperSecret'], 'x-ciq-request-id' => ['0098b8f2-fd78-4e13-afb8-ce655b29bc34']], '{"awesome":"sauce"}')
        );

        $request = new class extends ConcreteRequest{
            protected bool $shouldLog = true;

            public function getLogFolder(): string
            {
                return 'loggy';
            }

            public function getLogFileHelper(): LogFile
            {
                return new class extends LogFile {
                    public static function interestingResponseHeaders(Response $response): array
                    {
                        return Arr::only($response->getHeaders(), ['x-ciq-request-id']);
                    }
                };
            }
        };
        $request->sync();

        self::assertStringNotContainsString('SuperSecret', $request->getLastLogContents());
        self::assertStringContainsString("x-ciq-request-id: 0098b8f2-fd78-4e13-afb8-ce655b29bc34", $request->getLastLogContents());
    }
}
