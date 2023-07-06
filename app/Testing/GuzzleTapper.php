<?php
/**
 * Guzzle Tapper is a way to declaratively configure responses for Guzzle's MockHandler for unit tests.
 * You provide Guzzle Tapper with a HTTP method, URL pattern, and a response behavior.
 * Then your code under test makes Guzzle requests like it normally would.
 * Guzzle Tapper walks the list of URL patterns until it finds a match, then returns the response behavior 
 *   (usually a test body, but could be an exception or even run assertions on the request body before responding)
 */

namespace Carsdotcom\ApiRequest\Testing;

use Exception;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use OutOfBoundsException;
use Psr\Http\Message\RequestInterface;

class GuzzleTapper
{
    /**
     * @var array
     * A two-dimensional array like
     * $this->matches[ method ] [ pattern ] = result
     * Where method should be one of GET, POST, PATCH, PUT, DELETE
     * Pattern must be a valid regex of a URL
     * And result should be a Guzzle Response or an Exception
     */
    protected $matches = [];

    protected Collection $calls;

    public function __construct()
    {
        $this->calls = new Collection();
    }

    /**
     * Add a new fuzzy match.
     * @param string $method
     * @param string $urlPattern A regular expression
     * @param mixed $behavior
     *     Response
     *     Exception
     *     callable - receives the Request as a parameter, must return one of Response, Exception but can use its discretion to return different Responses to different Requests
     */
    public function addMatch(string $method, string $urlPattern, $behavior): self
    {
        $this->matches[$method][$urlPattern] = $behavior;
        return $this;
    }

    /**
     * Add a new fuzzy match that returns a known string.
     * If this is more than a 1-liner, please put it in a tests/data file and use addMatchFile instead
     * @param string $method
     * @param string $urlPattern
     * @param string $body
     * @param int $status
     */
    public function addMatchBody(string $method, string $urlPattern, string $body, int $status = 200): self
    {
        return $this->addMatch($method, $urlPattern, new Response($status, [], $body));
    }

    /**
     * Add a new fuzzy match that returns a test data file.
     * @param string $method
     * @param string $urlPattern
     * @param string $filename
     * @param int $status
     */
    public function addMatchFile(string $method, string $urlPattern, string $filename, int $status = 200): self
    {
        return $this->addMatch($method, $urlPattern, new Response($status, [], Helpers::getDataFile($filename)));
    }

    /**
     * Given a RequestInterface (from a unit test trying to make a Guzzle request)
     * Find the first appropriate match (NOT best-match!) in $this->matches
     *  - Log that the request happened (so we can assert later what requests happened in what order)
     *  - Run and return the behavior described in the match
     * @returns Response a Guzzle response object, including status, headers, body
     * @returns Exception if the behavior *returns* an exception, Guzzle will throw it, e.g. a BadResponseException
     * @throws OutOfBoundsException if the RequestInterface does not match any $this->matches
     */
    public function response(RequestInterface $request, array $options = [])
    {
        $requestMethod = $request->getMethod();
        if (!array_key_exists($requestMethod, $this->matches)) {
            return new \OutOfBoundsException("No responses match method {$requestMethod}");
        }

        $requestUrl = (string) $request->getUri();
        foreach ($this->matches[$requestMethod] as $pattern => $response) {
            if (preg_match($pattern, $requestUrl)) {
                $this->calls->push($request);
                if (is_callable($response)) {
                    return $response($request);
                }
                return $response;
            }
        }

        return new \OutOfBoundsException("No {$requestMethod} responses match URL {$requestUrl}");
    }

    /**
     * Guzzle's MockHandler is designed to fulfill responses in order, internally it array_shifts off a queue.
     * Tapper is designed to allow you to specify if-request-then-response declaratively, so matches aren't order-bound. This is more expressive, and if you refactor your request-making code to change the order of operations, you don't have to rewrite your unit tests.
     * To bridge the two designs, we stuff MockHandler's queue with a large number of our response() method, as a callable. MockHandler will call response() once for every request, and internally Tapper will decide which response to feed it based on the Guzzle Request MockHandler provides us.
     * @return array of callable
     */
    public function getResponses(): array
    {
        return array_fill(0, 100, [$this, 'response']);
    }

    /**
     * Return a count of the number of times this exact method and URL were called
     * Will return 0 for never, obvs
     * @param string $method
     * @param string $exactUrl
     * @return int
     */
    public function getCount(string $method, string $exactUrl): int
    {
        return $this->calls
            ->filter(
                fn(RequestInterface $request) => $request->getMethod() === $method &&
                    (string) $request->getUri() === $exactUrl,
            )
            ->count();
    }

    public function getCountLike(string $method, string $urlPattern): int
    {
        return $this->calls
            ->filter(
                fn(RequestInterface $request) => $request->getMethod() === $method &&
                    preg_match($urlPattern, (string) $request->getUri()),
            )
            ->count();
    }

    /**
     * Count all calls, all methods.
     * Mostly useful for "zero calls to this point in a test"
     * @return int
     */
    public function getCountAll(): int
    {
        return $this->calls->count();
    }

    /**
     * @return Collection
     */
    public function getCalls(): Collection
    {
        return $this->calls;
    }
}
