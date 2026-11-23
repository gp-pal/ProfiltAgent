<?php

namespace Tests\Unit;

use App\Services\NewsPortalRegistry;
use App\Tools\SearxngSearchService;
use Mockery;
use Tests\TestCase;

/**
 * The probe's whole value is that its verdict column matches what the pipeline really
 * does. A probe that reported a different answer than the collector would be worse
 * than no probe at all, so these pin the three outcomes it has to tell apart.
 */
class SearchProbeCommandTest extends TestCase
{
    /**
     * @param ?array<int, array<string, mixed>> $results null = the client refused the query
     */
    private function stubSearch(?array $results): void
    {
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchNews')->andReturn($results);
        $this->app->instance(SearxngSearchService::class, $search);

        $portals = Mockery::mock(NewsPortalRegistry::class);
        $portals->shouldReceive('enabledDomains')->andReturn(['delfi.lt', '15min.lt']);
        $this->app->instance(NewsPortalRegistry::class, $portals);
    }

    public function test_it_reports_a_blind_search_as_a_failure(): void
    {
        // Zero results is the case that looked like "no coverage" for a whole day.
        $this->stubSearch([]);

        $this->artisan('agent:search-probe', ['query' => '"Didmena" (site:delfi.lt)'])
            ->expectsOutputToContain('0 results returned')
            ->assertExitCode(1);
    }

    public function test_it_tells_a_locally_refused_query_apart_from_a_blind_search(): void
    {
        // Both exit 1, but they need different fixes: a refusal means nothing was sent, so
        // waiting out the rate-limit window is the answer, not probing harder.
        $this->stubSearch(null);

        $this->artisan('agent:search-probe', ['query' => 'Didmena'])
            ->expectsOutputToContain('refused locally')
            ->assertExitCode(1);
    }

    public function test_it_marks_a_non_portal_result_as_dropped(): void
    {
        $this->stubSearch([
            ['title' => 'Didmena', 'url' => 'https://scoris.lt/x', 'domain' => 'scoris.lt', 'snippet' => 'Didmena', 'published_date' => null],
        ]);

        $this->artisan('agent:search-probe', ['query' => 'Didmena', '--company' => 'Didmena LT, UAB'])
            ->expectsOutputToContain('not-a-portal')
            ->assertExitCode(0);
    }

    public function test_it_marks_an_off_topic_portal_result_as_dropped(): void
    {
        $this->stubSearch([
            ['title' => 'Visai kita įmonė', 'url' => 'https://delfi.lt/x', 'domain' => 'delfi.lt', 'snippet' => 'nieko', 'published_date' => null],
        ]);

        $this->artisan('agent:search-probe', ['query' => 'Didmena', '--company' => 'Didmena LT, UAB'])
            ->expectsOutputToContain('off-topic')
            ->expectsOutputToContain('kept after the on-topic gate 0')
            ->assertExitCode(0);
    }

    public function test_it_keeps_a_result_the_pipeline_would_keep(): void
    {
        $this->stubSearch([
            ['title' => 'Didmena uždaro parduotuvę', 'url' => 'https://delfi.lt/a/1', 'domain' => 'delfi.lt', 'snippet' => 'Didmena', 'published_date' => '2026-06-01'],
        ]);

        $this->artisan('agent:search-probe', ['query' => 'Didmena', '--company' => 'Didmena LT, UAB'])
            ->expectsOutputToContain('KEEP')
            ->expectsOutputToContain('kept after the on-topic gate 1')
            ->assertExitCode(0);
    }

    public function test_without_a_company_the_on_topic_gate_is_reported_as_not_applied(): void
    {
        // Probing a raw query without a company is legitimate — you are asking what the
        // engines return, not what the collector would keep. The column must say so
        // rather than implying everything passed.
        $this->stubSearch([
            ['title' => 'Bet kas', 'url' => 'https://delfi.lt/a/1', 'domain' => 'delfi.lt', 'snippet' => '', 'published_date' => null],
        ]);

        $this->artisan('agent:search-probe', ['query' => 'Didmena'])
            ->expectsOutputToContain('KEEP')
            ->assertExitCode(0);
    }
}
