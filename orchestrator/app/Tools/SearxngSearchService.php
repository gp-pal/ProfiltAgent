<?php

namespace App\Tools;

use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Web search via a self-hosted SearXNG instance (JSON API). Primary engine for
 * the ReAct domain-search phase; SearXNG aggregates Google/Bing/DDG server-side.
 */
class SearxngSearchService
{
    /** How many raw results to retain for later source analysis. */
    private const RAW_CAP = 15;

    private Client $client;

    /** @var array<int, array{title: string, url: string, domain: string, snippet: string, accepted: bool, filtered: ?string}> */
    private array $lastResults = [];

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(['timeout' => 15]);
    }

    /**
     * Raw, annotated results from the most recent searchCandidates() call —
     * including the ones filtered out as aggregators/duplicates. Lets us see what
     * non-official sources exist for a target (Wikipedia, directories, news) when
     * no official site is found, to decide whether new collection actions pay off.
     *
     * @return array<int, array{title: string, url: string, domain: string, snippet: string, accepted: bool, filtered: ?string}>
     */
    public function lastResults(): array
    {
        return $this->lastResults;
    }

    /**
     * Whether this query must be refused to protect the upstream engines.
     *
     * SearXNG proxies providers that rate-limit and CAPTCHA us; once they suspend,
     * every search returns nothing for minutes — so a burst of our own queries is
     * what empties a run. The counter is a fixed one-minute window in the shared
     * cache, making the ceiling global across the HTTP path and the queue worker
     * rather than per-process. Slight overshoot under concurrency is acceptable:
     * this is a protective cap, not an accounting system.
     */
    private function throttled(): bool
    {
        $max = (int) config('services.searxng.max_per_minute', 0);
        if ($max <= 0) {
            return false;
        }

        $key = 'searxng.throttle.' . Carbon::now()->format('YmdHi');
        Cache::add($key, 0, 120);
        $used = (int) Cache::increment($key);

        if ($used > $max) {
            Log::warning('SearXNG query refused by local throttle', ['used' => $used, 'max' => $max]);

            return true;
        }

        return false;
    }

    /**
     * @return array<int, array{title: string, url: string, domain: string, snippet: string}>
     */
    public function searchCandidates(string $query, int $limit = 6): array
    {
        $this->lastResults = [];

        $base = rtrim((string) config('services.searxng.url', ''), '/');
        if ($base === '' || $this->throttled()) {
            return [];
        }

        try {
            $response = $this->client->get("{$base}/search", [
                'query' => [
                    'q' => $query,
                    'format' => 'json',
                    'language' => 'lt',
                ],
                'headers' => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                Log::warning('SearXNG non-200', ['status' => $response->getStatusCode()]);
                return [];
            }

            $data = json_decode((string) $response->getBody(), true);
            $results = $data['results'] ?? [];

            $candidates = [];
            $seen = [];

            foreach ($results as $r) {
                if (count($this->lastResults) >= self::RAW_CAP) {
                    break;
                }

                $url = (string) ($r['url'] ?? '');
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                $domain = $host !== '' ? preg_replace('/^www\./', '', $host) : '';

                $accepted = count($candidates) < $limit;
                $reason = match (true) {
                    $domain === '' => 'no_domain',
                    WebSearchFilters::isDirectoryOrAggregator($domain) => 'aggregator',
                    isset($seen[$domain]) => 'duplicate',
                    !$accepted => 'over_limit',
                    default => null,
                };

                // Retain every raw result (annotated) so filtered-out informational
                // sources stay visible for later analysis.
                $this->lastResults[] = [
                    'title' => (string) ($r['title'] ?? ''),
                    'url' => $url,
                    'domain' => $domain,
                    'snippet' => mb_substr((string) ($r['content'] ?? ''), 0, 300),
                    'accepted' => $reason === null,
                    'filtered' => $reason,
                ];

                if ($reason !== null) {
                    continue;
                }
                $seen[$domain] = true;

                $candidates[] = [
                    'title' => (string) ($r['title'] ?? ''),
                    'url' => $url,
                    'domain' => $domain,
                    'snippet' => (string) ($r['content'] ?? ''),
                ];
            }

            return $candidates;
        } catch (\Throwable $e) {
            Log::warning('SearXNG search failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Search backing the separate news dimension, returning raw results with the
     * publish date. Unlike searchCandidates this does not filter or dedupe —
     * NewsSource applies the news-portal whitelist + on-topic gate.
     *
     * Runs in the `general` category, not `news`: the news-category engines index
     * only some of the whitelisted portals (a `site:delfi.lt` news query returns
     * nothing, while the same query in `general` returns plenty). Precision comes
     * from the caller's `site:` filters instead of the category.
     *
     * NULL means SearXNG never answered this query, so nothing was searched: refused
     * here before it left the process (no endpoint configured, a blank query, or our own
     * rate-limit window spent), unreachable, or answered non-200. An empty ARRAY means
     * SearXNG answered and had nothing for us.
     *
     * A caller that reports what a run actually looked for must tell those apart — see
     * the person-crediting rule in {@see \App\Services\NewsSource::collect()}, where an
     * unanswered query counted as a search claims we asked about a director nobody was
     * ever asked about. The three flavours differ only in which line the client logs;
     * for the caller the fact is the same, so one return value carries it.
     *
     * @return ?array<int, array{title: string, url: string, domain: string, snippet: string, published_date: ?string}>
     */
    public function searchNews(string $query, int $limit = 24): ?array
    {
        $base = rtrim((string) config('services.searxng.url', ''), '/');
        if ($base === '' || trim($query) === '' || $this->throttled()) {
            return null;
        }

        try {
            $response = $this->client->get("{$base}/search", [
                'query' => [
                    'q' => $query,
                    'format' => 'json',
                    'language' => 'lt',
                    'categories' => 'general',
                ],
                'headers' => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                Log::warning('SearXNG news non-200', ['status' => $response->getStatusCode()]);
                return null;
            }

            $data = json_decode((string) $response->getBody(), true);
            $results = $data['results'] ?? [];

            $out = [];
            foreach ($results as $r) {
                if (count($out) >= $limit) {
                    break;
                }

                $url = (string) ($r['url'] ?? '');
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                $domain = $host !== '' ? preg_replace('/^www\./', '', $host) : '';
                if ($url === '' || $domain === '') {
                    continue;
                }

                $out[] = [
                    'title' => (string) ($r['title'] ?? ''),
                    'url' => $url,
                    'domain' => $domain,
                    'snippet' => mb_substr((string) ($r['content'] ?? ''), 0, 300),
                    'published_date' => isset($r['publishedDate']) ? (string) $r['publishedDate'] : null,
                ];
            }

            // The one path that returns an array: SearXNG answered and we parsed its list.
            // An empty $out here is a real answer — it searched and had nothing.
            return $out;
        } catch (\Throwable $e) {
            // Unreachable, timed out, malformed body: no answer, so nothing was searched.
            Log::warning('SearXNG news search failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
