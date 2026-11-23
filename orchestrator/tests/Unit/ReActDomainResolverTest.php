<?php

namespace Tests\Unit;

use App\IO\Contracts\LlmClient;
use App\IO\RunnerClient;
use App\Services\Memory;
use App\Services\ReActDomainResolver;
use App\Tools\SearxngSearchService;
use App\Validation\UrlGuard;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class ReActDomainResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.runs_dir', storage_path('app/private/runs/test-react-domain-resolver'));
        File::deleteDirectory(config('app.runs_dir'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('app.runs_dir'));
        Mockery::close();
        parent::tearDown();
    }

    public function test_resolve_confirms_domain_after_visiting_about_page(): void
    {
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchCandidates')->andReturn([
            ['title' => 'Acmena UAB', 'url' => 'https://acmena.lt/', 'domain' => 'acmena.lt', 'snippet' => 'Oficiali'],
        ]);

        $runner = Mockery::mock(RunnerClient::class);
        $runner->shouldReceive('run')->andReturn([
            'ok' => true,
            'data' => [
                'content' => '# Acmena UAB\n\nApie mus: statybos įmonė.',
                'links' => [
                    ['text' => 'Apie mus', 'href' => 'https://acmena.lt/apie'],
                    ['text' => 'Paslaugos', 'href' => 'https://acmena.lt/paslaugos'],
                ],
            ],
        ]);

        // Fake LLM that drives the tool loop deterministically, simulating the
        // Prism loop by looking up tools by name() and calling handle() with
        // named arguments (matching how Prism invokes tool closures).
        $llm = new class implements LlmClient {
            public function complete(array $p): array { return ['choices' => []]; }
            public function withFallback(array $p): array { return ['choices' => []]; }
            public function structured(array $m, array $s): array { return []; }
            public function agentic(string $system, string $user, array $tools, int $maxSteps = 8): array
            {
                $byName = [];
                foreach ($tools as $t) { $byName[$t->name()] = $t; }

                $byName['web_search']->handle(query: 'Acmena oficiali svetainė');
                $byName['visit_page']->handle(url: 'https://acmena.lt/');
                $byName['visit_page']->handle(url: 'https://acmena.lt/apie');
                $byName['confirm_domain']->handle(domain: 'acmena.lt', reason: 'name matches, about verified');

                return ['text' => 'done', 'steps' => []];
            }
        };

        $resolver = new ReActDomainResolver($llm, $search, $runner, $this->offlineGuard(), new Memory());
        $result = $resolver->resolve('Acmena', 'test-react');

        $this->assertNotNull($result);
        $this->assertSame('acmena.lt', $result['domain']);
    }

    public function test_resolve_returns_null_when_confirm_never_called(): void
    {
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchCandidates')->andReturn([]);
        $runner = Mockery::mock(RunnerClient::class);

        $llm = new class implements LlmClient {
            public function complete(array $p): array { return ['choices' => []]; }
            public function withFallback(array $p): array { return ['choices' => []]; }
            public function structured(array $m, array $s): array { return []; }
            public function agentic(string $system, string $user, array $tools, int $maxSteps = 8): array
            {
                return ['text' => 'gave up', 'steps' => []];
            }
        };

        $resolver = new ReActDomainResolver($llm, $search, $runner, $this->offlineGuard(), new Memory());
        $this->assertNull($resolver->resolve('Nežinoma', 'test-react-2'));
    }

    public function test_confirm_rejected_without_about_visit_when_about_link_present(): void
    {
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchCandidates')->andReturn([
            ['title' => 'Acmena', 'url' => 'https://acmena.lt/', 'domain' => 'acmena.lt', 'snippet' => ''],
        ]);
        $runner = Mockery::mock(RunnerClient::class);
        $runner->shouldReceive('run')->andReturn([
            'ok' => true,
            'data' => [
                'content' => 'Acmena',
                'links' => [['text' => 'Apie mus', 'href' => 'https://acmena.lt/apie']],
            ],
        ]);

        // handle() on a closure that returns a plain string (verified against
        // vendor/prism-php/prism/src/Tool.php:262 — handle() returns the
        // closure's string value unwrapped) yields the guard message directly,
        // so it is captured as a string with no unwrapping needed.
        $captured = null;
        $llm = new class($captured) implements LlmClient {
            private $ref;
            public function __construct(&$c) { $this->ref = &$c; }
            public function complete(array $p): array { return ['choices' => []]; }
            public function withFallback(array $p): array { return ['choices' => []]; }
            public function structured(array $m, array $s): array { return []; }
            public function agentic(string $system, string $user, array $tools, int $maxSteps = 8): array
            {
                $byName = [];
                foreach ($tools as $t) { $byName[$t->name()] = $t; }
                $byName['visit_page']->handle(url: 'https://acmena.lt/'); // homepage only, about NOT visited
                $this->ref = (string) $byName['confirm_domain']->handle(domain: 'acmena.lt', reason: 'x');
                return ['text' => 'done', 'steps' => []];
            }
        };

        $resolver = new ReActDomainResolver($llm, $search, $runner, $this->offlineGuard(), new Memory());
        $result = $resolver->resolve('Acmena', 'test-react-3');

        $this->assertNull($result); // confirm was rejected → no domain
        $this->assertStringContainsStringIgnoringCase('about', $captured);
    }

    public function test_confirm_rejected_when_domain_never_visited(): void
    {
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchCandidates')->andReturn([]);
        $runner = Mockery::mock(RunnerClient::class);

        // Fake LLM that jumps straight to confirm_domain, skipping visit_page
        // entirely, to exercise the "reject-never-visited" guard.
        $captured = null;
        $llm = new class($captured) implements LlmClient {
            private $ref;
            public function __construct(&$c) { $this->ref = &$c; }
            public function complete(array $p): array { return ['choices' => []]; }
            public function withFallback(array $p): array { return ['choices' => []]; }
            public function structured(array $m, array $s): array { return []; }
            public function agentic(string $system, string $user, array $tools, int $maxSteps = 8): array
            {
                $byName = [];
                foreach ($tools as $t) { $byName[$t->name()] = $t; }
                $this->ref = (string) $byName['confirm_domain']->handle(domain: 'acmena.lt', reason: 'x');
                return ['text' => 'done', 'steps' => []];
            }
        };

        $resolver = new ReActDomainResolver($llm, $search, $runner, $this->offlineGuard(), new Memory());
        $result = $resolver->resolve('Acmena', 'test-react-4');

        $this->assertNull($result); // confirm was rejected → domain never visited
        $this->assertStringContainsStringIgnoringCase('visit the site', $captured);
    }

    public function test_confirm_allowed_when_homepage_has_no_about_link(): void
    {
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchCandidates')->andReturn([
            ['title' => 'Acmena UAB', 'url' => 'https://acmena.lt/', 'domain' => 'acmena.lt', 'snippet' => 'Oficiali'],
        ]);

        $runner = Mockery::mock(RunnerClient::class);
        $runner->shouldReceive('run')->andReturn([
            'ok' => true,
            'data' => [
                'content' => '# Acmena UAB\n\nPaslaugos ir kainos.',
                'links' => [
                    ['text' => 'Paslaugos', 'href' => 'https://acmena.lt/paslaugos'],
                ],
            ],
        ]);

        // Fake LLM visits only the homepage (which has no About/Contact link)
        // then confirms; the about-visit requirement must NOT be triggered.
        $llm = new class implements LlmClient {
            public function complete(array $p): array { return ['choices' => []]; }
            public function withFallback(array $p): array { return ['choices' => []]; }
            public function structured(array $m, array $s): array { return []; }
            public function agentic(string $system, string $user, array $tools, int $maxSteps = 8): array
            {
                $byName = [];
                foreach ($tools as $t) { $byName[$t->name()] = $t; }
                $byName['visit_page']->handle(url: 'https://acmena.lt/');
                $byName['confirm_domain']->handle(domain: 'acmena.lt', reason: 'verified');
                return ['text' => 'done', 'steps' => []];
            }
        };

        $resolver = new ReActDomainResolver($llm, $search, $runner, $this->offlineGuard(), new Memory());
        $result = $resolver->resolve('Acmena', 'test-react-5');

        $this->assertNotNull($result);
        $this->assertSame('acmena.lt', $result['domain']);
    }

    public function test_onEvent_receives_tool_calls_and_results_per_step(): void
    {
        $search = Mockery::mock(SearxngSearchService::class);
        $runner = Mockery::mock(RunnerClient::class);

        // Fake LLM returns a canned step trace (no tool invocation needed) so we
        // can assert the enriched event payload forwards tool_calls + tool_results.
        $llm = new class implements LlmClient {
            public function complete(array $p): array { return ['choices' => []]; }
            public function withFallback(array $p): array { return ['choices' => []]; }
            public function structured(array $m, array $s): array { return []; }
            public function agentic(string $system, string $user, array $tools, int $maxSteps = 8): array
            {
                return ['text' => 'done', 'steps' => [
                    [
                        'text' => 'searching',
                        'tool_calls' => [['name' => 'web_search', 'args' => ['query' => 'Acmena']]],
                        'tool_results' => [['name' => 'web_search', 'result' => 'Candidates: acmena.lt']],
                    ],
                ]];
            }
        };

        $events = [];
        $resolver = new ReActDomainResolver($llm, $search, $runner, $this->offlineGuard(), new Memory());
        $resolver->resolve('Acmena', 'test-react-6', function (array $e) use (&$events): void {
            $events[] = $e;
        });

        $this->assertCount(1, $events);
        $this->assertSame('react.step', $events[0]['phase']);
        $this->assertSame('web_search', $events[0]['tool_calls'][0]['name']);
        $this->assertSame(['query' => 'Acmena'], $events[0]['tool_calls'][0]['args']);
        $this->assertSame('Candidates: acmena.lt', $events[0]['tool_results'][0]['result']);
    }

    public function test_target_facts_are_injected_into_the_user_prompt(): void
    {
        $search = Mockery::mock(SearxngSearchService::class);
        $runner = Mockery::mock(RunnerClient::class);

        $llm = new class implements LlmClient {
            public string $capturedUser = '';
            public function complete(array $p): array { return ['choices' => []]; }
            public function withFallback(array $p): array { return ['choices' => []]; }
            public function structured(array $m, array $s): array { return []; }
            public function agentic(string $system, string $user, array $tools, int $maxSteps = 8): array
            {
                $this->capturedUser = $user;

                return ['text' => 'no confirm', 'steps' => []];
            }
        };

        $resolver = new ReActDomainResolver($llm, $search, $runner, $this->offlineGuard(), new Memory());

        // With target facts, the prompt must instruct a strict same-company match.
        $resolver->resolve('Pergola', 'facts-on', null, ['name' => 'Pergola', 'code' => '300000789', 'country' => 'LT']);
        $this->assertStringContainsString('country: LT', $llm->capturedUser);
        $this->assertStringContainsString('registry code: 300000789', $llm->capturedUser);
        $this->assertStringContainsString('ONLY if', $llm->capturedUser);

        // Without facts, the prompt stays the plain baseline (backward compatible).
        $resolver->resolve('Pergola', 'facts-off');
        $this->assertStringNotContainsString('ONLY if', $llm->capturedUser);
    }

    /**
     * The real guard resolves the host through DNS, so these tests used to pass only
     * while their fixture domain happened to be registered. The fixture domain is
     * invented on purpose; what is under test is the resolver's tool loop, not the
     * guard (see UrlGuardTest for that).
     */
    private function offlineGuard(): UrlGuard
    {
        return new class extends UrlGuard {
            public function assertSafe(string $url): void {}
        };
    }
}
