<?php

namespace Tests\Feature;

use App\Filament\Widgets\CollectionStatsOverview;
use App\Filament\Widgets\LatestRunsWidget;
use App\Filament\Widgets\SourceHealthWidget;
use App\Filament\Widgets\SystemHealthWidget;
use App\Filament\Widgets\VerdictDistributionChart;
use App\Models\CollectionAction;
use App\Models\CollectionRun;
use App\Models\CompanyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the SystemHealthWidget's reachability probes off the network so
        // the suite is fast and deterministic (the widget is fail-soft either way).
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response('ok', 200),
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@profiltagent.example',
            'password' => 'password123',
        ]);
    }

    private function seedData(): void
    {
        foreach (['sufficient', 'partial', 'none', 'error'] as $i => $verdict) {
            CollectionRun::create([
                'run_hash' => 'run-' . $i,
                'company_key' => 'code:3021099' . $i,
                'company_code' => '3021099' . $i,
                'name' => 'Firm ' . $i,
                'session' => 'sess-' . $i,
                'sources_used' => ['registry_db', 'official_site'],
                'overall_score' => 0.5 + ($i / 10),
                'verdict' => $verdict,
                'status' => 'done',
                'started_at' => now()->subMinutes(5),
                'finished_at' => now(),
            ]);
        }

        $actions = [
            'registry_db' => 'ok',
            'official_site' => 'rejected',
            'open_web' => 'empty',
            'contacts' => 'skipped',
        ];

        foreach ($actions as $source => $status) {
            CollectionAction::create([
                'run_hash' => 'run-0',
                'source' => $source,
                'action' => 'extract',
                'status' => $status,
                'yielded' => [],
                'field_scores' => [],
                'detail' => 'seeded',
            ]);
        }

        CompanyProfile::create([
            'company_key' => 'code:30210990',
            'company_code' => '30210990',
            'name' => 'Firm 0',
            'domain' => 'firm0.lt',
            'fields' => ['email' => 'info@firm0.lt'],
            'field_coverage' => ['email' => 0.9],
            'field_sources' => ['email' => 'official_site'],
            'overall_score' => 0.75,
            'verdict' => 'sufficient',
        ]);
    }

    public function test_dashboard_returns_200_with_seeded_data(): void
    {
        $this->seedData();

        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertStatus(200);
    }

    public function test_dashboard_returns_200_with_empty_database(): void
    {
        // No seeded rows: registration + page render must still succeed.
        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertStatus(200);
    }

    public function test_widgets_render_with_seeded_data(): void
    {
        $this->seedData();
        $this->actingAs($this->admin());

        Livewire::test(CollectionStatsOverview::class)
            ->assertOk()
            ->assertSee('Company profiles')
            ->assertSee('Average run score');

        Livewire::test(VerdictDistributionChart::class)
            ->assertOk();

        Livewire::test(SourceHealthWidget::class)
            ->assertOk()
            ->assertSee('Source health')
            ->assertSee('registry_db');

        Livewire::test(SystemHealthWidget::class)
            ->assertOk()
            ->assertSee('Browser runner')
            ->assertSee('Database');

        Livewire::test(LatestRunsWidget::class)
            ->assertOk()
            ->assertSee('Latest runs')
            ->assertSee('Firm 0');
    }

    public function test_verdict_chart_colours_follow_verdict_semantics(): void
    {
        $this->seedData();

        $method = new \ReflectionMethod(VerdictDistributionChart::class, 'getData');
        $method->setAccessible(true);
        $data = $method->invoke(new VerdictDistributionChart());

        $colours = array_combine($data['labels'], $data['datasets'][0]['backgroundColor']);

        $this->assertSame('#10b981', $colours['sufficient']); // success green
        $this->assertSame('#f59e0b', $colours['partial']);    // warning amber
    }

    public function test_needs_attention_scope_selects_only_problem_runs(): void
    {
        $make = fn (string $hash, array $o) => CollectionRun::create(array_merge([
            'run_hash' => $hash, 'company_key' => 'k' . $hash, 'name' => $hash,
            'status' => 'done', 'verdict' => 'sufficient', 'overall_score' => 0.9,
        ], $o));

        $make('good', []);
        $make('partial', ['verdict' => 'partial', 'overall_score' => 0.6]);
        $make('low', ['verdict' => 'sufficient', 'overall_score' => 0.3]);
        $make('failed', ['status' => 'failed', 'verdict' => null, 'overall_score' => null]);

        $flagged = CollectionRun::query()->needsAttention()->pluck('run_hash')->all();

        sort($flagged);
        $this->assertSame(['failed', 'low', 'partial'], $flagged);
    }

    public function test_widgets_render_with_empty_database(): void
    {
        // Every widget must render without divide-by-zero or null crashes.
        $this->actingAs($this->admin());

        Livewire::test(CollectionStatsOverview::class)
            ->assertOk()
            ->assertSee('Company profiles');

        Livewire::test(VerdictDistributionChart::class)
            ->assertOk();

        Livewire::test(SourceHealthWidget::class)
            ->assertOk()
            ->assertSee('No collection actions recorded yet.');

        Livewire::test(SystemHealthWidget::class)
            ->assertOk()
            ->assertSee('Database');
    }
}
