<?php

namespace Tests\Feature;

use App\Filament\Resources\CollectionRuns\Pages\ViewCollectionRun;
use App\Models\CollectionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CollectionRunNewsSectionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@profiltagent.example', 'password' => 'password123']);
    }

    private function makeRun(string $hash): CollectionRun
    {
        return CollectionRun::create([
            'run_hash' => $hash,
            'company_key' => 'name:acmena',
            'company_code' => null,
            'name' => 'Acmena',
            'session' => 'sess-' . $hash,
            'sources_used' => ['official_site', 'news'],
            'overall_score' => 0.8,
            'verdict' => 'sufficient',
            'status' => 'done',
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
        ]);
    }

    public function test_run_view_renders_news_section_from_the_news_action(): void
    {
        $this->actingAs($this->admin());
        $run = $this->makeRun('rnews');
        $run->actions()->create([
            'source' => 'news',
            'action' => 'collect',
            'status' => 'ok',
            'detail' => json_encode(['news' => [
                'score' => 0.6,
                'fresh' => false,
                'mentions' => [
                    ['portal' => 'delfi.lt', 'title' => 'Acmena <b>auga</b>', 'url' => 'https://delfi.lt/a/1', 'date' => '2026-05-01'],
                ],
            ]], JSON_THROW_ON_ERROR),
        ]);

        $html = Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('News mentions')
            ->assertSee('delfi.lt')
            ->assertSee('2026-05-01')
            ->html();

        // Escaped scraped title + a visible, bounded backlink block.
        $this->assertStringContainsString('Acmena &lt;b&gt;auga&lt;/b&gt;', $html);
        $this->assertMatchesRegularExpression('#<a href="https://delfi\.lt/a/1"[^>]*text-decoration:underline[^>]*>#', $html);
        $this->assertStringContainsString('max-height:24rem', $html);
    }

    public function test_run_view_shows_placeholder_when_no_news_action(): void
    {
        $this->actingAs($this->admin());
        $run = $this->makeRun('rnonews');

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('No third-party news mentions.');
    }

    public function test_run_view_reports_a_failed_news_search_instead_of_no_mentions(): void
    {
        $this->actingAs($this->admin());
        $run = $this->makeRun('rnewsfail');
        $run->actions()->create([
            'source' => 'news', 'action' => 'collect', 'status' => 'error',
            'detail' => json_encode(['news' => ['mentions' => [], 'search_state' => \App\Services\NewsSource::SEARCH_RETURNED_NOTHING]]),
        ]);

        Livewire::test(ViewCollectionRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee('returned no results at all')
            ->assertDontSee('No third-party news mentions.');
    }
}
