<?php

namespace App\Tools;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Lightweight HTTP GET of an article page (server-rendered HTML), used only to
 * read publish-date meta/JSON-LD when SearXNG and the URL carry no date. A plain
 * fetch is enough (no JS needed for <head> meta), and far cheaper than driving
 * the Playwright runner. Fail-soft: any error / non-200 returns null.
 */
class HttpArticleClient
{
    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(['timeout' => 6, 'allow_redirects' => true]);
    }

    public function fetch(string $url): ?string
    {
        if (trim($url) === '') {
            return null;
        }

        try {
            $response = $this->client->get($url, [
                'headers' => ['Accept' => 'text/html', 'User-Agent' => 'ProfiltAgent/1.0 (+news-date)'],
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            return (string) $response->getBody();
        } catch (\Throwable $e) {
            Log::warning('Article fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
