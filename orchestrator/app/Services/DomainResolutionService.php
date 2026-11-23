<?php

namespace App\Services;

use App\IO\RunnerClient;
use App\Tools\SearchDdgService;
use App\Tools\SessionFs;
use Illuminate\Support\Facades\Log;

/**
 * DomainResolutionService - RESOLVE_DOMAIN phase for Step-1.
 *
 * Finds the official site when the caller supplies no domain, extracted from
 * ReActLoopService so the loop only orchestrates it. Resolution order:
 * - cache (Memory)
 * - ReAct search loop (ReActDomainResolver)
 * - heuristic fallback (SearchDdgService), verified via CompanyMatchJudge
 *
 * Mutates the shared run state in place: on success it commits the domain and
 * moves the pending run path to the resolved one; otherwise it sets an error.
 */
class DomainResolutionService
{
    public function __construct(
        private Memory $memory,
        private ReActDomainResolver $reactResolver,
        private SearchDdgService $searchService,
        private RunnerClient $runnerClient,
        private CompanyMatchJudge $matchJudge,
        private SessionFs $sessionFs,
    ) {}

    /**
     * Phase 2: RESOLVE_DOMAIN - Find official site if domain is missing
     */
    public function resolve(array &$state): void
    {
        if ($state['domain']) {
            return;
        }

        try {
            $company = $state['company'];
            $resolvedVia = null;
            $resolvedDomain = null;

            // 1) cache
            $cached = $this->memory->getDomain($company);
            if ($cached) {
                $resolvedDomain = $cached;
                $resolvedVia = 'cache';
            }

            // 2) ReAct search loop (only when enabled and supported)
            if (!$resolvedDomain && config('react.search_enabled', true)) {
                try {
                    $onEvent = $state['thinking']
                        ? function (array $e) use (&$state) { $state['events'][] = $e; }
                        : null;
                    $react = $this->reactResolver->resolve($company, $state['session'], $onEvent, $state['target_facts'] ?? []);
                    if ($react !== null) {
                        $resolvedDomain = $react['domain'];
                        $resolvedVia = 'react';
                        $state['react_trace'] = $react['trace'];
                    }
                } catch (\Throwable $e) {
                    Log::warning('ReAct resolver error, falling back', ['error' => $e->getMessage()]);
                }
            }

            // 3) heuristic fallback — verified before commit. Unlike the ReAct
            // path it has no built-in company-match check, so an unverified guess
            // could otherwise commit to a wrong domain (e.g. an apartment
            // association resolving to lrs.lt). We reject a definite mismatch and
            // fall through to no_site_found instead.
            if (!$resolvedDomain) {
                $candidate = $this->searchService->findOfficialSite($company);
                if ($candidate !== null && $this->heuristicCandidatePasses($candidate, $state['target_facts'] ?? [])) {
                    $resolvedDomain = $candidate;
                    $resolvedVia = 'heuristic';
                }
            }

            // Persist what the search surfaced (incl. non-official sources) before
            // deciding — written to the current (pending) path so it survives both
            // a no_site_found outcome and the pending->resolved move below.
            $searchLog = $this->reactResolver->searchLog();
            if ($searchLog !== []) {
                $this->sessionFs->writeSearchResults($state['paths']['root'], $searchLog);
            }

            if (!$resolvedDomain) {
                $state['error'] = 'no_site_found';
                $state['details'] = 'Could not resolve domain for company';
                return;
            }

            $this->memory->storeDomain($company, $resolvedDomain);

            $state['domain'] = $resolvedDomain;
            $state['resolved_via'] = $resolvedVia;

            if ($state['thinking']) {
                $state['events'][] = ['phase' => 'search.domain_resolved', 'domain' => $resolvedDomain, 'via' => $resolvedVia];
            }

            $newBasePath = $this->sessionFs->getAnalysisPath($state['session'], $resolvedDomain);
            $state['paths']['root'] = $newBasePath;
            $this->sessionFs->movePendingToResolved($state['session'], $resolvedDomain);
            $this->sessionFs->ensureDirectories($newBasePath);

            // Persist the ReAct trace under the resolved domain's logs (single write, post-move).
            if (!empty($state['react_trace'])) {
                $this->sessionFs->writeReactTrace($newBasePath, $state['react_trace']);
            }

            Log::info("Domain resolved for session {$state['session']}", [
                'company' => $company, 'resolved_domain' => $resolvedDomain, 'via' => $resolvedVia,
            ]);
        } catch (\Throwable $e) {
            $state['error'] = 'internal';
            $state['details'] = 'Domain resolution failed: ' . $e->getMessage();
            Log::error('Domain resolution failed', [
                'session' => $state['session'],
                'company' => $state['company'],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Verify a heuristic-resolved domain actually belongs to the target company
     * before committing to it. The heuristic search has no company-match check,
     * so it can surface a wrong domain (e.g. an apartment association -> lrs.lt);
     * we fetch the homepage and let CompanyMatchJudge reject a definite mismatch.
     * Fail-open: a disabled judge, no target facts, or any fetch/judge error
     * accepts the candidate, leaving the post-profile judge as the backstop.
     *
     * @param array<string, mixed> $targetFacts
     */
    private function heuristicCandidatePasses(string $domain, array $targetFacts): bool
    {
        if ($targetFacts === [] || !config('react.match_judge.enabled', true)) {
            return true;
        }

        try {
            $res = $this->runnerClient->run([
                'version' => '1.0',
                'meta' => ['task' => 'verify candidate', 'company' => 'verify', 'domain' => $domain],
                'actions' => [
                    ['do' => 'goto', 'url' => 'https://' . $domain, 'timeout_ms' => 20000],
                    ['do' => 'wait_for', 'selector' => 'body', 'timeout_ms' => 8000],
                    ['do' => 'extract_markdown', 'save_as' => 'content'],
                    ['do' => 'return', 'data' => ['content']],
                ],
            ]);

            if (empty($res['ok'])) {
                return true; // couldn't load the page — don't reject on a fetch failure
            }

            $verdict = $this->matchJudge->judge($targetFacts, [
                'domain' => $domain,
                'content' => (string) ($res['data']['content'] ?? ''),
            ]);

            if ($this->matchJudge->rejects($verdict)) {
                Log::info('Heuristic candidate rejected by company-match judge', [
                    'domain' => $domain,
                    'verdict' => $verdict['verdict'],
                    'match_confidence' => $verdict['match_confidence'],
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Heuristic candidate verification error (fail-open)', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }
}
