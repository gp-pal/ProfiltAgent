<?php

namespace Tests\Unit;

use App\Models\CompanyProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyProfileScoresByCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_maps_codes_to_scores_and_omits_missing(): void
    {
        CompanyProfile::create([
            'company_key' => 'code:111', 'company_code' => '111', 'name' => 'Alpha',
            'domain' => 'alpha.lt', 'fields' => [], 'field_coverage' => [],
            'field_sources' => [], 'overall_score' => 0.8,
        ]);
        CompanyProfile::create([
            'company_key' => 'code:222', 'company_code' => '222', 'name' => 'Beta',
            'domain' => 'beta.lt', 'fields' => [], 'field_coverage' => [],
            'field_sources' => [], 'overall_score' => 0.3,
        ]);

        $map = CompanyProfile::scoresByCode(['111', '222', '999']);

        $this->assertSame(0.8, $map['111']);
        $this->assertSame(0.3, $map['222']);
        $this->assertArrayNotHasKey('999', $map);
        $this->assertSame([], CompanyProfile::scoresByCode([]));
    }
}
