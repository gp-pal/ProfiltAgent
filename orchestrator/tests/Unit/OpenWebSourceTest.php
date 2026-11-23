<?php

namespace Tests\Unit;

use App\IO\Contracts\LlmClient;
use App\IO\RunnerClient;
use App\Services\OpenWebSource;
use App\Tools\SearxngSearchService;
use Mockery;
use Tests\TestCase;

class OpenWebSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('react.open_web.enabled', true);
        config()->set('react.open_web.max_pages', 4);
        config()->set('react.open_web.directory_sites', ['imonesinfo.lt']);
        config()->set('react.open_web.directory_urls', []);
    }

    private function source($search = null, $runner = null, $llm = null): OpenWebSource
    {
        return new OpenWebSource(
            $search ?? Mockery::mock(SearxngSearchService::class),
            $runner ?? Mockery::mock(RunnerClient::class),
            $llm ?? Mockery::mock(LlmClient::class),
        );
    }

    public function test_collects_from_open_sources_skipping_social(): void
    {
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchCandidates')->andReturn([]);
        $search->shouldReceive('lastResults')->andReturn([
            ['url' => 'https://lt.wikipedia.org/wiki/Acmena', 'domain' => 'lt.wikipedia.org', 'title' => 'Acmena – Vikipedija', 'snippet' => '', 'accepted' => false, 'filtered' => 'aggregator'],
            ['url' => 'https://facebook.com/acmena', 'domain' => 'facebook.com', 'title' => 'FB', 'snippet' => '', 'accepted' => false, 'filtered' => 'aggregator'],
            ['url' => 'https://naujienos.lt/acmena', 'domain' => 'naujienos.lt', 'title' => 'Straipsnis', 'snippet' => '', 'accepted' => true, 'filtered' => null],
        ]);

        $runner = Mockery::mock(RunnerClient::class);
        $runner->shouldReceive('run')->andReturn(['ok' => true, 'data' => ['content' => 'Acmena yra Lietuvos įmonė, gaminanti X.']]);

        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')->once()->andReturn([
            'description' => 'Acmena yra Lietuvos įmonė, gaminanti X.',
            'activity' => 'X gamyba',
            'emails' => [],
            'phones' => [],
            'address' => '',
            'social_links' => ['https://linkedin.com/company/acmena'],
        ]);

        $r = $this->source($search, $runner, $llm)->collect(['name' => 'Acmena', 'country' => 'LT']);

        $this->assertSame('ok', $r->status);
        $this->assertSame('Acmena yra Lietuvos įmonė, gaminanti X.', $r->fields['description']);
        $this->assertSame(['https://linkedin.com/company/acmena'], $r->fields['social_links']);
        $this->assertArrayNotHasKey('emails', $r->fields);   // empty dropped
        $this->assertArrayNotHasKey('address', $r->fields);  // empty dropped

        // Wikipedia + news kept; facebook skipped as non-scrapeable.
        $this->assertContains('https://lt.wikipedia.org/wiki/Acmena', $r->meta['pages']);
        $this->assertContains('https://naujienos.lt/acmena', $r->meta['pages']);
        $this->assertNotContains('https://facebook.com/acmena', $r->meta['pages']);
    }

    public function test_empty_when_no_scrapeable_sources(): void
    {
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchCandidates')->andReturn([]);
        // Only genuinely non-scrapeable sources: a login-walled social network
        // and the state register's own site (third-party directories like
        // imones.lt ARE scrapeable, so they would not produce an empty result).
        $search->shouldReceive('lastResults')->andReturn([
            ['url' => 'https://facebook.com/x', 'domain' => 'facebook.com', 'title' => '', 'snippet' => '', 'accepted' => false, 'filtered' => 'aggregator'],
            ['url' => 'https://registrucentras.lt/x', 'domain' => 'registrucentras.lt', 'title' => '', 'snippet' => '', 'accepted' => false, 'filtered' => 'aggregator'],
        ]);
        $runner = Mockery::mock(RunnerClient::class);
        $runner->shouldNotReceive('run');
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldNotReceive('structured');

        $r = $this->source($search, $runner, $llm)->collect(['name' => 'Acmena']);

        $this->assertSame('empty', $r->status);
        $this->assertSame([], $r->fields);
    }

    public function test_skipped_when_disabled(): void
    {
        config()->set('react.open_web.enabled', false);
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldNotReceive('searchCandidates');

        $r = $this->source($search)->collect(['name' => 'Acmena']);

        $this->assertSame('skipped', $r->status);
    }

    /**
     * @param array<string, array<int, array<string, string>>> $by_query results keyed by a query prefix
     * @param array<int, string> $queries filled with every query sent
     */
    private function searchReturning(array $by_query, array &$queries): SearxngSearchService
    {
        $results = [];
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchCandidates')->andReturnUsing(function (string $query) use ($by_query, &$results, &$queries) {
            $queries[] = $query;
            $results = [];
            foreach ($by_query as $prefix => $rows) {
                if (str_starts_with($query, $prefix)) {
                    $results = $rows;
                    break;
                }
            }

            return [];
        });
        $search->shouldReceive('lastResults')->andReturnUsing(function () use (&$results) {
            return $results;
        });

        return $search;
    }

    /**
     * @param array<string, string> $pages url => page text
     * @param array<int, string> $fetched filled with every URL fetched
     */
    private function runnerServing(array $pages, array &$fetched): RunnerClient
    {
        $runner = Mockery::mock(RunnerClient::class);
        $runner->shouldReceive('run')->andReturnUsing(function (array $plan) use ($pages, &$fetched) {
            $url = $plan['actions'][0]['url'];
            $fetched[] = $url;

            return ['ok' => true, 'data' => ['content' => $pages[$url] ?? 'Kitas turinys.']];
        });

        return $runner;
    }

    public function test_reads_the_directory_page_that_states_the_registry_code(): void
    {
        $queries = [];
        $fetched = [];
        $search = $this->searchReturning([
            'site:' => [
                // Off-site hit for the same query: never a directory page.
                ['url' => 'https://kitas.lt/acmena', 'domain' => 'kitas.lt', 'title' => 'Kitas'],
                // Sub-page listed before the main page: the shallower path is tried first.
                ['url' => 'https://imonesinfo.lt/imone/acmena_uab/darbuotojai/', 'domain' => 'imonesinfo.lt', 'title' => 'Acmena darbuotojai'],
                // A namesake's main page — same depth as the target's, different code.
                ['url' => 'https://imonesinfo.lt/imone/acmena_grupe/', 'domain' => 'imonesinfo.lt', 'title' => 'Acmena grupė'],
                ['url' => 'https://imonesinfo.lt/imone/acmena_uab/', 'domain' => 'imonesinfo.lt', 'title' => 'Acmena, UAB'],
            ],
            'Acmena' => [['url' => 'https://naujienos.lt/acmena', 'domain' => 'naujienos.lt', 'title' => 'Straipsnis']],
        ], $queries);
        $runner = $this->runnerServing([
            'https://imonesinfo.lt/imone/acmena_grupe/' => 'Acmena grupė, UAB. Įmonės kodas 999999999.',
            'https://imonesinfo.lt/imone/acmena_uab/' => 'Acmena, UAB. Įmonės kodas 123456789. Adresas: Pavyzdžio g. 1.',
        ], $fetched);
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')->once()->andReturn(['address' => 'Pavyzdžio g. 1']);

        $r = $this->source($search, $runner, $llm)->collect(['name' => 'Acmena', 'code' => '123456789']);

        $this->assertSame('site:imonesinfo.lt Acmena', $queries[0]);
        // The namesake was fetched and rejected; the verified page is not fetched twice.
        $this->assertSame(
            ['https://imonesinfo.lt/imone/acmena_grupe/', 'https://imonesinfo.lt/imone/acmena_uab/', 'https://naujienos.lt/acmena'],
            $fetched,
        );
        $this->assertSame(
            ['https://imonesinfo.lt/imone/acmena_uab/', 'https://naujienos.lt/acmena'],
            $r->meta['pages'],
        );
        $this->assertSame('Pavyzdžio g. 1', $r->fields['address']);
    }

    public function test_directory_page_without_the_code_is_not_used(): void
    {
        $queries = [];
        $fetched = [];
        $search = $this->searchReturning([
            'site:' => [['url' => 'https://imonesinfo.lt/imone/acmena_grupe/', 'domain' => 'imonesinfo.lt', 'title' => 'Acmena grupė']],
            'Acmena' => [['url' => 'https://naujienos.lt/acmena', 'domain' => 'naujienos.lt', 'title' => 'Straipsnis']],
        ], $queries);
        $runner = $this->runnerServing([
            'https://imonesinfo.lt/imone/acmena_grupe/' => 'Acmena grupė, UAB. Įmonės kodas 999999999.',
        ], $fetched);
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')->once()->andReturn(['activity' => 'X gamyba']);

        $r = $this->source($search, $runner, $llm)->collect(['name' => 'Acmena', 'code' => '123456789']);

        $this->assertSame(['https://naujienos.lt/acmena'], $r->meta['pages']);
    }

    public function test_no_directory_lookup_without_registry_code(): void
    {
        $queries = [];
        $search = $this->searchReturning([], $queries);

        $this->source($search)->collect(['name' => 'Acmena']);

        $this->assertSame(['Acmena Lietuva', 'Acmena apie'], $queries);
    }

    public function test_opens_the_directory_url_template_with_the_registry_code(): void
    {
        config()->set('react.open_web.directory_sites', []);
        config()->set('react.open_web.directory_urls', ['https://katalogas.lt/paieska/?q={code}']);

        $queries = [];
        $fetched = [];
        $search = $this->searchReturning([
            'Acmena' => [['url' => 'https://naujienos.lt/acmena', 'domain' => 'naujienos.lt', 'title' => 'Straipsnis']],
        ], $queries);
        $runner = $this->runnerServing([
            'https://katalogas.lt/paieska/?q=123456789' => 'Acmena, UAB (įmonės kodas 123456789). Adresas Pavyzdžio g. 1.',
        ], $fetched);
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')->once()->andReturn(['address' => 'Pavyzdžio g. 1']);

        $r = $this->source($search, $runner, $llm)->collect(['name' => 'Acmena', 'code' => '123456789']);

        // No site: search — the template needs no search engine.
        $this->assertSame(['Acmena Lietuva', 'Acmena apie'], $queries);
        $this->assertSame(['https://katalogas.lt/paieska/?q=123456789', 'https://naujienos.lt/acmena'], $fetched);
        $this->assertSame(['https://katalogas.lt/paieska/?q=123456789', 'https://naujienos.lt/acmena'], $r->meta['pages']);
    }

    public function test_directory_url_that_does_not_state_the_code_is_dropped(): void
    {
        config()->set('react.open_web.directory_sites', []);
        config()->set('react.open_web.directory_urls', ['https://katalogas.lt/paieska/?q={code}']);

        $queries = [];
        $fetched = [];
        $search = $this->searchReturning([
            'Acmena' => [['url' => 'https://naujienos.lt/acmena', 'domain' => 'naujienos.lt', 'title' => 'Straipsnis']],
        ], $queries);
        // A "nothing found" lookup page: no registry code in it.
        $runner = $this->runnerServing(['https://katalogas.lt/paieska/?q=123456789' => 'Nieko nerasta.'], $fetched);
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')->once()->andReturn(['activity' => 'X gamyba']);

        $r = $this->source($search, $runner, $llm)->collect(['name' => 'Acmena', 'code' => '123456789']);

        $this->assertSame(['https://naujienos.lt/acmena'], $r->meta['pages']);
    }
}
