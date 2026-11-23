<?php

namespace Tests\Feature;

use App\Filament\Resources\CollectionRuns\Pages\ListCollectionRuns;
use App\Filament\Resources\CollectionRuns\Pages\ViewCollectionRun;
use App\Models\CollectionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CollectionRunDashboardExtrasTest extends TestCase
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

    public function test_needs_attention_filter_shows_problem_runs_and_hides_healthy_ones(): void
    {
        $this->actingAs($this->admin());

        $failed = $this->makeRun('failed', ['status' => 'failed', 'verdict' => null, 'overall_score' => null]);
        $lowScore = $this->makeRun('low', ['status' => 'done', 'verdict' => 'pass', 'overall_score' => 0.3]);
        $rejected = $this->makeRun('rejected', ['status' => 'done', 'verdict' => 'rejected', 'overall_score' => 0.95]);
        // A 'partial' verdict is the pipeline's most common "needs work" outcome
        // and must be flagged even when the score is above the low-score floor.
        $partial = $this->makeRun('partial', ['status' => 'done', 'verdict' => 'partial', 'overall_score' => 0.6]);
        $healthy = $this->makeRun('healthy', ['status' => 'done', 'verdict' => 'sufficient', 'overall_score' => 0.9]);

        Livewire::test(ListCollectionRuns::class)
            ->filterTable('needs_attention', true)
            ->assertCanSeeTableRecords([$failed, $lowScore, $rejected, $partial])
            ->assertCanNotSeeTableRecords([$healthy]);
    }

    public function test_run_view_organises_sections_into_tabs(): void
    {
        // In-page tabs: Overview (assessment) is the default; Actions / Artifacts /
        // Collected payload are one click away instead of a long scroll. All tab
        // panels render into the DOM (content stays assertable).
        $this->actingAs($this->admin());
        $run = $this->makeRun('tabs', ['session' => 'sess-tabs']);
        $run->actions()->create(['source' => 'official_site', 'action' => 'collect', 'status' => 'ok', 'field_scores' => ['description' => 1]]);

        $html = Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('Overview')            // tab label (only exists as a tab)
            ->assertSee('Artifacts')
            ->assertSee('Collected payload')
            ->html();

        $this->assertStringContainsString('fi-tabs', $html);       // Filament tabs component
        $this->assertStringContainsString('official_site', $html); // Actions-tab content still in DOM
    }

    public function test_actions_render_as_compact_table_with_skipped_collapsed(): void
    {
        // #1: Actions is one compact table (Source · Status · Fields filled ·
        // Detail), skipped sources collapsed to a single line, JSON behind a
        // <details> disclosure — not a tall card per source.
        $this->actingAs($this->admin());
        $run = $this->makeRun('acts', ['session' => 'sess-acts']);
        $run->actions()->create(['source' => 'registry_db', 'action' => 'collect', 'status' => 'skipped']);
        $run->actions()->create([
            'source' => 'official_site',
            'action' => 'collect',
            'status' => 'ok',
            'field_scores' => ['description' => 1, 'activity' => 0.5, 'emails' => 0],
            'detail' => json_encode(['domain' => 'acmena.lt'], JSON_THROW_ON_ERROR),
        ]);

        $html = Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('Fields filled')     // compact table header
            ->assertSee('official_site')
            ->assertSee('Skipped')           // skipped sources collapsed to a line
            ->assertSee('registry_db')
            ->html();

        // Detail JSON sits behind a native disclosure, not an always-open card.
        $this->assertStringContainsString('<details', $html);
        // "Fields filled" reflects field_scores > 0.
        $this->assertStringContainsString('description', $html);
    }

    /**
     * The actions table is where an operator audits a finished run, so the match verdict each
     * acceptance rested on has to be readable there — not only inside the JSON disclosure. The
     * word that matters is `unverified`: a source accepted while the judge was unreachable
     * must not present itself like a judged one.
     */
    public function test_actions_table_shows_the_match_verdict_each_source_rested_on(): void
    {
        $this->actingAs($this->admin());
        $run = $this->makeRun('match', ['session' => 'sess-match']);
        $run->actions()->create([
            'source' => 'official_site',
            'action' => 'collect',
            'status' => 'ok',
            'field_scores' => ['description' => 1],
            'detail' => json_encode([
                'domain' => 'acmena.lt',
                'match' => ['accepted' => true, 'verdict' => 'pass', 'basis' => 'stored_verdict', 'verified' => true, 'match_confidence' => 0.95, 'data_coverage' => 0.8, 'reasons' => []],
            ], JSON_THROW_ON_ERROR),
        ]);
        $run->actions()->create([
            'source' => 'open_web',
            'action' => 'collect',
            'status' => 'ok',
            'field_scores' => ['activity' => 1],
            'detail' => json_encode([
                'pages' => ['https://acmena.lt/apie'],
                'match' => ['accepted' => true, 'verdict' => 'unverified', 'basis' => 'judge_unavailable', 'verified' => false, 'match_confidence' => null, 'data_coverage' => null, 'reasons' => ['judge error: LLM down']],
            ], JSON_THROW_ON_ERROR),
        ]);

        $html = Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('Match')             // the column header
            ->html();

        // Asserted as the rendered CHIP, not as text: the stored detail JSON is also on the
        // page behind its disclosure, where every one of these words appears anyway (escaped
        // as &quot;unverified&quot;). Matching plain text would pass with no Match column at
        // all — which is how this test first went green against a stubbed-out cell.
        $this->assertStringContainsString('color:#f59e0b">unverified</span>', $html);
        $this->assertStringContainsString('>judge_unavailable</div>', $html);

        // official_site carried a real verdict: green chip, its basis and its confidence.
        $this->assertStringContainsString('color:#10b981">pass</span>', $html);
        $this->assertStringContainsString('>stored_verdict · 0.95</div>', $html);
    }

    public function test_run_section_groups_dev_metadata_under_its_own_label(): void
    {
        // #2: verdict/score/status/sources lead; run_hash/session/timestamps are
        // grouped under a recessive "Run metadata" label instead of competing.
        $this->actingAs($this->admin());
        $run = $this->makeRun('layout', ['session' => 'sess-layout']);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('Run metadata')     // the new recessive group
            ->assertSee('pass')             // verdict still shown (summary)
            ->assertSee('layout');          // run_hash still shown (metadata)
    }

    public function test_table_filters_render_non_native(): void
    {
        // Table SelectFilters (status, verdict) must render as Filament's own
        // anchored dropdown, not a native <select> whose option popup Chrome
        // mis-positions ("jumps to the side"). Enforced panel-wide via
        // SelectFilter::configureUsing(native(false)) in AppServiceProvider.
        $this->actingAs($this->admin());

        $html = Livewire::test(ListCollectionRuns::class)->assertOk()->html();

        $this->assertStringNotContainsString('fi-fo-select-native', $html);
    }

    public function test_pagination_per_page_renders_as_buttons_not_native_select(): void
    {
        // The records-per-page control is a themed segmented button group
        // (overridden filament::components.pagination.index), NOT the native
        // <select class="fi-select-input"> whose option popup Chrome mis-positions.
        $this->actingAs($this->admin());
        for ($i = 0; $i < 3; $i++) {
            $this->makeRun('pp' . $i);
        }

        $html = Livewire::test(ListCollectionRuns::class)->assertOk()->html();

        // Isolate the records-per-page control markup and assert it renders as
        // Filament buttons (fi-btn), not a native <select> — the whole point of
        // the pagination view override. (fi-select-input can't be used as a
        // marker: non-native form selects use that class internally too.)
        $start = strpos($html, 'fi-pagination-records-per-page-select-ctn');
        $this->assertNotFalse($start, 'per-page control not rendered');
        $perPageMarkup = substr($html, $start, 1500);

        $this->assertStringNotContainsString('<select', $perPageMarkup);
        $this->assertStringContainsString('fi-btn', $perPageMarkup);
    }

    public function test_data_limited_run_is_not_flagged_even_below_score_floor(): void
    {
        // A saturated firm (sources exhausted cleanly) sits below the 0.5 floor but
        // has nothing left to collect — re-running changes nothing, so it must not
        // clog the "needs attention" list. A low score with any OTHER (or no)
        // verdict is still a real problem worth an operator's eyes.
        $dataLimited = $this->makeRun('dl', ['status' => 'done', 'verdict' => 'data_limited', 'overall_score' => 0.3]);
        $lowNull = $this->makeRun('lownull', ['status' => 'done', 'verdict' => null, 'overall_score' => 0.3]);
        $partialLow = $this->makeRun('plow', ['status' => 'done', 'verdict' => 'partial', 'overall_score' => 0.3]);

        $flagged = CollectionRun::needsAttention()->pluck('run_hash')->all();

        $this->assertNotContains('dl', $flagged);
        $this->assertContains('lownull', $flagged);
        $this->assertContains('plow', $flagged);
    }

    public function test_view_page_renders_langfuse_deep_link_when_configured(): void
    {
        config([
            'services.langfuse.enabled' => true,
            'services.langfuse.base_url' => 'https://lf.test',
            'services.langfuse.project_id' => 'proj123',
        ]);

        $this->actingAs($this->admin());
        $run = $this->makeRun('linked', ['session' => 'trace-abc']);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('https://lf.test/project/proj123/sessions/trace-abc');
    }

    public function test_view_page_omits_langfuse_link_when_project_id_missing(): void
    {
        config([
            'services.langfuse.enabled' => true,
            'services.langfuse.base_url' => 'https://lf.test',
            'services.langfuse.project_id' => null,
        ]);

        $this->actingAs($this->admin());
        $run = $this->makeRun('nolink', ['session' => 'trace-xyz']);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertDontSee('/sessions/');
    }

    public function test_view_page_omits_langfuse_link_when_disabled(): void
    {
        config([
            'services.langfuse.enabled' => false,
            'services.langfuse.base_url' => 'https://lf.test',
            'services.langfuse.project_id' => 'proj123',
        ]);

        $this->actingAs($this->admin());
        $run = $this->makeRun('disabled', ['session' => 'trace-off']);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertDontSee('/sessions/');
    }

    public function test_view_page_shows_captured_summary_artifact(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'runs/sess-arts/example.lt/analyze/summary.md',
            'Captured summary body for Acmena.',
        );

        $this->actingAs($this->admin());
        $run = $this->makeRun('arts', ['session' => 'sess-arts']);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('Captured summary body for Acmena.');
    }

    public function test_view_page_bounds_large_json_blocks_with_a_scrollable_style(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'runs/sess-json/example.lt/analyze/profile.json',
            json_encode(['name' => 'Acmena UAB'], JSON_THROW_ON_ERROR),
        );

        $this->actingAs($this->admin());
        $run = $this->makeRun('json', ['session' => 'sess-json']);
        $run->actions()->create([
            'source' => 'website',
            'action' => 'capture',
            'status' => 'ok',
            'detail' => json_encode(['note' => 'captured homepage'], JSON_THROW_ON_ERROR),
            'yielded' => ['official_site' => 'https://example.lt'],
        ]);

        $html = Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->html();

        // The bounded style now lives on a self-contained <pre> (not the
        // entry's outer wrapper) so Filament's template whitespace around
        // the value can't be preserved and scatter the block — see
        // CollectionRunInfolist::preBlock().
        $this->assertStringContainsString(
            '<pre style="margin:0;max-height:18rem;overflow:auto;white-space:pre-wrap;word-break:break-word',
            $html,
        );
    }

    public function test_view_page_escapes_html_in_action_detail_and_yielded_pre_blocks(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin());
        $run = $this->makeRun('xssjson', ['session' => 'sess-xssjson']);
        $run->actions()->create([
            'source' => 'website',
            'action' => 'capture',
            'status' => 'ok',
            'detail' => json_encode(['note' => '<script>alert(1)</script>'], JSON_THROW_ON_ERROR),
            'yielded' => ['official_site' => '<img src=x onerror=alert(1)>'],
        ]);

        $html = Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->html();

        // ->html() now renders detail/yielded inside a <pre>, so scraped
        // content MUST be escaped before it gets there — no raw tags.
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
    }

    public function test_view_page_shows_placeholder_when_run_has_no_session(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin());
        $run = $this->makeRun('noarts', ['session' => null]);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('No artifacts captured for this run.');
    }
}
