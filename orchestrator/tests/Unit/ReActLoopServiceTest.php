<?php

namespace Tests\Unit;

use App\IO\Contracts\LlmClient;
use App\IO\RunnerClient;
use App\Services\CompanyMatchJudge;
use App\Services\CompanyProfileService;
use App\Services\DomainResolutionService;
use App\Services\HtmlStructureAnalyzer;
use App\Services\LinkExtractor;
use App\Services\Memory;
use App\Services\PlanBuilder;
use App\Services\ProfileExtractionPipeline;
use App\Services\PromptBuilderService;
use App\Services\ReActDomainResolver;
use App\Services\ReActLoopService;
use App\Services\SecondaryCrawlService;
use App\Tools\SearchDdgService;
use App\Tools\SessionFs;
use App\Validation\PolicyChecks;
use App\Validation\SchemaValidator;
use Mockery;
use Tests\TestCase;

class ReActLoopServiceTest extends TestCase
{
    private ReActLoopService $service;
    private $promptBuilder;
    private $ollamaClient;
    private $searchService;
    private $schemaValidator;
    private $policyChecks;
    private $runnerClient;
    private $sessionFs;
    private $linkExtractor;
    private $structureAnalyzer;
    private $profileService;
    private $secondaryCrawl;
    private $reactResolver;
    private $memory;
    private $matchJudge;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks
        $this->promptBuilder = Mockery::mock(PromptBuilderService::class);
        $this->ollamaClient = Mockery::mock(LlmClient::class);
        $this->searchService = Mockery::mock(SearchDdgService::class);
        $this->schemaValidator = Mockery::mock(SchemaValidator::class);
        $this->policyChecks = Mockery::mock(PolicyChecks::class);
        $this->runnerClient = Mockery::mock(RunnerClient::class);
        $this->sessionFs = Mockery::mock(SessionFs::class);
        $this->linkExtractor = Mockery::mock(LinkExtractor::class);
        $this->structureAnalyzer = Mockery::mock(HtmlStructureAnalyzer::class);
        $this->profileService = Mockery::mock(CompanyProfileService::class);
        $this->secondaryCrawl = Mockery::mock(SecondaryCrawlService::class);
        $this->reactResolver = Mockery::mock(ReActDomainResolver::class);
        $this->memory = Mockery::mock(Memory::class);
        $this->matchJudge = Mockery::mock(CompanyMatchJudge::class);

        // Profile quality scoring runs on the full pipeline; default to a pass so
        // individual tests need only override when asserting the quality block.
        $this->matchJudge->shouldReceive('judge')->andReturn([
            'verdict' => 'pass',
            'match_confidence' => 0.9,
            'data_coverage' => 0.8,
            'reasons' => [],
        ])->byDefault();

        // Mirror the real judge: only a 'fail' verdict rejects.
        $this->matchJudge->shouldReceive('rejects')->andReturnUsing(
            static fn (array $r): bool => ($r['verdict'] ?? 'warn') === 'fail'
        )->byDefault();

        // Domain resolver exposes its per-query search log after resolve(); default
        // to empty so the search-results persistence is a no-op unless overridden.
        $this->reactResolver->shouldReceive('searchLog')->andReturn([])->byDefault();

        // Infrastructure helper used by every run when a domain is known.
        $this->sessionFs->shouldReceive('createDomainSlug')
            ->andReturnUsing(fn (string $domain): string => str_replace('.', '_', $domain));

        // The post-run profile phases were extracted into a dedicated pipeline;
        // build a REAL one over the existing leaf mocks so their expectations are
        // still exercised exactly as before.
        $profileExtractor = new ProfileExtractionPipeline(
            $this->structureAnalyzer,
            $this->secondaryCrawl,
            $this->profileService,
            $this->matchJudge,
            $this->sessionFs
        );

        // The domain-resolution phase was extracted into a dedicated service;
        // build a REAL one over the existing leaf mocks so their expectations are
        // still exercised exactly as before.
        $domainResolver = new DomainResolutionService(
            $this->memory,
            $this->reactResolver,
            $this->searchService,
            $this->runnerClient,
            $this->matchJudge,
            $this->sessionFs
        );

        // The plan build + validate phases were extracted into a dedicated
        // builder; build a REAL one over the existing leaf mocks so their
        // expectations are still exercised exactly as before.
        $planBuilder = new PlanBuilder(
            $this->promptBuilder,
            $this->ollamaClient,
            $this->schemaValidator,
            $this->policyChecks,
            $this->sessionFs
        );

        $this->service = new ReActLoopService(
            $planBuilder,
            $this->runnerClient,
            $this->sessionFs,
            $this->linkExtractor,
            $profileExtractor,
            $domainResolver
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_asset_short_circuit_when_step1_already_satisfied(): void
    {
        // Pre-seed with existing assets
        $this->sessionFs->shouldReceive('createLock')->with('test-session', 'example.com')->andReturn(true);
        $this->sessionFs->shouldReceive('ensureDirectories')->once();
        $this->sessionFs->shouldReceive('isStep1Satisfied')->andReturn(true);
        // A cache hit must rehydrate the profile from disk (regression: it used
        // to short-circuit before profile-build and report a spurious null).
        $this->sessionFs->shouldReceive('readProfile')->once()->andReturn([
            'name' => 'Test Company',
            'domain' => 'example.com',
            'emails' => ['info@example.com'],
        ]);
        $this->sessionFs->shouldReceive('getPaths')->andReturn([
            'main_html' => '/path/to/main.html',
            'screenshots_dir' => '/path/to/screenshots'
        ]);
        $this->sessionFs->shouldReceive('releaseLock')->once();

        $result = $this->service->runStep1Bootstrap('Test Company', 'example.com', 'test-session');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['done']);
        $this->assertEquals('test-session', $result['session']);
        $this->assertEquals('example.com', $result['domain']);
        $this->assertNull($result['plan']);
        $this->assertNull($result['runner']);
        $this->assertSame('Test Company', $result['profile']['name']);
        $this->assertSame(['info@example.com'], $result['profile']['emails']);
    }

    public function test_domain_resolution_path_when_domain_missing(): void
    {
        $this->sessionFs->shouldReceive('createLock')->with('test-session', 'pending')->andReturn(true);
        $this->sessionFs->shouldReceive('ensureDirectories')->twice();
        $this->sessionFs->shouldReceive('isStep1Satisfied')->andReturn(false);
        $this->sessionFs->shouldReceive('getAnalysisPath')->with('test-session', 'resolved.com')->andReturn('runs/test-session/resolved_com/analyze');
        $this->sessionFs->shouldReceive('movePendingToResolved')->with('test-session', 'resolved.com');

        // Mock domain resolution: no cache hit, ReAct resolver defers, heuristic wins.
        $this->memory->shouldReceive('getDomain')->with('Test Company')->andReturn(null);
        $this->reactResolver->shouldReceive('resolve')->andReturn(null);
        $this->memory->shouldReceive('storeDomain')->with('Test Company', 'resolved.com');
        $this->searchService->shouldReceive('findOfficialSite')->with('Test Company')->andReturn('resolved.com');

        // Mock plan building
        $this->promptBuilder->shouldReceive('makeBootstrapPlanMessages')
            ->with('Test Company', 'resolved.com', 'test-session')
            ->andReturn([
                'messages' => [
                    ['role' => 'system', 'content' => 'system prompt'],
                    // Step-1 plan is a deterministic template rendered into the user message
                    ['role' => 'user', 'content' => json_encode([
                        'version' => '1.0',
                        'meta' => ['task' => 'Step-1', 'company' => 'Test Company', 'domain' => 'resolved.com'],
                        'actions' => [
                            ['do' => 'goto', 'url' => 'https://resolved.com'],
                            ['do' => 'return', 'data' => ['main_html']],
                        ],
                    ])],
                ],
                'trace' => ['policy_caps' => []]
            ]);

        // Mock validation
        $this->schemaValidator->shouldReceive('validate')->once();
        $this->policyChecks->shouldReceive('check')->once();

        // Mock plan persistence
        $this->sessionFs->shouldReceive('writePlan');

        // Mock runner execution
        $this->runnerClient->shouldReceive('run')->andReturn([
            'ok' => true,
            'data' => ['main_html' => '<html>test</html>'],
            'artifacts' => [],
            'logs' => []
        ]);

        // Mock artifact persistence
        $this->sessionFs->shouldReceive('writeMainHtml');
        $this->sessionFs->shouldReceive('writeIframes');
        $this->sessionFs->shouldReceive('appendRunnerLogs');

        // Mock links processing
        $this->linkExtractor->shouldReceive('extractAndProcess')->andReturn([
            'summary' => [
                'total_links' => 0,
                'unique_links' => 0,
                'unique_domains' => 0,
                'by_category' => [],
            ],
        ]);

        // Mock structure analysis (contacts + social links from homepage HTML)
        $this->structureAnalyzer->shouldReceive('analyzeStructure')->andReturn([
            'company_indicators' => [
                'contact_info' => ['emails' => ['info@resolved.com'], 'phones' => []],
                'social_links' => ['https://facebook.com/resolved', 'https://linkedin.com/company/resolved'],
            ],
            'important_links' => ['about' => [['url' => 'https://resolved.com/about', 'text' => 'about']]],
        ]);
        $this->sessionFs->shouldReceive('writeStructure');

        // Mock secondary crawl (Step-2)
        $this->secondaryCrawl->shouldReceive('crawl')->andReturn([
            ['url' => 'https://resolved.com/about', 'text' => 'Apie mus'],
        ]);

        // Mock company profile build (structured profile + summary)
        $this->profileService->shouldReceive('build')->andReturn([
            'name' => 'Test Company',
            'domain' => 'resolved.com',
            'emails' => ['info@resolved.com'],
            'social_links' => ['https://linkedin.com/company/resolved'],
        ]);
        $this->profileService->shouldReceive('renderSummary')->andReturn('# Test Company');
        $this->sessionFs->shouldReceive('writeProfile');
        $this->sessionFs->shouldReceive('writeSummary');

        // Final satisfaction check
        $this->sessionFs->shouldReceive('isStep1Satisfied')->andReturn(true);
        $this->sessionFs->shouldReceive('getPaths')->andReturn([]);
        $this->sessionFs->shouldReceive('releaseLock')->once();

        $result = $this->service->runStep1Bootstrap('Test Company', null, 'test-session');

        $this->assertTrue($result['ok']);
        $this->assertEquals('resolved.com', $result['domain']);
        $this->assertSame('heuristic', $result['resolved_via']);

        // Structure analysis is wired into the result (contacts + social links)
        $this->assertArrayHasKey('structure', $result);
        $this->assertContains(
            'https://linkedin.com/company/resolved',
            $result['structure']['company_indicators']['social_links']
        );

        // Structured company profile is wired into the result
        $this->assertArrayHasKey('profile', $result);
        $this->assertSame('Test Company', $result['profile']['name']);

        // Company-match quality verdict is attached to the profile.
        $this->assertSame('pass', $result['profile']['quality']['verdict']);
    }

    public function test_react_resolver_exception_falls_back_to_heuristic(): void
    {
        $this->sessionFs->shouldReceive('createLock')->with('test-session', 'pending')->andReturn(true);
        $this->sessionFs->shouldReceive('ensureDirectories')->twice();
        $this->sessionFs->shouldReceive('isStep1Satisfied')->andReturn(false);
        $this->sessionFs->shouldReceive('getAnalysisPath')->with('test-session', 'resolved.com')->andReturn('runs/test-session/resolved_com/analyze');
        $this->sessionFs->shouldReceive('movePendingToResolved')->with('test-session', 'resolved.com');

        // Mock domain resolution: no cache hit, ReAct resolver THROWS, heuristic wins.
        $this->memory->shouldReceive('getDomain')->with('Test Company')->andReturn(null);
        $this->reactResolver->shouldReceive('resolve')->andThrow(new \RuntimeException('boom'));
        $this->memory->shouldReceive('storeDomain')->with('Test Company', 'resolved.com');
        $this->searchService->shouldReceive('findOfficialSite')->with('Test Company')->andReturn('resolved.com');

        // Mock plan building
        $this->promptBuilder->shouldReceive('makeBootstrapPlanMessages')
            ->with('Test Company', 'resolved.com', 'test-session')
            ->andReturn([
                'messages' => [
                    ['role' => 'system', 'content' => 'system prompt'],
                    // Step-1 plan is a deterministic template rendered into the user message
                    ['role' => 'user', 'content' => json_encode([
                        'version' => '1.0',
                        'meta' => ['task' => 'Step-1', 'company' => 'Test Company', 'domain' => 'resolved.com'],
                        'actions' => [
                            ['do' => 'goto', 'url' => 'https://resolved.com'],
                            ['do' => 'return', 'data' => ['main_html']],
                        ],
                    ])],
                ],
                'trace' => ['policy_caps' => []]
            ]);

        // Mock validation
        $this->schemaValidator->shouldReceive('validate')->once();
        $this->policyChecks->shouldReceive('check')->once();

        // Mock plan persistence
        $this->sessionFs->shouldReceive('writePlan');

        // Mock runner execution
        $this->runnerClient->shouldReceive('run')->andReturn([
            'ok' => true,
            'data' => ['main_html' => '<html>test</html>'],
            'artifacts' => [],
            'logs' => []
        ]);

        // Mock artifact persistence
        $this->sessionFs->shouldReceive('writeMainHtml');
        $this->sessionFs->shouldReceive('writeIframes');
        $this->sessionFs->shouldReceive('appendRunnerLogs');

        // Mock links processing
        $this->linkExtractor->shouldReceive('extractAndProcess')->andReturn([
            'summary' => [
                'total_links' => 0,
                'unique_links' => 0,
                'unique_domains' => 0,
                'by_category' => [],
            ],
        ]);

        // Mock structure analysis (contacts + social links from homepage HTML)
        $this->structureAnalyzer->shouldReceive('analyzeStructure')->andReturn([
            'company_indicators' => [
                'contact_info' => ['emails' => ['info@resolved.com'], 'phones' => []],
                'social_links' => ['https://facebook.com/resolved', 'https://linkedin.com/company/resolved'],
            ],
            'important_links' => ['about' => [['url' => 'https://resolved.com/about', 'text' => 'about']]],
        ]);
        $this->sessionFs->shouldReceive('writeStructure');

        // Mock secondary crawl (Step-2)
        $this->secondaryCrawl->shouldReceive('crawl')->andReturn([
            ['url' => 'https://resolved.com/about', 'text' => 'Apie mus'],
        ]);

        // Mock company profile build (structured profile + summary)
        $this->profileService->shouldReceive('build')->andReturn([
            'name' => 'Test Company',
            'domain' => 'resolved.com',
            'emails' => ['info@resolved.com'],
            'social_links' => ['https://linkedin.com/company/resolved'],
        ]);
        $this->profileService->shouldReceive('renderSummary')->andReturn('# Test Company');
        $this->sessionFs->shouldReceive('writeProfile');
        $this->sessionFs->shouldReceive('writeSummary');

        // Final satisfaction check
        $this->sessionFs->shouldReceive('isStep1Satisfied')->andReturn(true);
        $this->sessionFs->shouldReceive('getPaths')->andReturn([]);
        $this->sessionFs->shouldReceive('releaseLock')->once();

        // No exception should escape runStep1Bootstrap; the ReAct throw must be
        // swallowed by the inner catch and fall through to the heuristic resolver.
        $result = $this->service->runStep1Bootstrap('Test Company', null, 'test-session');

        $this->assertTrue($result['ok']);
        $this->assertSame('resolved.com', $result['domain']);
        $this->assertSame('heuristic', $result['resolved_via']);
    }

    public function test_cache_hit_short_circuits_resolution(): void
    {
        $this->sessionFs->shouldReceive('createLock')->with('test-session', 'pending')->andReturn(true);
        $this->sessionFs->shouldReceive('ensureDirectories')->twice();
        $this->sessionFs->shouldReceive('isStep1Satisfied')->andReturn(false);
        $this->sessionFs->shouldReceive('getAnalysisPath')->with('test-session', 'cached.com')->andReturn('runs/test-session/cached_com/analyze');
        $this->sessionFs->shouldReceive('movePendingToResolved')->with('test-session', 'cached.com');

        // Mock domain resolution: cache hit short-circuits ReAct and heuristic entirely.
        $this->memory->shouldReceive('getDomain')->with('Test Company')->andReturn('cached.com');
        $this->reactResolver->shouldNotReceive('resolve');
        $this->searchService->shouldNotReceive('findOfficialSite');
        $this->memory->shouldReceive('storeDomain')->with('Test Company', 'cached.com');

        // Mock plan building
        $this->promptBuilder->shouldReceive('makeBootstrapPlanMessages')
            ->with('Test Company', 'cached.com', 'test-session')
            ->andReturn([
                'messages' => [
                    ['role' => 'system', 'content' => 'system prompt'],
                    ['role' => 'user', 'content' => json_encode([
                        'version' => '1.0',
                        'meta' => ['task' => 'Step-1', 'company' => 'Test Company', 'domain' => 'cached.com'],
                        'actions' => [
                            ['do' => 'goto', 'url' => 'https://cached.com'],
                            ['do' => 'return', 'data' => ['main_html']],
                        ],
                    ])],
                ],
                'trace' => ['policy_caps' => []]
            ]);

        // Mock validation
        $this->schemaValidator->shouldReceive('validate')->once();
        $this->policyChecks->shouldReceive('check')->once();

        // Mock plan persistence
        $this->sessionFs->shouldReceive('writePlan');

        // Mock runner execution
        $this->runnerClient->shouldReceive('run')->andReturn([
            'ok' => true,
            'data' => ['main_html' => '<html>test</html>'],
            'artifacts' => [],
            'logs' => []
        ]);

        // Mock artifact persistence
        $this->sessionFs->shouldReceive('writeMainHtml');
        $this->sessionFs->shouldReceive('writeIframes');
        $this->sessionFs->shouldReceive('appendRunnerLogs');

        // Mock links processing
        $this->linkExtractor->shouldReceive('extractAndProcess')->andReturn([
            'summary' => [
                'total_links' => 0,
                'unique_links' => 0,
                'unique_domains' => 0,
                'by_category' => [],
            ],
        ]);

        // Mock structure analysis (contacts + social links from homepage HTML)
        $this->structureAnalyzer->shouldReceive('analyzeStructure')->andReturn([
            'company_indicators' => [
                'contact_info' => ['emails' => ['info@cached.com'], 'phones' => []],
                'social_links' => ['https://facebook.com/cached', 'https://linkedin.com/company/cached'],
            ],
            'important_links' => ['about' => [['url' => 'https://cached.com/about', 'text' => 'about']]],
        ]);
        $this->sessionFs->shouldReceive('writeStructure');

        // Mock secondary crawl (Step-2)
        $this->secondaryCrawl->shouldReceive('crawl')->andReturn([
            ['url' => 'https://cached.com/about', 'text' => 'Apie mus'],
        ]);

        // Mock company profile build (structured profile + summary)
        $this->profileService->shouldReceive('build')->andReturn([
            'name' => 'Test Company',
            'domain' => 'cached.com',
            'emails' => ['info@cached.com'],
            'social_links' => ['https://linkedin.com/company/cached'],
        ]);
        $this->profileService->shouldReceive('renderSummary')->andReturn('# Test Company');
        $this->sessionFs->shouldReceive('writeProfile');
        $this->sessionFs->shouldReceive('writeSummary');

        // Final satisfaction check
        $this->sessionFs->shouldReceive('isStep1Satisfied')->andReturn(true);
        $this->sessionFs->shouldReceive('getPaths')->andReturn([]);
        $this->sessionFs->shouldReceive('releaseLock')->once();

        $result = $this->service->runStep1Bootstrap('Test Company', null, 'test-session');

        $this->assertSame('cached.com', $result['domain']);
        $this->assertSame('cache', $result['resolved_via']);
    }

    public function test_declared_fields_option_reaches_profile_build(): void
    {
        // The caller's `declared_fields` option must survive onto $state
        // ($state['options'] = $options;) and reach
        // ProfileExtractionPipeline::buildProfile() -> CompanyProfileService::build()
        // as its 7th argument. Every other assertion in this class would still
        // pass if that plumbing were dropped or the key renamed -- this is the
        // only test that would catch it.
        $this->sessionFs->shouldReceive('createLock')->with('test-session', 'pending')->andReturn(true);
        $this->sessionFs->shouldReceive('ensureDirectories')->twice();
        $this->sessionFs->shouldReceive('isStep1Satisfied')->andReturn(false);
        $this->sessionFs->shouldReceive('getAnalysisPath')->with('test-session', 'cached.com')->andReturn('runs/test-session/cached_com/analyze');
        $this->sessionFs->shouldReceive('movePendingToResolved')->with('test-session', 'cached.com');

        $this->memory->shouldReceive('getDomain')->with('Test Company')->andReturn('cached.com');
        $this->reactResolver->shouldNotReceive('resolve');
        $this->searchService->shouldNotReceive('findOfficialSite');
        $this->memory->shouldReceive('storeDomain')->with('Test Company', 'cached.com');

        $this->promptBuilder->shouldReceive('makeBootstrapPlanMessages')
            ->with('Test Company', 'cached.com', 'test-session')
            ->andReturn([
                'messages' => [
                    ['role' => 'system', 'content' => 'system prompt'],
                    ['role' => 'user', 'content' => json_encode([
                        'version' => '1.0',
                        'meta' => ['task' => 'Step-1', 'company' => 'Test Company', 'domain' => 'cached.com'],
                        'actions' => [
                            ['do' => 'goto', 'url' => 'https://cached.com'],
                            ['do' => 'return', 'data' => ['main_html']],
                        ],
                    ])],
                ],
                'trace' => ['policy_caps' => []]
            ]);

        $this->schemaValidator->shouldReceive('validate')->once();
        $this->policyChecks->shouldReceive('check')->once();
        $this->sessionFs->shouldReceive('writePlan');

        $this->runnerClient->shouldReceive('run')->andReturn([
            'ok' => true,
            'data' => ['main_html' => '<html>test</html>'],
            'artifacts' => [],
            'logs' => []
        ]);

        $this->sessionFs->shouldReceive('writeMainHtml');
        $this->sessionFs->shouldReceive('writeIframes');
        $this->sessionFs->shouldReceive('appendRunnerLogs');

        $this->linkExtractor->shouldReceive('extractAndProcess')->andReturn([
            'summary' => [
                'total_links' => 0,
                'unique_links' => 0,
                'unique_domains' => 0,
                'by_category' => [],
            ],
        ]);

        $this->structureAnalyzer->shouldReceive('analyzeStructure')->andReturn([
            'company_indicators' => [
                'contact_info' => ['emails' => ['info@cached.com'], 'phones' => []],
                'social_links' => ['https://facebook.com/cached', 'https://linkedin.com/company/cached'],
            ],
            'important_links' => ['about' => [['url' => 'https://cached.com/about', 'text' => 'about']]],
        ]);
        $this->sessionFs->shouldReceive('writeStructure');

        $this->secondaryCrawl->shouldReceive('crawl')->andReturn([
            ['url' => 'https://cached.com/about', 'text' => 'Apie mus'],
        ]);

        $seenDeclared = null;
        $this->profileService->shouldReceive('build')->andReturnUsing(
            function (...$args) use (&$seenDeclared): array {
                $seenDeclared = $args[6] ?? null;

                return [
                    'name' => 'Test Company',
                    'domain' => 'cached.com',
                    'emails' => ['info@cached.com'],
                    'social_links' => ['https://linkedin.com/company/cached'],
                ];
            }
        );
        $this->profileService->shouldReceive('renderSummary')->andReturn('# Test Company');
        $this->sessionFs->shouldReceive('writeProfile');
        $this->sessionFs->shouldReceive('writeSummary');

        $this->sessionFs->shouldReceive('isStep1Satisfied')->andReturn(true);
        $this->sessionFs->shouldReceive('getPaths')->andReturn([]);
        $this->sessionFs->shouldReceive('releaseLock')->once();

        $this->service->runStep1Bootstrap('Test Company', null, 'test-session', [
            'declared_fields' => ['description', 'brands'],
        ]);

        $this->assertSame(['description', 'brands'], $seenDeclared);
    }

    public function test_heuristic_candidate_rejected_by_judge_yields_no_site_found(): void
    {
        $this->sessionFs->shouldReceive('createLock')->with('test-session', 'pending')->andReturn(true);
        $this->sessionFs->shouldReceive('ensureDirectories');
        $this->sessionFs->shouldReceive('isStep1Satisfied')->andReturn(false);
        $this->sessionFs->shouldReceive('getPaths')->andReturn([]);
        $this->sessionFs->shouldReceive('releaseLock')->once();

        // No cache; ReAct finds nothing; the heuristic proposes a wrong domain.
        $this->memory->shouldReceive('getDomain')->andReturn(null);
        $this->reactResolver->shouldReceive('resolve')->andReturn(null);
        $this->searchService->shouldReceive('findOfficialSite')->with('Test Company')->andReturn('wrong.com');
        $this->memory->shouldNotReceive('storeDomain');

        // The candidate is fetched once for verification...
        $this->runnerClient->shouldReceive('run')->once()->andReturn([
            'ok' => true,
            'data' => ['content' => 'This is a US company in Maryland'],
        ]);
        // ...and the judge rejects it, so resolution must NOT commit the domain.
        $this->matchJudge->shouldReceive('judge')->andReturn([
            'verdict' => 'fail',
            'match_confidence' => 0.05,
            'data_coverage' => 0.3,
            'reasons' => ['US company, not the Lithuanian target'],
        ]);

        $result = $this->service->runStep1Bootstrap('Test Company', null, 'test-session');

        $this->assertFalse($result['ok']);
        $this->assertSame('no_site_found', $result['error']);
        $this->assertNull($result['domain']);
    }

    public function test_challenge_detection(): void
    {
        $this->sessionFs->shouldReceive('createLock')->andReturn(true);
        $this->sessionFs->shouldReceive('ensureDirectories')->once();
        $this->sessionFs->shouldReceive('isStep1Satisfied')->andReturn(false);
        $this->sessionFs->shouldReceive('getPaths')->andReturn([]);
        $this->sessionFs->shouldReceive('releaseLock')->once();

        // Mock plan building and validation
        $this->promptBuilder->shouldReceive('makeBootstrapPlanMessages')->andReturn([
            'messages' => [
                ['role' => 'system', 'content' => 'system prompt'],
                ['role' => 'user', 'content' => json_encode([
                    'version' => '1.0',
                    'meta' => ['task' => 'Step-1', 'company' => 'Test Company'],
                    'actions' => [['do' => 'goto', 'url' => 'https://example.com'], ['do' => 'return', 'data' => ['main_html']]],
                ])],
            ],
            'trace' => [],
        ]);
        $this->ollamaClient->shouldReceive('withFallback')->andReturn([
            'choices' => [['message' => ['content' => json_encode(['version' => '2.0', 'meta' => [], 'actions' => []])]]]
        ]);
        $this->sessionFs->shouldReceive('writePlan');
        $this->schemaValidator->shouldReceive('validate');
        $this->policyChecks->shouldReceive('check');

        // Mock runner returning bot challenge
        $this->runnerClient->shouldReceive('run')->andReturn([
            'ok' => false,
            'error' => 'Cloudflare challenge detected - please solve CAPTCHA',
            'logs' => []
        ]);

        $result = $this->service->runStep1Bootstrap('Test Company', 'example.com', 'test-session');

        $this->assertFalse($result['ok']);
        $this->assertEquals('challenge_detected', $result['error']);
        $this->assertStringContainsString('Cloudflare', $result['details']);
    }

    public function test_dry_run_mode(): void
    {
        $this->sessionFs->shouldReceive('createLock')->andReturn(true);
        $this->sessionFs->shouldReceive('ensureDirectories')->once();
        $this->sessionFs->shouldReceive('isStep1Satisfied')->andReturn(false);

        // Mock plan building and validation
        $this->promptBuilder->shouldReceive('makeBootstrapPlanMessages')->andReturn([
            'messages' => [
                ['role' => 'system', 'content' => 'system prompt'],
                ['role' => 'user', 'content' => json_encode([
                    'version' => '1.0',
                    'meta' => ['task' => 'Step-1', 'company' => 'Test Company'],
                    'actions' => [['do' => 'goto', 'url' => 'https://example.com'], ['do' => 'return', 'data' => ['main_html']]],
                ])],
            ],
            'trace' => ['policy_caps' => []],
        ]);
        $this->ollamaClient->shouldReceive('withFallback')->andReturn([
            'choices' => [['message' => ['content' => json_encode(['version' => '2.0', 'meta' => [], 'actions' => []])]]]
        ]);
        $this->sessionFs->shouldReceive('writePlan');
        $this->schemaValidator->shouldReceive('validate');
        $this->policyChecks->shouldReceive('check');

        // In dry run, satisfaction check should run but no runner execution
        $this->sessionFs->shouldReceive('isStep1Satisfied')->andReturn(false);
        $this->sessionFs->shouldReceive('getPaths')->andReturn([]);
        $this->sessionFs->shouldReceive('releaseLock')->once();

        // Runner should NOT be called in dry run
        $this->runnerClient->shouldNotReceive('run');

        $result = $this->service->runStep1Bootstrap('Test Company', 'example.com', 'test-session', [
            'dryRun' => true,
            'thinking' => true
        ]);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['done']);
        $this->assertNull($result['runner']);
        $this->assertNotNull($result['plan']);
    }
}