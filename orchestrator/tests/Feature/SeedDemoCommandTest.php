<?php

namespace Tests\Feature;

use App\Models\CollectionRun;
use App\Models\CompanyProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedDemoCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_invented_companies_on_reserved_example_hosts(): void
    {
        $this->artisan('agent:seed-demo')->assertSuccessful();

        $this->assertSame(5, CompanyProfile::count());
        $this->assertSame(5, CollectionRun::count());

        foreach (CompanyProfile::all() as $profile) {
            if ($profile->domain !== null) {
                $this->assertStringEndsWith('.example', $profile->domain);
            }
            foreach ($profile->fields['emails'] ?? [] as $email) {
                $this->assertStringEndsWith('.example', $email);
            }
        }
    }

    public function test_it_refuses_a_database_that_already_holds_collected_data(): void
    {
        CompanyProfile::create([
            'company_key' => 'code:1', 'company_code' => '1', 'name' => 'Real',
            'fields' => [], 'field_coverage' => [], 'field_sources' => [], 'overall_score' => 0.1,
        ]);

        $this->artisan('agent:seed-demo')->assertFailed();

        $this->assertSame(1, CompanyProfile::count());
        $this->assertSame(0, CollectionRun::count());
    }
}
