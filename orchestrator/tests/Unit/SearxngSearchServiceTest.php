<?php

namespace Tests\Unit;

use App\Tools\SearxngSearchService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class SearxngSearchServiceTest extends TestCase
{
    private function serviceReturning(Response $response): SearxngSearchService
    {
        config()->set('services.searxng.url', 'http://searxng:8080');
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]);

        return new SearxngSearchService($client);
    }

    public function test_search_candidates_maps_results_and_skips_aggregators(): void
    {
        $json = json_encode(['results' => [
            ['title' => 'Acmena UAB', 'url' => 'https://acmena.lt/', 'content' => 'Oficiali svetainė'],
            ['title' => 'Acmena LinkedIn', 'url' => 'https://linkedin.com/company/acmena', 'content' => 'social'],
            ['title' => 'Acmena rekvizitai', 'url' => 'https://rekvizitai.lt/imone/acmena/', 'content' => 'katalogas'],
            ['title' => 'Acmena dubl', 'url' => 'https://acmena.lt/kontaktai', 'content' => 'same domain dup'],
        ]]);

        $candidates = $this->serviceReturning(new Response(200, [], $json))->searchCandidates('Acmena', 6);

        $domains = array_column($candidates, 'domain');
        $this->assertContains('acmena.lt', $domains);
        $this->assertNotContains('linkedin.com', $domains);
        $this->assertNotContains('rekvizitai.lt', $domains);
        $this->assertSame(1, count(array_filter($domains, fn ($d) => $d === 'acmena.lt')), 'domains deduplicated');
        $this->assertSame('Acmena UAB', $candidates[0]['title']);
        $this->assertSame('Oficiali svetainė', $candidates[0]['snippet']);
    }

    public function test_last_results_retains_filtered_sources_annotated(): void
    {
        $json = json_encode(['results' => [
            ['title' => 'Acmena UAB', 'url' => 'https://acmena.lt/', 'content' => 'Oficiali svetainė'],
            ['title' => 'Acmena – Vikipedija', 'url' => 'https://lt.wikipedia.org/wiki/Acmena', 'content' => 'enciklopedija'],
            ['title' => 'Acmena rekvizitai', 'url' => 'https://rekvizitai.lt/imone/acmena/', 'content' => 'katalogas'],
            ['title' => 'Acmena dubl', 'url' => 'https://acmena.lt/kontaktai', 'content' => 'dup'],
        ]]);

        $svc = $this->serviceReturning(new Response(200, [], $json));
        $svc->searchCandidates('Acmena', 6);
        $raw = $svc->lastResults();

        // Every source is retained (annotated), in result order, including the
        // ones filtered from the candidate list.
        $this->assertCount(4, $raw);

        $this->assertSame('acmena.lt', $raw[0]['domain']);
        $this->assertTrue($raw[0]['accepted']);
        $this->assertNull($raw[0]['filtered']);

        // Wikipedia / informational sources are surfaced, not silently dropped.
        $this->assertSame('lt.wikipedia.org', $raw[1]['domain']);
        $this->assertFalse($raw[1]['accepted']);
        $this->assertSame('aggregator', $raw[1]['filtered']);

        $this->assertSame('aggregator', $raw[2]['filtered']);
        $this->assertSame('duplicate', $raw[3]['filtered']);
    }

    public function test_search_candidates_empty_on_non_200(): void
    {
        $svc = $this->serviceReturning(new Response(502, [], 'bad gateway'));
        $this->assertSame([], $svc->searchCandidates('Acmena'));
    }

    public function test_search_candidates_empty_on_connection_error(): void
    {
        config()->set('services.searxng.url', 'http://searxng:8080');
        $mock = new MockHandler([new \GuzzleHttp\Exception\ConnectException(
            'refused', new \GuzzleHttp\Psr7\Request('GET', 'x')
        )]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $this->assertSame([], (new SearxngSearchService($client))->searchCandidates('Acmena'));
    }
}
