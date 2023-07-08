<?php
/**
 * Trait to include to mock the guzzle instance into the app
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Testing;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;

trait MocksGuzzleInstance
{
    /** @var GuzzleTapper|null */
    protected $tapper;

    /**
     * Replace Laravel's dependency-injected Guzzle
     * with a Client that responds with GuzzleTappers matching process
     * Note, you can keep adding new responses to GuzzleTapper any time before the matched request is made
     * @return GuzzleTapper
     */
    protected function mockGuzzleWithTapper(): GuzzleTapper
    {
        if (!$this->tapper) {
            $this->tapper = new GuzzleTapper();
        }

        $handler = new MockHandler($this->tapper->getResponses());
        $this->mockGuzzleAppInstanceWithHandler($handler);

        return $this->tapper;
    }

    /**
     * Mocks the guzzle client and injects it into the app
     *
     * @return CustomMockInterface|Client
     */
    protected function mockGuzzleAppInstance()
    {
        $mockguzzle = mock(Client::class);
        $this->app->instance('guzzle', $mockguzzle);
        return $mockguzzle;
    }

    /**
     * Create a real guzzle client that users a MockHandler that throws an exception if it is called.
     */
    protected function mockZeroGuzzleRequests(): void
    {
        $exceptionResponse = new RequestException('Guzzle should not have been called.', new Request('GET', 'test'));
        $this->mockGuzzleWithTapper()
            ->addMatch('POST', '/.*?/', $exceptionResponse)
            ->addMatch('GET', '/.*?/', $exceptionResponse)
            ->addMatch('PUT', '/.*?/', $exceptionResponse)
            ->addMatch('PATCH', '/.*?/', $exceptionResponse)
            ->addMatch('DELETE', '/.*?/', $exceptionResponse)
            ->addMatch('OPTIONS', '/.*?/', $exceptionResponse);
        // The tapper defined above has been dependency injected, but the reference in $this
        // is broken, meaning a later call to $this->mockGuzzleWithTapper() will overwrite
        // the one in dependency injection
        $this->tapper = null;
    }

    /**
     * Request has the header, and the value is or contains the required value
     * @param Request $request
     * @param string $headerName
     * @param string $headerValue
     * @param string|null $message
     */
    protected function assertSameRequestHeader(
        Request $request,
        string $headerName,
        string $headerValue,
        string $message = '',
    ): void {
        $header = $request->getHeader($headerName);
        self::assertNotSame([], $header, "Header {$headerName} should not be missing from the request");
        if (is_array($header)) {
            self::assertContains($headerValue, $header, $message);
        } else {
            self::assertSame($headerValue, $header, $message);
        }
    }

    /**
     * @param int $expectedRequestCount
     */
    protected function expectTotalRequestCount(int $expectedRequestCount): void
    {
        if (!$this->tapper) {
            self::fail('Test calls expectTotalRequestCount without first calling mockGuzzleWithTapper');
        }
        self::assertSame($expectedRequestCount, $this->tapper->getCountAll());
    }

    protected function assertTapperRequestLike(string $method, string $urlPattern, int $count = null): void
    {
        if (is_int($count)) {
            self::assertSame(
                $count,
                $this->tapper->getCountLike($method, $urlPattern),
                "Should see exactly {$count} requests like {$method} {$urlPattern}",
            );
        } else {
            self::assertGreaterThanOrEqual(
                1,
                $this->tapper->getCountLike($method, $urlPattern),
                "Should see one or more requests like {$method} {$urlPattern}",
            );
        }
    }

    /**
     * Takes an array of request descriptions.
     * Each expected request is also an array, the 0th position is the method, 1st position is a regular expression that matches the URI
     * e.g.
     * $this->assertAllTapperRequestsLike([
     *    ['POST', '/GetVehiclesByVINParams/'],
     *    ['GET', '/GetMarketByZIP/'],
     *    ['GET', '/GetStateFeeTax/'],
     *    ['POST', '/PushTomDesking/'],
     * ]);
     *
     * Order is strict.
     */
    protected function assertAllTapperRequestsLike(array $expectedRequests): void
    {
        $actualCalls = $this->tapper
            ->getCalls()
            ->map(fn(RequestInterface $request) => [$request->getMethod(), (string) $request->getUri()]);

        $expectedAsString = collect($expectedRequests)->reduce(
            fn($aggregate, $c) => $aggregate . "{$c[0]} {$c[1]}\n",
            "\n",
        );
        $actualAsString = $actualCalls->reduce(fn($aggregate, $c) => $aggregate . "{$c[0]} {$c[1]}\n", "\n");
        $comment = "\nReceived: {$actualAsString}\nExpected: {$expectedAsString}";

        self::assertSame(count($expectedRequests), $actualCalls->count(), "Unexpected requests. $comment");

        foreach ($actualCalls as $key => $call) {
            $expectedRequest = $expectedRequests[$key];
            self::assertSame($call[0], $expectedRequest[0], "Unexpected method in request {$key}, $comment");
            self::assertMatchesRegularExpression(
                $expectedRequest[1],
                $call[1],
                "Unexpected URL in request {$key}, {$call[1]} should match pattern {$expectedRequest[1]} $comment",
            );
        }
    }

    /**
     * Create a real guzzle client that can use a MockHandler class to mock all
     * requests.
     * @see http://docs.guzzlephp.org/en/stable/testing.html
     * @param MockHandler $mock
     * @return Client
     */
    private function mockGuzzleAppInstanceWithHandler(MockHandler $mock): Client
    {
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $this->app->instance('guzzle', $client);
        return $client;
    }
}
