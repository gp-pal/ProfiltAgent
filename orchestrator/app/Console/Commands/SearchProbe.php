<?php

namespace App\Console\Commands;

use App\Services\NewsPortalRegistry;
use App\Tools\CompanyNameTokens;
use App\Tools\SearxngSearchService;
use App\Tools\WebSearchFilters;
use Illuminate\Console\Command;

/**
 * Show what a search actually returns, and what the pipeline then does with each
 * result — the two filters that decide whether a link ever reaches the runner.
 *
 * This exists because "the news layer found nothing" has three completely different
 * causes that look identical from the outside: the search returned nothing, the
 * results were not on whitelisted portals, or they were dropped as off-topic. Reading
 * a verdict per row is the only way to tell them apart without guessing.
 */
class SearchProbe extends Command
{
    protected $signature = 'agent:search-probe
        {query : The search query, exactly as the pipeline would send it}
        {--company= : Apply the real on-topic gate against this company name}
        {--limit=24 : How many results to ask for}
        {--json : Emit the rows as JSON instead of a table}';

    protected $description = 'Run one search and show every result with the pipeline\'s accept/reject verdict for it.';

    public function handle(SearxngSearchService $search, NewsPortalRegistry $portals): int
    {
        $query = (string) $this->argument('query');
        $company = trim((string) $this->option('company'));
        $tokens = $company === '' ? [] : CompanyNameTokens::tokens($company);
        $enabled = $portals->enabledDomains();

        if ($company !== '') {
            $this->line("Company tokens: " . json_encode($tokens, JSON_UNESCAPED_UNICODE));
        }
        $this->line('Portals enabled: ' . count($enabled));
        $this->line("Query: {$query}");
        $this->newLine();

        $results = $search->searchNews($query, (int) $this->option('limit'));

        if ($results === null) {
            // Nothing was sent: the client refused the query itself. Reported apart from
            // an answered-but-empty search, because no amount of retrying helps until the
            // rate-limit window rolls over (or SEARXNG_URL is set).
            $this->error('Query refused locally — nothing reached SearXNG.');
            $this->line('Either SEARXNG_URL is unset or the local rate-limit window (SEARXNG_MAX_PER_MINUTE) is spent.');

            return self::FAILURE;
        }

        if ($results === []) {
            // The distinction the pipeline itself now draws: nothing returned means we
            // could not see, which is not the same as nothing being on topic.
            $this->error('0 results returned — the search itself gave us nothing to judge.');
            $this->line('Check engine health with: php artisan agent:search-engines');

            return self::FAILURE;
        }

        $rows = [];
        $kept = 0;

        foreach ($results as $i => $r) {
            $isPortal = WebSearchFilters::isNewsPortal($r['domain'], $enabled);
            $onTopic = $tokens === []
                ? null
                : CompanyNameTokens::containsAll($tokens, $r['title'] . ' ' . $r['snippet']);

            $verdict = match (true) {
                ! $isPortal => 'DROP not-a-portal',
                $onTopic === false => 'DROP off-topic',
                default => 'KEEP',
            };
            if ($verdict === 'KEEP') {
                $kept++;
            }

            $rows[] = [
                'n' => $i + 1,
                'domain' => $r['domain'],
                'portal' => $isPortal ? 'Y' : 'N',
                'on_topic' => $onTopic === null ? '-' : ($onTopic ? 'Y' : 'N'),
                'date' => $r['published_date'] ?? '-',
                'verdict' => $verdict,
                'title' => mb_substr($r['title'], 0, 58),
                'url' => $r['url'],
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'domain', 'portal', 'on-topic', 'date', 'verdict', 'title'],
            array_map(static fn (array $r): array => [
                $r['n'], $r['domain'], $r['portal'], $r['on_topic'], $r['date'], $r['verdict'], $r['title'],
            ], $rows),
        );

        $this->newLine();
        $this->line(sprintf(
            'returned %d, on a whitelisted portal %d, kept after the on-topic gate %d',
            count($rows),
            count(array_filter($rows, static fn (array $r): bool => $r['portal'] === 'Y')),
            $kept,
        ));

        if ($kept === 0) {
            $this->warn('Nothing survived: these results would never reach the runner.');
        }

        return self::SUCCESS;
    }
}
