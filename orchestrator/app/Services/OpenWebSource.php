<?php

namespace App\Services;

use App\IO\Contracts\LlmClient;
use App\IO\RunnerClient;
use App\Tools\SearxngSearchService;
use App\Tools\WebSearchFilters;
use Illuminate\Support\Facades\Log;

/**
 * Open-web source: searches for the target, fetches a few OPEN informational
 * pages (Wikipedia, news, general web — skipping social networks and ToS
 * business directories), and extracts profile fields from their combined
 * content. This is how the collector learns about a target that has no official
 * site but is written about elsewhere.
 */
class OpenWebSource implements CollectionSource
{
    private const CONTENT_CAP = 12000;
    private const PER_PAGE_CAP = 4000;

    /** Directory hits fetched per site while looking for the page that states the code. */
    private const DIRECTORY_CANDIDATES = 3;

    /** @var array<string, mixed> Structured-output schema (no min/max — Anthropic). */
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'description' => ['type' => 'string', 'description' => 'What the company does (prose), from the sources.'],
            'activity' => ['type' => 'string', 'description' => 'Industry / main activity.'],
            'emails' => ['type' => 'array', 'items' => ['type' => 'string']],
            'phones' => ['type' => 'array', 'items' => ['type' => 'string']],
            'address' => ['type' => 'string'],
            'social_links' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => [],
        'additionalProperties' => false,
    ];

    public function __construct(
        private SearxngSearchService $search,
        private RunnerClient $runner,
        private LlmClient $llm,
    ) {
    }

    public function name(): string
    {
        return 'open_web';
    }

    public function supports(array $target): bool
    {
        return trim((string) ($target['name'] ?? '')) !== '' && (bool) config('react.open_web.enabled', true);
    }

    public function collect(array $target): CollectionResult
    {
        if (!$this->supports($target)) {
            return CollectionResult::none($this->name(), 'skipped');
        }

        $name = (string) $target['name'];
        $directory = $this->directoryPages($name, trim((string) ($target['code'] ?? '')));
        $urls = $this->discoverUrls($name, array_fill_keys(array_keys($directory), ''));
        if ($urls === []) {
            return CollectionResult::none($this->name(), 'empty', ['detail' => 'no open sources found']);
        }

        $content = $this->fetchAll($urls, $directory);
        if (trim($content) === '') {
            return CollectionResult::none($this->name(), 'empty', ['detail' => 'no fetchable content', 'pages' => array_keys($urls)]);
        }

        try {
            $fields = $this->normalizeFields($this->llm->structured($this->messages($target, $content), self::SCHEMA));
        } catch (\Throwable $e) {
            Log::warning('OpenWebSource extraction failed', ['error' => $e->getMessage()]);

            return CollectionResult::none($this->name(), 'error', ['detail' => $e->getMessage(), 'pages' => array_keys($urls)]);
        }

        return new CollectionResult(
            $this->name(),
            $fields === [] ? 'empty' : 'ok',
            $fields,
            ['pages' => array_keys($urls)],
        );
    }

    /**
     * Verified directory pages come first, then general name searches fill the
     * remaining slots.
     *
     * @param array<string, string> $urls url => title, already chosen
     *
     * @return array<string, string> url => title
     */
    private function discoverUrls(string $name, array $urls = []): array
    {
        $max = (int) config('react.open_web.max_pages', 4);

        foreach (["{$name} Lietuva", "{$name} apie"] as $query) {
            if (count($urls) >= $max) {
                break;
            }

            foreach ($this->results($query) as $r) {
                $url = (string) ($r['url'] ?? '');
                $domain = (string) ($r['domain'] ?? '');
                if ($url !== '' && !isset($urls[$url]) && WebSearchFilters::isScrapeableOpenSource($domain)) {
                    $urls[$url] = (string) ($r['title'] ?? '');
                }
                if (count($urls) >= $max) {
                    break 2;
                }
            }
        }

        return $urls;
    }

    /**
     * The target's own page on each configured business directory, found two ways:
     *
     *  - `directory_urls`: a URL template with `{code}` that opens (or redirects to)
     *    the company page directly — no search engine involved;
     *  - `directory_sites`: a `site:<host> <name>` search, for directories with no
     *    code-addressable URL. Engines do not index registry codes, hence the name.
     *
     * Either way the page is kept only when its text contains the target's registry
     * code: directories are full of namesakes, and a lookup page that found nothing
     * must not be read as the target's. Without a code nothing is looked up. The
     * page text is returned so the caller does not fetch it twice.
     *
     * @return array<string, string> url => page text
     */
    private function directoryPages(string $name, string $code): array
    {
        if ($code === '') {
            return [];
        }

        $pages = [];

        foreach ((array) config('react.open_web.directory_urls', []) as $template) {
            $template = trim((string) $template);
            if (!str_contains($template, '{code}')) {
                continue;
            }

            $url = str_replace('{code}', rawurlencode($code), $template);
            $text = $this->fetch($url);
            if ($text !== '' && str_contains($text, $code)) {
                $pages[$url] = $text;
            }
        }

        foreach ((array) config('react.open_web.directory_sites', []) as $site) {
            $site = strtolower(trim((string) $site));
            if ($site === '') {
                continue;
            }

            foreach ($this->directoryCandidates($site, $name) as $url) {
                $text = $this->fetch($url);
                if ($text !== '' && str_contains($text, $code)) {
                    $pages[$url] = $text;
                    break;
                }
            }
        }

        return $pages;
    }

    /**
     * On-site search hits, shallowest path first: a company's main page sits above
     * its sub-pages (staff, news, reviews), and it is the one that states the code.
     *
     * @return array<int, string>
     */
    private function directoryCandidates(string $site, string $name): array
    {
        $candidates = [];

        foreach ($this->results("site:{$site} {$name}") as $r) {
            $url = (string) ($r['url'] ?? '');
            $domain = strtolower((string) ($r['domain'] ?? ''));
            $on_site = $domain === $site || str_ends_with($domain, '.' . $site);
            if ($url !== '' && $on_site && WebSearchFilters::isScrapeableOpenSource($domain)) {
                $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
                $candidates[$url] = $path === '' ? 0 : substr_count($path, '/') + 1;
            }
        }

        asort($candidates, SORT_NUMERIC);

        return array_slice(array_keys($candidates), 0, self::DIRECTORY_CANDIDATES);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function results(string $query): array
    {
        try {
            $this->search->searchCandidates($query, 8);
        } catch (\Throwable $e) {
            Log::warning('OpenWebSource search failed', ['query' => $query, 'error' => $e->getMessage()]);

            return [];
        }

        return $this->search->lastResults();
    }

    /**
     * @param array<string, string> $urls url => title
     * @param array<string, string> $fetched url => page text already in hand
     */
    private function fetchAll(array $urls, array $fetched = []): string
    {
        $parts = [];

        foreach (array_keys($urls) as $url) {
            $text = $fetched[$url] ?? $this->fetch($url);
            if ($text !== '') {
                $parts[] = "# SOURCE: {$url}\n" . mb_substr($text, 0, self::PER_PAGE_CAP);
            }
        }

        return mb_substr(implode("\n\n", $parts), 0, self::CONTENT_CAP);
    }

    private function fetch(string $url): string
    {
        try {
            $res = $this->runner->run([
                'version' => '1.0',
                'meta' => ['task' => 'open web fetch', 'company' => 'open_web', 'domain' => (string) parse_url($url, PHP_URL_HOST)],
                'actions' => [
                    ['do' => 'goto', 'url' => $url, 'timeout_ms' => 20000],
                    ['do' => 'extract_markdown', 'save_as' => 'content'],
                    ['do' => 'return', 'data' => ['content']],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('OpenWebSource fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return '';
        }

        return empty($res['ok']) ? '' : trim((string) ($res['data']['content'] ?? ''));
    }

    /**
     * @param array<string, mixed> $target
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function messages(array $target, string $content): array
    {
        $system = <<<'TXT'
        Extract structured company information about the TARGET from the open-web
        content below (Wikipedia, news, directories, general pages). Content may be
        in Lithuanian or English.

        Include ONLY information clearly about the TARGET company — ignore unrelated,
        same-named entities. Leave a field empty if the sources don't support it;
        do not invent contacts or an address.
        TXT;

        $target_lines = ['TARGET:', '- name: ' . ($target['name'] ?? '')];
        foreach (['code' => 'registry code', 'address' => 'known address', 'country' => 'country'] as $key => $label) {
            $value = trim((string) ($target[$key] ?? ''));
            if ($value !== '') {
                $target_lines[] = "- {$label}: {$value}";
            }
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => implode("\n", $target_lines) . "\n\nOPEN-WEB CONTENT:\n" . $content],
        ];
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function normalizeFields(array $raw): array
    {
        $fields = [];

        foreach (['description', 'activity', 'address'] as $key) {
            $value = trim((string) ($raw[$key] ?? ''));
            if ($value !== '') {
                $fields[$key] = $value;
            }
        }

        foreach (['emails', 'phones', 'social_links'] as $key) {
            $list = [];
            foreach ((array) ($raw[$key] ?? []) as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $list[] = trim($item);
                }
            }
            if ($list !== []) {
                $fields[$key] = $list;
            }
        }

        return $fields;
    }
}
