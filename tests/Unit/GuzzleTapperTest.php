<?php

namespace Tests\Unit;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Carsdotcom\ApiRequest\Testing\MocksGuzzleInstance;
use Tests\BaseTestCase;

class GuzzleTapperTest extends BaseTestCase
{
    use MocksGuzzleInstance;

    public function testUnmatchedMethod()
    {
        $tapper = $this->mockGuzzleWithTapper();
        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage("match method GET");

        app()->make('guzzle')->get("http://hopeless.com");
    }

    public function testUnmatchedURL()
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('GET', '/hopeful/', 'true');
        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage("match URL ");

        app()->make('guzzle')->get("http://hopeless.com");
    }

    public function testCanReuseCalls()
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('GET', '/funky.town/', 'take me to');
        $client = app()->make('guzzle');

        // No matter how many times you call it, it's always got an answer for you
        foreach (range(1, 50) as $i) {
            $first = $client->get("http://funky.town/");
            self::assertSame('take me to', (string)$first->getBody());
        }
        self::assertSame(50, $tapper->getCount('GET', 'http://funky.town/'));
    }

    public function testCanReorderCalls()
    {
        $tapper = $this->mockGuzzleWithTapper();
        // Declared apple then banana
        $tapper->addMatchBody('GET', '/apple/', 'green');
        $tapper->addMatchBody('GET', '/banana/', 'yellow');
        $client = app()->make('guzzle');

        // Used banana then apple, still got correct corresponding results
        $bananaResponse = $client->get("http://banana.com/");
        self::assertSame('yellow', (string)$bananaResponse->getBody());

        $appleResponse = $client->get("http://apple.com/");
        self::assertSame('green', (string)$appleResponse->getBody());
    }

    public function testCanDifferentiateMethod()
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('DELETE', '/apple/', 'deleted');
        $tapper->addMatchBody('GET', '/apple/', 'green');
        $tapper->addMatchBody('POST', '/apple/', 'created');
        $client = app()->make('guzzle');

        $createResponse = $client->post('http://awesome.io/apple');
        self::assertSame('created', (string)$createResponse->getBody());

        $getResponse = $client->get('http://awesome.io/apple');
        self::assertSame('green', (string)$getResponse->getBody());

        $deleteResponse = $client->delete('http://awesome.io/apple');
        self::assertSame('deleted', (string)$deleteResponse->getBody());
    }

    public function testCanMatchBadResponseGuzzleMakesException()
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatch('DELETE', '/apple/', new Response(500, [], 'Method not supported'));

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('Method not supported');
        app()->make('guzzle')->delete('http://awesome.io/apple');
    }

    public function testCanThrowException()
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatch('DELETE', '/apple/', new \Exception("Timed out due to not paying attention"));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Timed out due to not paying attention');
        app()->make('guzzle')->delete('http://awesome.io/apple');
    }

    public function testCanDifferentiatePostBodies()
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatch('POST', '/muppets/', function (Request $request) {
            if (Str::contains($request->getBody(), 'NY')) {
                return new Response(200, [], 'Muppets Take Manhattan');
            } elseif (json_decode((string)$request->getBody(), true)['location'] === 'space') {
                return new Response(200, [], 'Muppets In Space');
            } else {
                return new Response(404, [], 'No muppet movies found in that location');
            }
        });

        $client = app()->make('guzzle');
        $NYResponse = $client->post('https://muppets.com', ['json' => ['location' => 'NY']]);
        self::assertSame('Muppets Take Manhattan', (string)$NYResponse->getBody());

        $spaceResponse = $client->post('https://muppets.com', ['json' => ['location' => 'space']]);
        self::assertSame('Muppets In Space', (string)$spaceResponse->getBody());

        try {
            $client->post('https://muppets.com', ['json' => ['location' => 'where does nanny live']]);
            self::fail('Should have thrown exception');
        } catch (ClientException $notFoundException) {
            self::assertSame(404, $notFoundException->getCode());
            self::assertSame('No muppet movies found in that location', (string)$notFoundException->getResponse()->getBody());
        }
    }

    public function testGetCount()
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('GET', '/apple/', 'green');
        $client = app()->make('guzzle');

        self::assertSame(0, $tapper->getCount('GET', 'http://apple.com/'));

        $client->get('http://apple.com/');
        self::assertSame(1, $tapper->getCount('GET', 'http://apple.com/'));

        $client->get('http://apple.com/');
        self::assertSame(2, $tapper->getCount('GET', 'http://apple.com/'));

        $tapper->addMatchBody('POST', '/apple/', 'green');
        $client->post('http://apple.com/');

        // POST calls don't mix with GET calls
        self::assertSame(2, $tapper->getCount('GET', 'http://apple.com/'));
        self::assertSame(1, $tapper->getCount('POST', 'http://apple.com/'));
    }

    public function testGetCountLike()
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('GET', '/apple/', 'green');
        $client = app()->make('guzzle');

        self::assertSame(0, $tapper->getCountLike('GET', '/apple/'));

        $client->get('http://apple.com/');
        self::assertSame(1, $tapper->getCountLike('GET', '/apple/'));

        // Matching by pattern, so changing apple.net to apple.com increments count
        $client->get('http://apple.net/');
        self::assertSame(2, $tapper->getCountLike('GET', '/apple/'));

        $tapper->addMatchBody('POST', '/apple/', 'green');
        $client->post('http://apple.com/');

        // POST calls don't mix with GET calls
        self::assertSame(2, $tapper->getCountLike('GET', '/apple/'));
    }

    public function testGetCountAll()
    {
        $tapper = $this->mockGuzzleWithTapper();
        $tapper->addMatchBody('GET', '/apple/', 'green');
        $tapper->addMatchBody('POST', '/apple/', 'green');
        $client = app()->make('guzzle');

        self::assertSame(0, $tapper->getCountAll());

        $client->get('http://apple.com/');
        self::assertSame(1, $tapper->getCountAll());

        // Second call exact same method and URL
        $client->get('http://apple.com/');
        self::assertSame(2, $tapper->getCountAll(), "Counts duplicates");

        // Same method, new URL
        $client->get('http://apple.net/');
        self::assertSame(3, $tapper->getCountAll(), "Sums across different URLs, same method");

        // New call on POST method
        $client->post('http://apple.com/');
        self::assertSame(4, $tapper->getCountAll(), "Sums across methods");
    }

    public function testCanMockNoneThenReplaceWithMockSome(): void
    {
        $this->mockZeroGuzzleRequests();
        try {
            app()->make('guzzle')->get('http://apple.com/');
            self::fail('Should have thrown a Request Exception, Guzzle is mocked to permit zero requests');
        } catch (RequestException $e) {
            self::assertSame('Guzzle should not have been called.', $e->getMessage());
        }

        $this->mockGuzzleWithTapper()->addMatchBody('GET', '/apple/', 'green');

        $response = app()->make('guzzle')->get('http://apple.com/');
        self::assertSame('green', (string)$response->getBody());
    }
}
