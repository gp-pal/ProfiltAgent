<?php

namespace Tests\Feature;

use App\Filament\Resources\CollectionRuns\Pages\ViewCollectionRun;
use App\Models\CollectionAction;
use App\Models\CollectionRun;
use App\Models\User;
use App\Services\ProfileCoverageScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature coverage for the "Field diagnosis" section added to the
 * CollectionRun View page: a per-field, derived read-only summary of which
 * source (if any) actually provided each canonical target field
 * (ProfileCoverageScorer::FIELDS), computed from CollectionAction.field_scores.
 */
class RunFieldDiagnosisTest extends TestCase
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

    /**
     * @param array<string, int> $fieldScores
     */
    private function makeAction(string $runHash, string $source, array $fieldScores): CollectionAction
    {
        return CollectionAction::create([
            'run_hash' => $runHash,
            'source' => $source,
            'action' => 'collect',
            'status' => 'ok',
            'yielded' => [],
            'field_scores' => $fieldScores,
            'detail' => null,
        ]);
    }

    public function test_view_page_shows_providing_source_for_each_collected_field(): void
    {
        $this->actingAs($this->admin());
        $run = $this->makeRun('diag-collected');

        $this->makeAction($run->run_hash, 'official_site', [
            'description' => 0,
            'activity' => 1,
            'emails' => 0,
            'phones' => 1,
            'address' => 0,
            'social_links' => 0,
        ]);
        $this->makeAction($run->run_hash, 'registry_db', [
            'description' => 0,
            'activity' => 0,
            'emails' => 0,
            'phones' => 0,
            'address' => 1,
            'social_links' => 0,
        ]);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('Field diagnosis')
            ->assertSee('official_site')
            ->assertSee('registry_db')
            ->assertSee('Missing: description, emails, social_links')
            ->assertSee('— not collected');
    }

    public function test_view_page_lists_all_fields_missing_when_run_has_no_actions(): void
    {
        $this->actingAs($this->admin());
        $run = $this->makeRun('diag-empty');

        $expectedMissing = 'Missing: ' . implode(', ', ProfileCoverageScorer::FIELDS);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee($expectedMissing);
    }

    public function test_view_page_shows_all_collected_summary_when_every_field_is_covered(): void
    {
        $this->actingAs($this->admin());
        $run = $this->makeRun('diag-full');

        $this->makeAction($run->run_hash, 'official_site', [
            'description' => 1,
            'activity' => 1,
            'emails' => 1,
            'phones' => 1,
            'address' => 1,
            'social_links' => 1,
        ]);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('All target fields collected.')
            ->assertDontSee('— not collected');
    }
}
