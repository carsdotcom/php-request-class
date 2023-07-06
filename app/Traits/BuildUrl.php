<?php
/**
 * Build url to external API
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Traits;

use GuzzleHttp\Psr7\Uri;

trait BuildUrl
{
    public function buildUrl(string $baseUrl, string $path, array $queryParams = []): string
    {
        $uri = new Uri($baseUrl);
        $uri = $uri->withPath($path);
        return (string) Uri::withQueryValues($uri, $queryParams);
    }
}
