<?php

namespace Tests\Unit;

use App\Services\PromptBuilderService;
use App\Tools\SessionFs;
use Tests\TestCase;

class PromptBuilderTest extends TestCase
{
    private PromptBuilderService $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new PromptBuilderService(new SessionFs());
    }

    public function test_system_prompt_resolves_placeholders_and_enforces_json_only(): void
    {
        $result = $this->builder->makeBootstrapPlanMessages('Test Company', 'example.com', 'test-session-123');

        $systemPrompt = $result['messages'][0]['content'];

        // System prompt enforces JSON-only, explanation-free output
        $this->assertStringContainsString('JSON plan only', $systemPrompt);
        $this->assertStringContainsString('No explanations', $systemPrompt);

        // About-page synonyms are injected for both locales
        $this->assertStringContainsString('apie', $systemPrompt); // LT
        $this->assertStringContainsString('about', $systemPrompt); // EN

        // No template placeholders should remain unresolved
        $this->assertStringNotContainsString('{{', $systemPrompt);

        // Policy caps + schema hash remain available via the trace (not the prompt text)
        $trace = $result['trace'];
        $this->assertArrayHasKey('schema', $trace);
        $this->assertArrayHasKey('sha256', $trace['schema']);
        $this->assertIsString($trace['schema']['sha256']);
        $this->assertEquals(64, strlen($trace['schema']['sha256'])); // SHA-256 is 64 hex chars
    }

    public function test_user_prompt_falls_back_to_null_domain_when_unknown(): void
    {
        $result = $this->builder->makeBootstrapPlanMessages('Test Company', null, 'test-session-123');

        $userPrompt = $result['messages'][1]['content'];

        // Domain resolution now happens in the orchestrator (executeResolveDomain),
        // not the LLM. An unknown domain renders the literal "null" placeholder, and
        // the capture recipe is still emitted as a ready-to-complete JSON plan.
        $this->assertStringContainsString('"domain": "null"', $userPrompt);
        $this->assertStringContainsString('goto', $userPrompt);
        $this->assertStringContainsString('extract_html', $userPrompt);
        $this->assertNotNull(json_decode($userPrompt), 'User prompt must be valid JSON');
    }

    public function test_known_domain_generates_minimal_plan(): void
    {
        $result = $this->builder->makeBootstrapPlanMessages('Test Company', 'example.com', 'test-session-123');

        $userPrompt = $result['messages'][1]['content'];

        // When domain is known it is rendered into the minimal homepage-capture plan
        $this->assertStringContainsString('example.com', $userPrompt);
        $this->assertStringContainsString('goto', $userPrompt);
        $this->assertStringContainsString('wait_for', $userPrompt);
        $this->assertStringContainsString('extract_html', $userPrompt);
        $this->assertStringContainsString('extract_iframe', $userPrompt);
        $this->assertStringContainsString('return', $userPrompt);
        $this->assertStringContainsString('main_html', $userPrompt);
        $this->assertStringContainsString('iframes_html', $userPrompt);

        // A screenshot is captured as proof, into the session's screenshots dir
        $this->assertStringContainsString('screenshot_fullpage', $userPrompt);
        $this->assertStringContainsString('runs/test-session-123/example.com/analyze/screenshots/home.png', $userPrompt);

        $this->assertNotNull(json_decode($userPrompt), 'User prompt must be valid JSON');
    }

    public function test_repair_flow(): void
    {
        $errors = json_encode(['Schema validation failed: Missing required field']);
        $lastPlan = json_encode([
            'version' => '2.0',
            'meta' => ['task' => 'test'],
            'actions' => [['do' => 'goto', 'url' => 'https://example.com']]
        ]);

        $result = $this->builder->makeRepairMessages($errors, $lastPlan);

        $this->assertCount(2, $result['messages']);
        $this->assertEquals('system', $result['messages'][0]['role']);
        $this->assertEquals('user', $result['messages'][1]['role']);

        $userPrompt = $result['messages'][1]['content'];

        // Repair prompt should contain both errors and last plan
        $this->assertStringContainsString('Schema validation failed', $userPrompt);
        $this->assertStringContainsString('version', $userPrompt);
        $this->assertStringContainsString('goto', $userPrompt);
    }

    public function test_trace_completeness(): void
    {
        $result = $this->builder->makeBootstrapPlanMessages('Test Company', 'example.com', 'test-session-123');

        $trace = $result['trace'];

        // Assert trace has all required fields
        $this->assertArrayHasKey('policy_caps', $trace);
        $this->assertArrayHasKey('schema', $trace);
        $this->assertArrayHasKey('prompts', $trace);

        // Check policy caps
        $policyCaps = $trace['policy_caps'];
        $this->assertArrayHasKey('max_steps', $policyCaps);
        $this->assertArrayHasKey('max_timeout_ms', $policyCaps);
        $this->assertArrayHasKey('runs_dir', $policyCaps);
        $this->assertEquals(30, $policyCaps['max_steps']);
        $this->assertEquals(20000, $policyCaps['max_timeout_ms']);
        $this->assertEquals('runs', $policyCaps['runs_dir']);

        // Check schema info
        $schema = $trace['schema'];
        $this->assertArrayHasKey('sha256', $schema);
        $this->assertArrayHasKey('excerpt_bytes', $schema);
        $this->assertArrayHasKey('path', $schema);
        $this->assertIsInt($schema['excerpt_bytes']);
        $this->assertLessThanOrEqual(2000, $schema['excerpt_bytes']);

        // Check prompts paths
        $prompts = $trace['prompts'];
        $this->assertArrayHasKey('system_plan', $prompts);
        $this->assertArrayHasKey('user_plan', $prompts);
        $this->assertArrayHasKey('user_repair', $prompts);
    }

    public function test_json_only_enforcement(): void
    {
        $result = $this->builder->makeBootstrapPlanMessages('Test Company', 'example.com', 'test-session-123');

        $systemPrompt = $result['messages'][0]['content'];
        $userPrompt = $result['messages'][1]['content'];

        // The system prompt carries the JSON-only / no-explanations instruction
        $this->assertStringContainsString('JSON plan only', $systemPrompt);
        $this->assertStringContainsString('No explanations', $systemPrompt);

        // The user prompt is a ready-to-complete JSON plan skeleton
        $this->assertNotNull(json_decode($userPrompt), 'User prompt must be valid JSON');
    }

    public function test_legacy_methods_compatibility(): void
    {
        // Test that legacy methods still work for backwards compatibility
        $systemPrompt = $this->builder->planSystem();
        $this->assertIsString($systemPrompt);
        $this->assertStringContainsString('JSON', $systemPrompt);

        $userPrompt = $this->builder->planUser([
            'name' => 'Test Company',
            'domain' => 'example.com',
            'session' => 'test-session'
        ]);
        $this->assertIsString($userPrompt);
        $this->assertStringContainsString('Test Company', $userPrompt);

        $repairPrompt = $this->builder->repairUser('["error"]', '{"plan": "test"}');
        $this->assertIsString($repairPrompt);
        $this->assertStringContainsString('error', $repairPrompt);
    }
}