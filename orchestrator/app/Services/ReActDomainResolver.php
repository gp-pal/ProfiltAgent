<?php

namespace App\Services;

use App\IO\Contracts\LlmClient;
use App\IO\RunnerClient;
use App\Tools\SearxngSearchService;
use App\Validation\UrlGuard;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Tool;

/**
 * Hybrid ReAct domain-search phase: drives an LLM tool-loop (web_search /
 * visit_page / confirm_domain) to discover a company's official website
 * domain, with deterministic guards so the LLM cannot self-confirm without
 * having actually visited (and, when available, verified via About/Apie mus)
 * the candidate site.
 */
class ReActDomainResolver
{
    private const MAX_SEARCH = 3;
    private const MAX_VISIT = 4;
    private const CONTENT_CHARS = 2500;

    /** @var array<string, string> "registered-domain:home"|"registered-domain:about" => visited marker */
    private array $visited = [];
    /** @var array<string, bool> registered-domain => had an About/Apie link on homepage */
    private array $aboutLinkSeen = [];
    private int $searchCalls = 0;
    private int $visitCalls = 0;
    private ?array $confirmed = null;
    private array $trace = [];
    /** @var array<int, array{query: string, source: string, results: array<int, mixed>}> */
    private array $searchLog = [];

    // About/Contact link text/href patterns (LT + EN), for surfacing + verification.
    private const ABOUT_PATTERNS = ['apie', 'about', 'kontakt', 'contact'];

    public function __construct(
        private LlmClient $llm,
        private SearxngSearchService $search,
        private RunnerClient $runner,
        private UrlGuard $urlGuard,
        private Memory $memory,
        private ?\App\Observability\LangfuseTracer $tracer = null,
    ) {
        $this->tracer = $tracer ?? app(\App\Observability\LangfuseTracer::class);
    }

    /**
     * @return array{domain: string, trace: array}|null
     */
    /**
     * @param array<string, mixed> $targetFacts name/code/city/address/legal_form/country
     */
    public function resolve(string $company, string $session, ?callable $onEvent = null, array $targetFacts = []): ?array
    {
        $this->resetState();

        $system = file_get_contents(resource_path('prompts/react_search.txt')) ?: '';
        $user = $this->buildUserPrompt($company, $targetFacts);
        $tools = $this->buildTools();

        try {
            $result = $this->llm->agentic($system, $user, $tools, (int) config('react.max_steps', 8));
            $this->trace = $result['steps'] ?? [];
            if ($onEvent !== null) {
                foreach ($this->trace as $i => $step) {
                    $onEvent([
                        'phase' => 'react.step',
                        'step' => $i,
                        'text' => $step['text'] ?? '',
                        'tool_calls' => $step['tool_calls'] ?? [],
                        'tool_results' => $step['tool_results'] ?? [],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ReAct domain resolve failed', ['company' => $company, 'error' => $e->getMessage()]);
            return null;
        }

        if ($this->confirmed === null) {
            return null;
        }

        return ['domain' => $this->confirmed['domain'], 'trace' => $this->trace];
    }

    private function resetState(): void
    {
        $this->visited = [];
        $this->aboutLinkSeen = [];
        $this->searchCalls = 0;
        $this->visitCalls = 0;
        $this->confirmed = null;
        $this->trace = [];
        $this->searchLog = [];
    }

    /**
     * Raw, annotated search results captured per web_search call during the last
     * resolve() — including non-official sources filtered out of the candidate
     * list. Persisted by the caller for source analysis. Populated even when
     * resolve() returns null (no official site found), which is exactly the case
     * worth analysing.
     *
     * @return array<int, array{query: string, source: string, results: array<int, mixed>}>
     */
    public function searchLog(): array
    {
        return $this->searchLog;
    }

    /**
     * Build the ReAct user prompt. When target facts are supplied, spell out that
     * the confirmed site must belong to THIS specific company (country + registry
     * facts) — a same-named company in another country is not a match — so the
     * loop won't confirm a foreign or aggregator domain that merely shares the name.
     *
     * @param array<string, mixed> $targetFacts
     */
    private function buildUserPrompt(string $company, array $targetFacts): string
    {
        $lines = ["Company name: {$company}", 'Find its official website domain.'];

        $facts = [];
        $country = trim((string) ($targetFacts['country'] ?? ''));
        if ($country !== '') {
            $facts[] = "country: {$country}";
        }
        foreach (['code' => 'registry code', 'city' => 'city', 'address' => 'address', 'legal_form' => 'legal form'] as $key => $label) {
            $value = trim((string) ($targetFacts[$key] ?? ''));
            if ($value !== '') {
                $facts[] = "{$label}: {$value}";
            }
        }

        if ($facts !== []) {
            $lines[] = '';
            $lines[] = 'TARGET company facts — ' . implode('; ', $facts) . '.';
            $lines[] = 'Confirm a domain ONLY if the site clearly belongs to THIS company (matching the country and, where given, the other facts). A same-named company in another country, or a directory/aggregator, is NOT a match — if none matches, do not confirm.';
        }

        return implode("\n", $lines);
    }

    /** @return array<int, \Prism\Prism\Tool> */
    private function buildTools(): array
    {
        $webSearch = Tool::as('web_search')
            ->for('Search the web for the company official site; returns candidate results.')
            ->withStringParameter('query', 'Search query')
            ->using(function (string $query): string {
                $span = $this->tracer->span('tool:web_search', ['query' => $query]);
                try {
                    $result = (function () use ($query): string {
                        if ($this->searchCalls >= self::MAX_SEARCH) {
                            return 'ERROR: search budget exhausted; decide from what you already have.';
                        }
                        $this->searchCalls++;

                        $cached = $this->memory->getSearchResults($query);
                        $candidates = $cached ?? $this->search->searchCandidates($query, 6);
                        if ($cached === null) {
                            $this->memory->storeSearchResults($query, $candidates);
                        }

                        // Record what the query surfaced (raw annotated results when
                        // fresh) for later analysis of non-official info sources.
                        $this->searchLog[] = [
                            'query' => $query,
                            'source' => $cached === null ? 'searxng' : 'cache',
                            'results' => $cached === null ? $this->search->lastResults() : $candidates,
                        ];

                        if ($candidates === []) {
                            return 'No candidates found for this query.';
                        }

                        $lines = [];
                        foreach ($candidates as $c) {
                            $lines[] = "- {$c['domain']} | {$c['title']} | {$c['snippet']}";
                        }
                        return "Candidates:\n" . implode("\n", $lines);
                    })();
                    return $result;
                } finally {
                    $span->end(['result' => mb_substr($result ?? '', 0, 500)]);
                }
            });

        $visitPage = Tool::as('visit_page')
            ->for('Fetch a page as markdown plus its internal links (About/Apie mus first).')
            ->withStringParameter('url', 'Absolute http(s) URL to visit')
            ->using(function (string $url): string {
                $span = $this->tracer->span('tool:visit_page', ['url' => $url]);
                try {
                    $result = (function () use ($url): string {
                        if ($this->visitCalls >= self::MAX_VISIT) {
                            return 'ERROR: visit budget exhausted; confirm or stop.';
                        }
                        try {
                            $this->urlGuard->assertSafe($url);
                        } catch (\InvalidArgumentException $e) {
                            return 'ERROR: url blocked by policy: ' . $e->getMessage();
                        }

                        $registered = $this->registeredDomain((string) parse_url($url, PHP_URL_HOST));
                        $isAbout = $this->looksLikeAbout($url);
                        $key = $registered . ($isAbout ? ':about' : ':home');
                        if (isset($this->visited[$key])) {
                            return 'ERROR: already visited this page.';
                        }
                        $this->visitCalls++;

                        $plan = [
                            'version' => '1.0',
                            'meta' => ['task' => 'ReAct visit', 'company' => 'react', 'domain' => $registered],
                            'actions' => [
                                ['do' => 'goto', 'url' => $url, 'timeout_ms' => 20000],
                                ['do' => 'wait_for', 'selector' => 'body', 'timeout_ms' => 8000],
                                ['do' => 'extract_markdown', 'save_as' => 'content'],
                                ['do' => 'extract_links', 'save_as' => 'links', 'limit' => 30],
                                ['do' => 'return', 'data' => ['content', 'links']],
                            ],
                        ];

                        $res = $this->runner->run($plan);
                        if (empty($res['ok'])) {
                            return 'ERROR: could not load page: ' . (string) ($res['error'] ?? 'unknown');
                        }

                        $this->visited[$key] = $isAbout ? 'about' : 'home';
                        if ($isAbout) {
                            // mark that about got visited for this domain
                            $this->visited[$registered . ':about'] = 'about';
                        }

                        $content = mb_substr((string) ($res['data']['content'] ?? ''), 0, self::CONTENT_CHARS);
                        $links = $this->sortInternalLinks($res['data']['links'] ?? [], $registered);

                        if (!$isAbout && $this->hasAboutLink($links)) {
                            $this->aboutLinkSeen[$registered] = true;
                        }

                        $linkLines = array_slice(array_map(
                            static fn (array $l): string => "  - {$l['text']}: {$l['href']}",
                            $links
                        ), 0, 15);

                        return "URL: {$url}\nContent:\n{$content}\n\nInternal links:\n" . implode("\n", $linkLines);
                    })();
                    return $result;
                } finally {
                    $span->end(['result' => mb_substr($result ?? '', 0, 500)]);
                }
            });

        $confirm = Tool::as('confirm_domain')
            ->for('Confirm the official registered domain. Terminal step.')
            ->withStringParameter('domain', 'Registered domain, e.g. example.lt')
            ->withStringParameter('reason', 'Why this is the official site')
            ->using(function (string $domain, string $reason): string {
                $span = $this->tracer->span('tool:confirm_domain', ['domain' => $domain, 'reason' => $reason]);
                try {
                    $result = (function () use ($domain, $reason): string {
                        $registered = $this->registeredDomain($domain);

                        $visitedHome = isset($this->visited[$registered . ':home']);
                        $visitedAbout = isset($this->visited[$registered . ':about']);

                        if (!$visitedHome && !$visitedAbout) {
                            return 'ERROR: visit the site before confirming.';
                        }
                        if (($this->aboutLinkSeen[$registered] ?? false) && !$visitedAbout) {
                            return 'ERROR: visit the About/Apie mus page before confirming.';
                        }

                        $this->confirmed = ['domain' => $registered, 'reason' => $reason];
                        return 'OK';
                    })();
                    return $result;
                } finally {
                    $span->end(['result' => mb_substr($result ?? '', 0, 500)]);
                }
            });

        return [$webSearch, $visitPage, $confirm];
    }

    private function looksLikeAbout(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        foreach (self::ABOUT_PATTERNS as $p) {
            if (str_contains($path, $p)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int, array{text?: string, href?: string}> $links */
    private function hasAboutLink(array $links): bool
    {
        foreach ($links as $l) {
            $hay = strtolower(($l['text'] ?? '') . ' ' . ($l['href'] ?? ''));
            foreach (self::ABOUT_PATTERNS as $p) {
                if (str_contains($hay, $p)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Keep only same-registered-domain links; put About/Contact-looking ones first.
     * @param array<int, array{text?: string, href?: string}> $links
     * @return array<int, array{text: string, href: string}>
     */
    private function sortInternalLinks(array $links, string $registered): array
    {
        $internal = [];
        foreach ($links as $l) {
            $href = (string) ($l['href'] ?? '');
            $host = (string) parse_url($href, PHP_URL_HOST);
            if ($host === '' || $this->registeredDomain($host) !== $registered) {
                continue;
            }
            $internal[] = ['text' => trim((string) ($l['text'] ?? '')), 'href' => $href];
        }

        usort($internal, function (array $a, array $b): int {
            $aw = $this->hasAboutLink([$a]) ? 0 : 1;
            $bw = $this->hasAboutLink([$b]) ? 0 : 1;
            return $aw <=> $bw;
        });

        return $internal;
    }

    private function registeredDomain(string $host): string
    {
        $host = strtolower(trim($host));
        $parts = explode('.', $host);
        $n = count($parts);
        return $n >= 2 ? $parts[$n - 2] . '.' . $parts[$n - 1] : $host;
    }
}
