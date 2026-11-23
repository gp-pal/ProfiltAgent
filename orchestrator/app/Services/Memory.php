<?php

namespace App\Services;

use App\Tools\WebSearchFilters;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class Memory
{
    /**
     * Disk-relative location of the domain cache, on the same `local` disk and under the
     * same `runs/` prefix SessionFs uses. It used to be built from `app.runs_dir`, which
     * is resolved against the process working directory — different for artisan and for
     * php-fpm, and under php-fpm it pointed at an app root the web user cannot write, so
     * the cache was never persisted once in production.
     */
    private const CACHE_PATH = 'runs/memory_cache.json';

    private array $cache = [];

    public function __construct()
    {
        $this->loadCache();
    }

    public function storeDomain(string $company, string $domain): void
    {
        // Never persist a directory/aggregator as a company's domain — it would
        // otherwise be refreshed on every cache read and never expire.
        if (WebSearchFilters::isDirectoryOrAggregator($domain)) {
            return;
        }

        $this->cache['domains'][strtolower($company)] = ['domain' => $domain, 'ts' => time()];
        $this->saveCache();
    }

    public function getDomain(string $company): ?string
    {
        $entry = $this->cache['domains'][strtolower($company)] ?? null;

        if (is_array($entry) && isset($entry['domain'], $entry['ts']) && (time() - $entry['ts']) < 86400) {
            // Self-heal: reject aggregator domains cached before the store guard
            // existed, so stale poison (e.g. scoris.lt) can never be returned.
            if (WebSearchFilters::isDirectoryOrAggregator($entry['domain'])) {
                return null;
            }

            return $entry['domain'];
        }

        return null;
    }

    public function storeSearchResults(string $query, array $results): void
    {
        $this->cache['searches'][md5($query)] = [
            'query' => $query,
            'results' => $results,
            'timestamp' => time()
        ];
        $this->saveCache();
    }

    public function getSearchResults(string $query): ?array
    {
        $cached = $this->cache['searches'][md5($query)] ?? null;

        // Cache expires after 24 hours
        if ($cached && (time() - $cached['timestamp']) < 86400) {
            return $cached['results'];
        }

        return null;
    }

    private function loadCache(): void
    {
        if (Storage::disk('local')->exists(self::CACHE_PATH)) {
            $this->cache = json_decode((string) Storage::disk('local')->get(self::CACHE_PATH), true) ?? [];
        }

        if (!isset($this->cache['domains'])) {
            $this->cache['domains'] = [];
        }

        if (!isset($this->cache['searches'])) {
            $this->cache['searches'] = [];
        }
    }

    /**
     * Persist the cache, best-effort.
     *
     * This is an optimisation, not a source of truth, and it is reached from
     * DomainResolutionService AFTER a domain has already been resolved. When the write
     * threw, that exception landed in the resolver's catch and discarded the resolved
     * domain, so official_site had nothing to fetch — a cache miss turned into total
     * data loss. A failed write must cost a slower next run and nothing else.
     */
    private function saveCache(): void
    {
        try {
            Storage::disk('local')->put(
                self::CACHE_PATH,
                json_encode($this->cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        } catch (\Throwable $e) {
            Log::warning('Memory cache write failed; continuing without persisting it', [
                'path' => self::CACHE_PATH,
                'error' => $e->getMessage(),
            ]);
        }
    }
}