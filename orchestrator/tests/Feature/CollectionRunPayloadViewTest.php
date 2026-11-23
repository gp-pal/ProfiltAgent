<?php

namespace Tests\Feature;

use App\Filament\Resources\CollectionRuns\Pages\ViewCollectionRun;
use App\Models\CollectionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature coverage for the "Collected payload" section added to the
 * CollectionRun View page: web-search results, captured page markdown,
 * extracted structure, and the ReAct domain-discovery trace — each backed
 * by a real file under runs/{session}/{domain}/analyze/ (see SessionFs).
 */
class CollectionRunPayloadViewTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@profiltagent.example',
            'password' => 'password123',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeRun(string $hash, array $overrides = []): CollectionRun
    {
        return CollectionRun::create(array_merge([
            'run_hash' => $hash,
            'company_key' => 'code:' . $hash,
            'company_code' => $hash,
            'name' => 'Firm ' . $hash,
            'session' => 'sess-' . $hash,
            'sources_used' => ['registry_db'],
            'overall_score' => 0.9,
            'verdict' => 'pass',
            'status' => 'done',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ], $overrides));
    }

    public function test_view_page_shows_web_search_results_query_and_urls(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'runs/sess-search/example.lt/analyze/logs/search_results.json',
            json_encode([
                [
                    'query' => 'Acmena Lithuania 12345',
                    'source' => 'searxng',
                    'results' => [
                        [
                            'title' => 'Acmena official site',
                            'url' => 'https://acmena.lt',
                            'domain' => 'acmena.lt',
                            'snippet' => 'Acmena homepage',
                            'accepted' => true,
                            'filtered' => null,
                        ],
                        [
                            'title' => 'Acmena on a directory',
                            'url' => 'https://rekvizitai.lt/acmena',
                            'domain' => 'rekvizitai.lt',
                            'snippet' => 'Registry listing',
                            'accepted' => false,
                            'filtered' => 'aggregator',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $this->actingAs($this->admin());
        $run = $this->makeRun('search', ['session' => 'sess-search']);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('Acmena Lithuania 12345')
            ->assertSee('https://acmena.lt')
            ->assertSee('accepted')
            ->assertSee('filtered: aggregator');
    }

    public function test_view_page_shows_captured_page_markdown(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'runs/sess-md/example.lt/analyze/main.md',
            'Captured homepage content for Acmena UAB.',
        );

        $this->actingAs($this->admin());
        $run = $this->makeRun('md', ['session' => 'sess-md']);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('Captured homepage content for Acmena UAB.');
    }

    public function test_captured_markdown_block_is_bounded_without_pre_wrap(): void
    {
        // ->markdown() renders real HTML (paragraphs/lists), so its bounded
        // container must NOT use white-space:pre-wrap — that preserved Filament's
        // template indentation around the rendered markdown and scattered the
        // block (same failure the JSON <pre> blocks had). It stays scrollable.
        Storage::fake('local');
        Storage::disk('local')->put(
            'runs/sess-mdstyle/example.lt/analyze/main.md',
            "# Acmena\n\nCaptured homepage content.",
        );

        $this->actingAs($this->admin());
        $run = $this->makeRun('mdstyle', ['session' => 'sess-mdstyle']);

        $html = Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->html();

        $this->assertStringContainsString('display:block;max-height:18rem;overflow:auto', $html);
        $this->assertStringNotContainsString('display:block;max-height:18rem;overflow:auto;white-space:pre-wrap', $html);
    }

    public function test_view_page_shows_structure_signals_summary(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'runs/sess-struct/example.lt/analyze/structure.json',
            json_encode([
                'language' => 'en',
                'page_type' => 'homepage',
                'structure_confidence' => 0.42,
                'company_indicators' => [
                    'contact_info' => [
                        'emails' => ['payload-structure@example.lt'],
                        'phones' => ['+37060011122'],
                    ],
                    'social_links' => ['https://facebook.com/acmena'],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $this->actingAs($this->admin());
        $run = $this->makeRun('struct', ['session' => 'sess-struct']);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('payload-structure@example.lt')
            ->assertSee('+37060011122')
            ->assertSee('https://facebook.com/acmena')
            ->assertSee('0.42')
            ->assertSee('homepage');
    }

    public function test_view_page_shows_react_trace_step_reasoning(): void
    {
        Storage::fake('local');
        $step = [
            'text' => 'Deciding the official domain based on search hits.',
            'tool_calls' => [
                ['name' => 'web_search', 'args' => ['query' => 'Acmena UAB official website']],
            ],
            'tool_results' => [
                ['name' => 'web_search', 'result' => 'Candidates:\n- acmena.lt | Acmena homepage'],
            ],
        ];
        Storage::disk('local')->put(
            'runs/sess-react/example.lt/analyze/logs/react_trace.jsonl',
            json_encode($step, JSON_THROW_ON_ERROR),
        );

        $this->actingAs($this->admin());
        $run = $this->makeRun('react', ['session' => 'sess-react']);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('Deciding the official domain based on search hits.')
            ->assertSee('web_search');
    }

    public function test_view_page_bounds_payload_blocks_with_scrollable_styles(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'runs/sess-bounds/example.lt/analyze/logs/search_results.json',
            json_encode([['query' => 'q', 'source' => 's', 'results' => []]], JSON_THROW_ON_ERROR),
        );
        Storage::disk('local')->put(
            'runs/sess-bounds/example.lt/analyze/logs/react_trace.jsonl',
            json_encode(['text' => 'step', 'tool_calls' => [], 'tool_results' => []], JSON_THROW_ON_ERROR),
        );

        $this->actingAs($this->admin());
        $run = $this->makeRun('bounds', ['session' => 'sess-bounds']);

        $html = Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->html();

        // Outer RepeatableEntry containers (search results, ReAct trace).
        $this->assertStringContainsString('max-height:26rem;overflow:auto', $html);
        // Per-item bounded text cells (returned URLs / tool calls / results).
        $this->assertStringContainsString('max-height:12rem;overflow:auto;white-space:pre-wrap', $html);
        $this->assertStringContainsString('max-height:10rem;overflow:auto;white-space:pre-wrap', $html);
    }

    public function test_view_page_escapes_html_in_bounded_payload_pre_blocks(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'runs/sess-xss/example.lt/analyze/logs/search_results.json',
            json_encode([
                [
                    'query' => 'q',
                    'source' => 's',
                    'results' => [
                        [
                            'title' => 'evil',
                            'url' => '<img src=x onerror=alert(1)>',
                            'domain' => 'evil.lt',
                            'snippet' => '',
                            'accepted' => true,
                            'filtered' => null,
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );
        Storage::disk('local')->put(
            'runs/sess-xss/example.lt/analyze/structure.json',
            json_encode(['language' => '<script>alert(2)</script>'], JSON_THROW_ON_ERROR),
        );
        Storage::disk('local')->put(
            'runs/sess-xss/example.lt/analyze/logs/react_trace.jsonl',
            json_encode([
                'text' => 'step',
                'tool_calls' => [],
                'tool_results' => [
                    ['name' => 'web_search', 'result' => '<script>alert(3)</script>'],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $this->actingAs($this->admin());
        $run = $this->makeRun('xss', ['session' => 'sess-xss']);

        $html = Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->html();

        // These bounded blocks now render via ->html() into a <pre>, so any
        // scraped/derived content (web-search URLs, page structure, ReAct
        // tool results) MUST be escaped before it gets there — no raw tags.
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(2)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(3)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(3)&lt;/script&gt;', $html);
    }

    public function test_view_page_shows_payload_placeholder_when_base_resolves_but_nothing_captured(): void
    {
        Storage::fake('local');
        // No search_results.json / main.md / structure.json / react_trace.jsonl,
        // but the analyze directory exists (some unrelated artifact was written)
        // so findAnalysisBase() resolves a base.
        Storage::disk('local')->put(
            'runs/sess-nopayload/example.lt/analyze/plans/initial.json',
            '{}',
        );

        $this->actingAs($this->admin());
        $run = $this->makeRun('nopayload', ['session' => 'sess-nopayload']);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('No collected payload captured for this run.');
    }

    public function test_view_page_shows_payload_placeholder_when_run_has_no_session(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin());
        $run = $this->makeRun('nosession', ['session' => null]);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('No collected payload captured for this run.');
    }
}
