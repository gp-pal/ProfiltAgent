<?php

namespace Tests\Feature;

use App\Filament\Resources\CollectionRuns\CollectionRunResource;
use App\Filament\Resources\CompanyProfiles\CompanyProfileResource;
use App\Models\CollectionAction;
use App\Models\CollectionRun;
use App\Models\CompanyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentMonitoringResourcesTest extends TestCase
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

    private function seedRun(): CollectionRun
    {
        $run = CollectionRun::create([
            'run_hash' => 'abc123',
            'company_key' => 'code:300000970',
            'company_code' => '300000970',
            'name' => 'Acmena UAB',
            'session' => 'sess-1',
            'sources_used' => ['registry_db', 'official_site', 'open_web'],
            'overall_score' => 0.82,
            'verdict' => 'accept',
            'status' => 'done',
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
        ]);

        CollectionAction::create([
            'run_hash' => 'abc123',
            'source' => 'official_site',
            'action' => 'extract',
            'status' => 'ok',
            'yielded' => ['email' => 'info@acmena.lt', 'phones' => ['+37060000000']],
            'field_scores' => ['email' => 0.9, 'phone' => 0.7],
            'detail' => 'Parsed contact block from About page.',
        ]);

        return $run;
    }

    private function seedProfile(): CompanyProfile
    {
        return CompanyProfile::create([
            'company_key' => 'code:300000970',
            'company_code' => '300000970',
            'name' => 'Acmena UAB',
            'domain' => 'acmena.lt',
            'fields' => ['email' => 'info@acmena.lt', 'address' => 'Vilnius, LT'],
            'field_coverage' => ['email' => 0.9, 'address' => 0.6],
            'field_sources' => ['email' => 'official_site', 'address' => 'registry_db'],
            'overall_score' => 0.75,
            'verdict' => 'accept',
        ]);
    }

    public function test_collection_run_index_renders(): void
    {
        $this->seedRun();

        $this->actingAs($this->admin())
            ->get(CollectionRunResource::getUrl('index'))
            ->assertStatus(200)
            ->assertSee('Acmena UAB');
    }

    public function test_collection_run_view_renders_with_actions(): void
    {
        $run = $this->seedRun();

        $this->assertCount(1, $run->actions()->get());

        $this->actingAs($this->admin())
            ->get(CollectionRunResource::getUrl('view', ['record' => $run]))
            ->assertStatus(200)
            ->assertSee('official_site');
    }

    public function test_company_profile_index_renders(): void
    {
        $this->seedProfile();

        $this->actingAs($this->admin())
            ->get(CompanyProfileResource::getUrl('index'))
            ->assertStatus(200)
            ->assertSee('Acmena UAB');
    }

    public function test_company_profile_view_renders(): void
    {
        $profile = $this->seedProfile();

        $this->actingAs($this->admin())
            ->get(CompanyProfileResource::getUrl('view', ['record' => $profile]))
            ->assertStatus(200)
            ->assertSee('acmena.lt');
    }

    public function test_company_profile_view_lists_field_sources_as_a_table_not_a_json_block(): void
    {
        $profile = $this->seedProfile();

        // The <pre> JSON block this used to pin never showed JSON: a TextEntry given an
        // associative array rendered the values alone, so sources appeared with no field.
        $this->actingAs($this->admin())
            ->get(CompanyProfileResource::getUrl('view', ['record' => $profile]))
            ->assertStatus(200)
            ->assertDontSee('<pre style="margin:0;max-height:18rem', false);
    }

    public function test_company_profile_view_escapes_html_in_field_sources(): void
    {
        $profile = CompanyProfile::create([
            'company_key' => 'code:300000971',
            'company_code' => '300000971',
            'name' => 'Evil UAB',
            'domain' => 'evil.lt',
            'fields' => ['email' => 'info@evil.lt'],
            'field_coverage' => ['email' => 0.9],
            'field_sources' => ['email' => '<script>alert(1)</script>'],
            'overall_score' => 0.75,
            'verdict' => 'accept',
        ]);

        $response = $this->actingAs($this->admin())
            ->get(CompanyProfileResource::getUrl('view', ['record' => $profile]))
            ->assertStatus(200);

        // Scraped/derived content must reach the page escaped.
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $response->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_resources_are_read_only(): void
    {
        $this->assertFalse(CollectionRunResource::canCreate());
        $this->assertFalse(CompanyProfileResource::canCreate());
        $this->assertArrayNotHasKey('create', CollectionRunResource::getPages());
        $this->assertArrayNotHasKey('edit', CollectionRunResource::getPages());
        $this->assertArrayNotHasKey('create', CompanyProfileResource::getPages());
        $this->assertArrayNotHasKey('edit', CompanyProfileResource::getPages());
    }
}
