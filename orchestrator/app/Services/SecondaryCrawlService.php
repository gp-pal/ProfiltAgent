<?php

namespace App\Services;

use App\IO\RunnerClient;
use App\Validation\PolicyChecks;
use App\Validation\SchemaValidator;
use Illuminate\Support\Facades\Log;

/**
 * Step-2: crawl a few key internal pages (About / Contact / Services) discovered
 * by HtmlStructureAnalyzer, so the company profile can be built from more than
 * just the homepage.
 *
 * The plan is built deterministically (no LLM) and reuses the existing schema +
 * policy validation and the Playwright runner. Pages are returned as runner-converted
 * markdown.
 */
class SecondaryCrawlService
{
    private const MAX_PAGES = 4;
    private const PAGE_CATEGORIES = ['about', 'contact', 'products'];
    private const MAX_TEXT = 6000;

    public function __construct(
        private SchemaValidator $schemaValidator,
        private PolicyChecks $policyChecks,
        private RunnerClient $runnerClient,
    ) {}

    /**
     * @param array<string, mixed> $structure HtmlStructureAnalyzer output
     * @return array<int, array{url: string, text: string}>
     */
    public function crawl(string $company, string $domain, array $structure): array
    {
        $urls = $this->selectUrls($domain, $structure);

        if ($urls === []) {
            return [];
        }

        $plan = $this->buildPlan($company, $domain, $urls);

        try {
            $this->schemaValidator->validate($plan);
            $this->policyChecks->check($plan);
        } catch (\Throwable $e) {
            Log::warning('Step-2 crawl plan rejected', ['error' => $e->getMessage()]);

            return [];
        }

        $result = $this->runnerClient->run($plan);

        if (empty($result['ok'])) {
            Log::warning('Step-2 crawl runner failed', ['error' => $result['error'] ?? null]);

            return [];
        }

        return $this->collectPages($urls, $result['data'] ?? []);
    }

    /**
     * Pick same-domain About/Contact/Services URLs from the structure analysis.
     *
     * @param array<string, mixed> $structure
     * @return array<int, string>
     */
    private function selectUrls(string $domain, array $structure): array
    {
        $important = $structure['important_links'] ?? [];
        $root = "https://{$domain}";
        $urls = [];

        foreach (self::PAGE_CATEGORIES as $category) {
            foreach ($important[$category] ?? [] as $link) {
                $url = is_array($link) ? ($link['url'] ?? '') : '';
                $url = $this->cleanUrl((string) $url);

                if ($url === '' || $url === $root || rtrim($url, '/') === $root) {
                    continue;
                }

                if ($this->isSameDomain($url, $domain) && !in_array($url, $urls, true)) {
                    $urls[] = $url;
                }

                if (count($urls) >= self::MAX_PAGES) {
                    return $urls;
                }
            }
        }

        return $urls;
    }

    /**
     * @param array<int, string> $urls
     * @return array{version: string, meta: array<string, mixed>, actions: array<int, array<string, mixed>>}
     */
    private function buildPlan(string $company, string $domain, array $urls): array
    {
        $actions = [];
        $saveKeys = [];

        foreach ($urls as $i => $url) {
            $key = "page_{$i}";
            $saveKeys[] = $key;
            $actions[] = ['do' => 'goto', 'url' => $url, 'timeout_ms' => 20000];
            $actions[] = ['do' => 'wait_for', 'selector' => 'body', 'timeout_ms' => 8000];
            $actions[] = ['do' => 'extract_markdown', 'save_as' => $key];
        }

        $actions[] = ['do' => 'return', 'data' => $saveKeys];

        return [
            'version' => '1.0',
            'meta' => [
                'task' => 'Step-2 internal pages capture',
                'company' => $company,
                'domain' => $domain,
            ],
            'actions' => $actions,
        ];
    }

    /**
     * @param array<int, string> $urls
     * @param array<string, mixed> $data
     * @return array<int, array{url: string, text: string}>
     */
    private function collectPages(array $urls, array $data): array
    {
        $pages = [];

        foreach ($urls as $i => $url) {
            $markdown = $data["page_{$i}"] ?? null;

            if (!is_string($markdown) || $markdown === '') {
                continue;
            }

            $text = $this->normalizeText($markdown);

            if ($text !== '') {
                $pages[] = ['url' => $url, 'text' => $text];
            }
        }

        return $pages;
    }

    private function cleanUrl(string $url): string
    {
        $url = trim($url);

        return preg_replace('/#.*$/', '', $url) ?? $url;
    }

    private function isSameDomain(string $url, string $domain): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $domain = strtolower($domain);

        return $host === $domain || str_ends_with($host, ".{$domain}");
    }

    private function normalizeText(string $text): string
    {
        return mb_substr(trim($text), 0, self::MAX_TEXT);
    }
}
