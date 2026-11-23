<?php

namespace Tests\Unit;

use App\Tools\SearxngSearchService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class SearxngSearchNewsTest extends TestCase
{
    private function service(string $json, int $status = 200): SearxngSearchService
    {
        config()->set('services.searxng.url', 'http://searxng:8080');
        $mock = new MockHandler([new Response($status, [], $json)]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        return new SearxngSearchService($client);
    }

    public function test_search_news_returns_domain_and_published_date(): void
    {
        $json = json_encode(['results' => [
            ['title' => 'Acmena plečiasi', 'url' => 'https://www.delfi.lt/a/1', 'content' => 'Acmena naujiena', 'publishedDate' => '2026-05-01T00:00:00'],
            ['title' => 'No date piece', 'url' => 'https://15min.lt/b/2', 'content' => 'x'],
        ]], JSON_THROW_ON_ERROR);

        $out = $this->service($json)->searchNews('Acmena', 24);

        $this->assertCount(2, $out);
        $this->assertSame('delfi.lt', $out[0]['domain']);            // www. stripped
        $this->assertSame('2026-05-01T00:00:00', $out[0]['published_date']);
        $this->assertNull($out[1]['published_date']);
        $this->assertSame('15min.lt', $out[1]['domain']);
    }

    public function test_a_missing_base_url_is_a_refusal_not_an_empty_result(): void
    {
        // Still fail-soft (no exception), but null: nothing was sent, and a caller that
        // reports what a run looked for must not read this as "we searched and found
        // nothing" — see NewsSource's person-crediting rule.
        config()->set('services.searxng.url', '');
        $this->assertNull((new SearxngSearchService())->searchNews('Acmena'));
    }

    public function test_a_blank_query_is_a_refusal(): void
    {
        config()->set('services.searxng.url', 'http://searxng:8080');
        $this->assertNull((new SearxngSearchService())->searchNews('   '));
    }

    public function test_an_answered_search_with_no_results_is_an_empty_array_not_a_refusal(): void
    {
        // The other half of the contract: SearXNG answered and had nothing. Conflating the
        // two is what let a director be reported as searched when nothing ever answered.
        $out = $this->service(json_encode(['results' => []], JSON_THROW_ON_ERROR))->searchNews('Acmena', 24);

        $this->assertSame([], $out);
    }

    public function test_a_non_200_is_not_an_answer(): void
    {
        $this->assertNull($this->service('bad gateway', 502)->searchNews('Acmena', 24));
    }

    public function test_an_unreachable_backend_is_not_an_answer(): void
    {
        // Still fail-soft (no exception escapes), but the caller must not read a connection
        // failure as "we searched and found nothing about this person".
        config()->set('services.searxng.url', 'http://searxng:8080');
        $mock = new MockHandler([new ConnectException('refused', new Request('GET', 'x'))]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $this->assertNull((new SearxngSearchService($client))->searchNews('Acmena', 24));
    }

    public function test_news_search_uses_the_general_category(): void
    {
        // Verified live: the news-category engines do not index delfi.lt
        // (`site:delfi.lt` + categories=news returns 0, general returns 25). The
        // portal-targeted queries therefore run in `general` and get their
        // precision from the caller's site: filters instead.
        config()->set('services.searxng.url', 'http://searxng:8080');
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['results' => []], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));

        (new SearxngSearchService(new Client(['handler' => $stack])))->searchNews('Acmena', 24);

        $query = $history[0]['request']->getUri()->getQuery();
        $this->assertStringContainsString('categories=general', $query);
        $this->assertStringNotContainsString('categories=news', $query);
    }
}
