<?php

namespace App\Services;

use App\Tools\SessionFs;
use Illuminate\Support\Facades\Log;

/**
 * ProfileExtractionPipeline - post-run profile extraction phases for Step-1.
 *
 * Groups the three best-effort phases that turn a captured homepage into a
 * structured company profile, extracted from ReActLoopService so the loop only
 * orchestrates them:
 * - STRUCTURE_ANALYSIS: contacts, social links and company indicators (offline).
 * - SECONDARY_CRAWL: fetch a few key internal pages (About/Contact/Services).
 * - PROFILE_BUILD: aggregate homepage + secondary pages + structure into a profile.
 *
 * Each phase mutates the shared run state in place and never throws; a failure
 * is logged and the run continues.
 */
class ProfileExtractionPipeline
{
    public function __construct(
        private HtmlStructureAnalyzer $structureAnalyzer,
        private SecondaryCrawlService $secondaryCrawl,
        private CompanyProfileService $profileService,
        private CompanyMatchJudge $matchJudge,
        private SessionFs $sessionFs,
    ) {}

    /**
     * Phase 6.6: STRUCTURE_ANALYSIS - Extract contacts, social links and company
     * indicators from the captured homepage HTML (deterministic, offline).
     *
     * @param array<string, mixed> $state
     */
    public function analyzeStructure(array &$state): void
    {
        $mainHtml = $state['runner']['data']['main_html'] ?? null;

        if (!is_string($mainHtml) || $mainHtml === '') {
            return;
        }

        try {
            $baseUrl = $state['domain'] ? "https://{$state['domain']}" : '';
            $structure = $this->structureAnalyzer->analyzeStructure($mainHtml, $baseUrl);

            $state['structure'] = $structure;
            $this->sessionFs->writeStructure($state['paths']['root'], $structure);

            if ($state['thinking']) {
                $state['events'][] = [
                    'phase' => 'structure.analyzed',
                    'company_indicators' => $structure['company_indicators'] ?? [],
                ];
            }

            $indicators = $structure['company_indicators'] ?? [];
            Log::info("Structure analyzed for session {$state['session']}", [
                'social_links' => count($indicators['social_links'] ?? []),
                'emails' => count($indicators['contact_info']['emails'] ?? []),
                'phones' => count($indicators['contact_info']['phones'] ?? []),
            ]);

        } catch (\Throwable $e) {
            // Structure analysis is best-effort; never fail the run because of it.
            Log::warning("Structure analysis failed for session {$state['session']}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Phase 6.65: SECONDARY_CRAWL - Fetch a few key internal pages
     * (About/Contact/Services) so the profile is built from more than the homepage.
     *
     * @param array<string, mixed> $state
     */
    public function crawlSecondary(array &$state): void
    {
        if (empty($state['structure']['important_links'])) {
            return;
        }

        try {
            $pages = $this->secondaryCrawl->crawl(
                $state['company'],
                $state['domain'],
                $state['structure']
            );

            $state['secondary_pages'] = $pages;

            if ($state['thinking']) {
                $state['events'][] = [
                    'phase' => 'secondary.crawled',
                    'urls' => array_column($pages, 'url'),
                ];
            }

            Log::info("Secondary crawl for session {$state['session']}", [
                'pages' => count($pages),
            ]);

        } catch (\Throwable $e) {
            // Secondary crawl is best-effort; never fail the run because of it.
            Log::warning("Secondary crawl failed for session {$state['session']}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Phase 6.7: PROFILE_BUILD - Aggregate the captured homepage + secondary
     * pages + structure analysis into a structured company profile (+ summary).
     *
     * @param array<string, mixed> $state
     */
    public function buildProfile(array &$state): void
    {
        $mainHtml = $state['runner']['data']['main_html'] ?? null;

        if (!is_string($mainHtml) || $mainHtml === '') {
            return;
        }

        try {
            $profile = $this->profileService->build(
                $state['company'],
                $state['domain'],
                $mainHtml,
                $state['structure'] ?? [],
                $state['secondary_pages'] ?? [],
                $state['runner']['data']['main_md'] ?? null,
                is_array($state['options']['declared_fields'] ?? null) ? $state['options']['declared_fields'] : [],
            );

            // Score how well the built profile matches the target company (and how
            // complete it is) before persisting, so the quality verdict travels with
            // profile.json. Fail-open: the judge never throws.
            $profile['quality'] = $this->matchJudge->judge($state['target_facts'] ?? ['name' => $state['company'], 'country' => 'LT'], [
                'domain' => $state['domain'],
                'content' => $state['runner']['data']['main_md'] ?? $mainHtml,
                'profile' => $profile,
            ]);

            $state['profile'] = $profile;
            $this->sessionFs->writeProfile($state['paths']['root'], $profile);
            $this->sessionFs->writeSummary($state['paths']['root'], $this->profileService->renderSummary($profile));

            if ($state['thinking']) {
                $state['events'][] = ['phase' => 'profile.built', 'name' => $profile['name'] ?? null];
            }

            Log::info("Company profile built for session {$state['session']}", [
                'name' => $profile['name'] ?? null,
                'emails' => count($profile['emails'] ?? []),
                'social_links' => count($profile['social_links'] ?? []),
            ]);

        } catch (\Throwable $e) {
            // Profile building is best-effort; never fail the run because of it.
            Log::warning("Profile build failed for session {$state['session']}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
