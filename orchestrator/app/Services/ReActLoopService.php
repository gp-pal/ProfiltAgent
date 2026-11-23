<?php

namespace App\Services;

use App\IO\RunnerClient;
use App\Tools\SessionFs;
use Illuminate\Support\Facades\Log;

/**
 * ReActLoopService - First-layer orchestrator for Step-1 Bootstrap & Discovery
 *
 * Implements a deterministic state machine to complete Step-1 for {company, domain?, session}:
 * - Check existing assets (short-circuit if done)
 * - Resolve domain if missing (LT-biased search)
 * - Generate minimal homepage capture plan
 * - Validate plan (with one repair attempt)
 * - Execute via runner or dry-run
 * - Persist artifacts and determine satisfaction
 */
class ReActLoopService
{
    public function __construct(
        private PlanBuilder $planBuilder,
        private RunnerClient $runnerClient,
        private SessionFs $sessionFs,
        private LinkExtractor $linkExtractor,
        private ProfileExtractionPipeline $profileExtractor,
        private DomainResolutionService $domainResolver,
        private ?\App\Observability\LangfuseTracer $tracer = null,
    ) {
        $this->tracer = $tracer ?? app(\App\Observability\LangfuseTracer::class);
    }

    /**
     * Main entry point for Step-1 bootstrap process
     *
     * @param string $company Company name
     * @param string|null $domain Domain or null if unknown
     * @param string $session Session ULID/UUID
     * @param array $options ['thinking' => bool, 'dryRun' => bool, 'maxSteps' => int|null]
     *
     * @return array Complete result object for controller
     */
    public function runStep1Bootstrap(string $company, ?string $domain, string $session, array $options = []): array
    {
        $thinking = $options['thinking'] ?? false;
        $dryRun = $options['dryRun'] ?? false;

        $state = $this->initializeState($company, $domain, $session, $thinking);
        $state['target_facts'] = $this->resolveTargetFacts($company, $options['targetFacts'] ?? []);
        $state['options'] = $options;
        $lockCreated = $this->sessionFs->createLock($session, $domain ?: 'pending');

        if (!$lockCreated) {
            return $this->buildErrorResult('internal', 'Another run is already in progress for this session/domain');
        }

        try {
            $this->executeCheckAssets($state);

            if (!$state['done'] && !$state['error']) {
                $span = $this->tracer->span('resolve-domain', ['company' => $state['company']]);
                try {
                    $this->domainResolver->resolve($state);
                } finally {
                    $span->end(['domain' => $state['domain'] ?? null, 'via' => $state['resolved_via'] ?? null]);
                }
            }

            if (!$state['done'] && !$state['error']) {
                $this->planBuilder->build($state);
            }

            if (!$state['done'] && !$state['error']) {
                $this->planBuilder->validate($state);
            }

            if (!$state['done'] && !$state['error']) {
                $this->executeExtractionOrRun($state, $dryRun);
            }

            if (!$state['done'] && !$state['error'] && !$dryRun) {
                $this->executePersist($state);
            }

            if (!$state['error'] && !$dryRun && $state['domain']) {
                $this->executeLinksProcessing($state);
            }

            if (!$state['error'] && !$dryRun) {
                $span = $this->tracer->span('structure-analysis', ['company' => $state['company']]);
                try {
                    $this->profileExtractor->analyzeStructure($state);
                } finally {
                    $span->end(['structure' => $state['structure'] ?? null]);
                }
            }

            if (!$state['error'] && !$dryRun && $state['domain']) {
                $span = $this->tracer->span('secondary-crawl', ['company' => $state['company'], 'domain' => $state['domain']]);
                try {
                    $this->profileExtractor->crawlSecondary($state);
                } finally {
                    $span->end(['pages' => count($state['secondary_pages'] ?? [])]);
                }
            }

            if (!$state['error'] && !$dryRun) {
                $span = $this->tracer->span('profile-build', ['company' => $state['company']]);
                try {
                    $this->profileExtractor->buildProfile($state);
                } finally {
                    $span->end(['profile_name' => $state['profile']['name'] ?? null]);
                }
            }

            if (!$state['error']) {
                $this->executeSatisfaction($state, $dryRun);
            }

        } finally {
            // Always release lock
            $this->sessionFs->releaseLock($session, $state['domain'] ?: 'pending');
        }

        // Build final result
        return $this->buildFinalResult($state);
    }

    /**
     * Initialize the minimal state object
     */
    private function initializeState(string $company, ?string $domain, string $session, bool $thinking): array
    {
        $domainSlug = $domain ? $this->sessionFs->createDomainSlug($domain) : 'pending';
        $basePath = "runs/{$session}/{$domainSlug}/analyze";

        return [
            'company' => $company,
            'domain' => $domain,
            'session' => $session,
            'paths' => [
                'root' => $basePath
            ],
            'plan' => null,
            'runner' => null,
            'events' => [],
            'trace' => null,
            'error' => null,
            'details' => null,
            'done' => false,
            'thinking' => $thinking,
            'links_summary' => null,
            'structure' => null,
            'secondary_pages' => [],
            'profile' => null,
            'resolved_via' => null,
            'react_trace' => null,
            'target_facts' => [],
            'options' => [],
        ];
    }

    /**
     * Merge caller-supplied target facts (enrichment passes name/code/city/etc.)
     * over the defaults every run needs: the company name and country=LT. Blank
     * values are dropped so they don't crowd out a default.
     *
     * @param array<string, mixed> $provided
     * @return array<string, mixed>
     */
    private function resolveTargetFacts(string $company, array $provided): array
    {
        $facts = array_filter($provided, static fn ($v): bool => $v !== null && $v !== '');
        $facts['name'] = $facts['name'] ?? $company;
        $facts['country'] = $facts['country'] ?? 'LT';

        return $facts;
    }

    /**
     * Phase 1: CHECK_ASSETS - Short-circuit if Step-1 already satisfied
     */
    private function executeCheckAssets(array &$state): void
    {
        $basePath = $state['paths']['root'];

        // Ensure directories exist
        $this->sessionFs->ensureDirectories($basePath);

        // Check if Step-1 already satisfied
        $satisfied = $this->sessionFs->isStep1Satisfied($basePath);

        if ($state['thinking']) {
            $state['events'][] = ['phase' => 'check_assets', 'exists' => $satisfied];
        }

        if ($satisfied) {
            $state['done'] = true;
            // Rehydrate the profile written by the original run; otherwise a
            // cache hit short-circuits before profile-build and reports a
            // spurious null even though profile.json exists on disk.
            $state['profile'] = $this->sessionFs->readProfile($basePath);
            Log::info("Step-1 already satisfied for session {$state['session']}", [
                'company' => $state['company'],
                'domain' => $state['domain'],
                'profile_rehydrated' => $state['profile'] !== null,
            ]);
        }
    }

    /**
     * Phase 5: EXTRACTION_OR_RUN - Execute plan using traditional runner
     */
    private function executeExtractionOrRun(array &$state, bool $dryRun): void
    {
        if ($dryRun) {
            $state['runner'] = null;
            if ($state['thinking']) {
                $state['events'][] = ['phase' => 'extraction.skipped'];
            }
            return;
        }

        try {
            $this->executeRunOrDryRun($state, $dryRun);

        } catch (\Exception $e) {
            $state['error'] = 'internal';
            $state['details'] = 'Execution failed: ' . $e->getMessage();
            Log::error("Execution failed", [
                'session' => $state['session'],
                'error' => $e->getMessage()
            ]);
        }
    }



    /**
     * Phase 5: RUN_OR_DRY_RUN - Execute plan or skip for dry run
     */
    private function executeRunOrDryRun(array &$state, bool $dryRun): void
    {
        if ($dryRun) {
            $state['runner'] = null;
            if ($state['thinking']) {
                $state['events'][] = ['phase' => 'runner.skipped'];
            }
            return;
        }

        try {
            if ($state['thinking']) {
                $state['events'][] = ['phase' => 'runner.start'];
            }

            $runnerResult = $this->runnerClient->run($state['plan']);

            if (!$runnerResult['ok']) {
                if ($this->isBotChallenge($runnerResult)) {
                    $state['error'] = 'challenge_detected';
                    $state['details'] = $runnerResult['error'];
                } else {
                    $state['error'] = 'runner_failed';
                    $state['details'] = [
                        'error' => $runnerResult['error'],
                        'logs' => $runnerResult['logs'] ?? []
                    ];
                }
                return;
            }

            $state['runner'] = $runnerResult;

            if ($state['thinking']) {
                $state['events'][] = ['phase' => 'runner.done', 'ok' => true];
            }

            Log::info("Runner execution completed for session {$state['session']}", [
                'artifacts_count' => count($runnerResult['artifacts'] ?? [])
            ]);

        } catch (\Exception $e) {
            $state['error'] = 'internal';
            $state['details'] = 'Runner execution failed: ' . $e->getMessage();
            Log::error("Runner execution failed", [
                'session' => $state['session'],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Phase 6: PERSIST - Save artifacts to filesystem
     */
    private function executePersist(array &$state): void
    {
        if (!$state['runner']) {
            return;
        }

        try {
            $basePath = $state['paths']['root'];
            $data = $state['runner']['data'] ?? [];

            // Write main HTML
            if (isset($data['main_html'])) {
                $this->sessionFs->writeMainHtml($basePath, $data['main_html']);
            }

            // Write main markdown (runner-side HTML→markdown conversion)
            if (isset($data['main_md']) && is_string($data['main_md']) && $data['main_md'] !== '') {
                $this->sessionFs->writeMainMarkdown($basePath, $data['main_md']);
            }

            // Write iframes
            if (isset($data['iframes_html']) && is_array($data['iframes_html'])) {
                $this->sessionFs->writeIframes($basePath, $data['iframes_html']);
            }

            // Append runner logs
            if (!empty($state['runner']['logs'])) {
                $this->sessionFs->appendRunnerLogs($basePath, $state['runner']['logs']);
            }

            if ($state['thinking']) {
                $state['events'][] = ['phase' => 'persist.done'];
            }

            Log::info("Artifacts persisted for session {$state['session']}");

        } catch (\Exception $e) {
            $state['error'] = 'internal';
            $state['details'] = 'Artifact persistence failed: ' . $e->getMessage();
            Log::error("Artifact persistence failed", [
                'session' => $state['session'],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Phase 7: SATISFACTION - Check if Step-1 is satisfied
     */
    private function executeSatisfaction(array &$state, bool $dryRun): void
    {
        if ($dryRun) {
            // In dry run, only satisfied if assets already existed
            $state['done'] = $this->sessionFs->isStep1Satisfied($state['paths']['root']);
        } else {
            // For regular runs, check satisfaction after persistence
            $state['done'] = $this->sessionFs->isStep1Satisfied($state['paths']['root']);
        }

        if ($state['thinking']) {
            $state['events'][] = ['phase' => 'done', 'value' => $state['done']];
        }

        Log::info("Step-1 satisfaction check for session {$state['session']}", [
            'done' => $state['done'],
            'dry_run' => $dryRun
        ]);
    }

    /**
     * Phase 6.5: LINKS_PROCESSING - Offline deterministic link extraction
     */
    private function executeLinksProcessing(array &$state): void
    {
        try {
            $basePath = 'app/private/' . $state['paths']['root'];
            $domain = $state['domain'];

            if (isset($state['runner']['data']['links_raw'])) {
                $rawLinksPath = storage_path("{$basePath}/links_raw.json");

                $directory = dirname($rawLinksPath);

                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
                }

                file_put_contents($rawLinksPath, json_encode($state['runner']['data']['links_raw'], JSON_PRETTY_PRINT));
            }

            // Process links using the LinkExtractor
            $linkResult = $this->linkExtractor->extractAndProcess($basePath, $domain);

            $state['links_summary'] = $linkResult['summary'];

            if ($state['thinking']) {
                $state['events'][] = ['phase' => 'links.processed', 'summary' => $linkResult['summary']];
            }

            Log::info("Links processed for session {$state['session']}", [
                'total_links' => $linkResult['summary']['total_links'],
                'unique_links' => $linkResult['summary']['unique_links'],
                'unique_domains' => $linkResult['summary']['unique_domains']
            ]);

        } catch (\Throwable $e) {
            Log::warning("Links processing failed for session {$state['session']}", [
                'error' => $e->getMessage()
            ]);
            // Don't fail the entire process if links processing fails
            $state['links_summary'] = [
                'total_links' => 0,
                'unique_links' => 0,
                'by_category' => [],
                'unique_domains' => 0
            ];
        }
    }

    /**
     * Build final result object for controller
     */
    private function buildFinalResult(array $state): array
    {
        $result = [
            'ok' => !$state['error'],
            'done' => $state['done'],
            'session' => $state['session'],
            'domain' => $state['domain'],
            'resolved_via' => $state['resolved_via'] ?? null,
            'paths' => $this->sessionFs->getPaths($state['paths']['root']),
            'links_summary' => $state['links_summary'],
            'structure' => $state['structure'],
            'profile' => $state['profile'],
            'plan' => $state['plan'],
            'runner' => $state['runner'],
            'trace' => $state['trace'] ?? $this->getDefaultTrace(),
            'events' => $state['events'],
            'error' => $state['error'],
            'details' => $state['details']
        ];

        if ($state['thinking']) {
            $result['react_trace'] = $state['react_trace'] ?? null;
        }

        return $result;
    }

    /**
     * Build error result
     */
    private function buildErrorResult(string $error, string $message): array
    {
        return [
            'ok' => false,
            'done' => false,
            'session' => null,
            'domain' => null,
            'paths' => null,
            'plan' => null,
            'runner' => null,
            'trace' => $this->getDefaultTrace(),
            'events' => [],
            'error' => $error,
            'details' => $message
        ];
    }

    /**
     * Check if runner error indicates bot challenge
     */
    private function isBotChallenge(array $runnerResult): bool
    {
        $error = strtolower($runnerResult['error'] ?? '');
        $challengeIndicators = [
            'cloudflare', 'turnstile', 'hcaptcha', 'recaptcha',
            'bot challenge', 'challenge detected', 'security check'
        ];

        foreach ($challengeIndicators as $indicator) {
            if (strpos($error, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get default trace when PromptBuilderService trace is not available
     */
    private function getDefaultTrace(): array
    {
        return [
            'policy_caps' => [],
            'schema' => [
                'sha256' => null,
                'excerpt_bytes' => 0,
                'path' => null
            ],
            'prompts' => []
        ];
    }

}