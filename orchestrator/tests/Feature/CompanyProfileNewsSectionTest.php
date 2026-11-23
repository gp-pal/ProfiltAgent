<?php

namespace Tests\Feature;

use App\Filament\Resources\CompanyProfiles\Pages\ViewCompanyProfile;
use App\Models\CompanyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyProfileNewsSectionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@profiltagent.example', 'password' => 'password123']);
    }

    public function test_news_section_lists_mentions_and_escapes_content(): void
    {
        $this->actingAs($this->admin());
        $profile = CompanyProfile::create([
            'company_key' => 'code:111', 'company_code' => '111', 'name' => 'Acmena',
            'domain' => null, 'field_coverage' => [], 'field_sources' => [], 'overall_score' => 0.3,
            'fields' => ['news' => [
                'score' => 0.667, 'fresh' => true,
                'mentions' => [
                    ['portal' => 'delfi.lt', 'title' => 'Acmena <b>auga</b>', 'url' => 'https://delfi.lt/a/1', 'date' => '2026-05-01'],
                ],
            ]],
        ]);

        $html = Livewire::test(ViewCompanyProfile::class, ['record' => $profile->getKey()])
            ->assertOk()
            ->assertSee('0.67')
            ->assertSee('delfi.lt')
            ->html();

        $this->assertStringContainsString('Acmena &lt;b&gt;auga&lt;/b&gt;', $html); // escaped, no raw tag
        $this->assertStringNotContainsString('Acmena <b>auga</b>', $html);

        // Visible backlink to the original article: an underlined <a> to the url
        // opening in a new tab.
        $this->assertMatchesRegularExpression(
            '#<a href="https://delfi\.lt/a/1"[^>]*text-decoration:underline[^>]*>#',
            $html,
        );
        $this->assertStringContainsString('target="_blank"', $html);

        // The block is bounded + scrollable so it fits the page height instead of
        // sprawling / getting clipped when a firm has many mentions.
        $this->assertStringContainsString('max-height:24rem', $html);
        $this->assertStringContainsString('overflow-y:auto', $html);
    }

    public function test_news_section_shows_placeholder_when_absent(): void
    {
        $this->actingAs($this->admin());
        $profile = CompanyProfile::create([
            'company_key' => 'code:222', 'company_code' => '222', 'name' => 'NoNews',
            'domain' => null, 'fields' => [], 'field_coverage' => [], 'field_sources' => [], 'overall_score' => 0.3,
        ]);

        Livewire::test(ViewCompanyProfile::class, ['record' => $profile->getKey()])
            ->assertOk()
            ->assertSee('No third-party news mentions.');
    }

    public function test_a_failed_news_search_on_the_latest_run_is_not_shown_as_no_mentions(): void
    {
        $this->actingAs($this->admin());
        $profile = CompanyProfile::create([
            'company_key' => 'code:333', 'company_code' => '333', 'name' => 'Blind',
            'domain' => null, 'fields' => [], 'field_coverage' => [], 'field_sources' => [], 'overall_score' => 0.3,
        ]);
        $run = \App\Models\CollectionRun::create([
            'run_hash' => 'rblind', 'company_key' => 'code:333', 'company_code' => '333', 'name' => 'Blind',
            'status' => 'done', 'started_at' => now(), 'finished_at' => now(),
        ]);
        $run->actions()->create([
            'source' => 'news', 'action' => 'collect', 'status' => 'error',
            'detail' => json_encode(['news' => ['mentions' => [], 'search_state' => \App\Services\NewsSource::SEARCH_OFF_PORTAL]]),
        ]);

        Livewire::test(ViewCompanyProfile::class, ['record' => $profile->getKey()])
            ->assertOk()
            ->assertSee('did not reach the news portals')
            ->assertDontSee('No third-party news mentions.');
    }

    public function test_field_sources_name_the_field_beside_its_source(): void
    {
        $this->actingAs($this->admin());
        $profile = CompanyProfile::create([
            'company_key' => 'code:444', 'company_code' => '444', 'name' => 'Sourced',
            'domain' => null, 'fields' => ['address' => 'Vilnius'], 'field_coverage' => ['address' => 1.0],
            'field_sources' => ['address' => 'open_web'], 'overall_score' => 0.3,
        ]);

        $html = Livewire::test(ViewCompanyProfile::class, ['record' => $profile->getKey()])->assertOk()->html();

        $this->assertMatchesRegularExpression('#Address.*?open_web · unknown#s', $html);
    }
}
