<?php

namespace Tests\Unit;

use App\Tools\CompanyRegistryRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyRegistrySearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.company_registry' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        Schema::connection('company_registry')->create('firms', function ($t): void {
            $t->string('code');
            $t->string('name')->nullable();
            $t->string('website')->nullable();
            $t->string('address')->nullable();
            $t->integer('employees')->nullable();
            $t->string('description')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('mobile')->nullable();
            $t->string('deregistered_at')->nullable();
            $t->text('categories')->nullable();
            $t->string('manager')->nullable();
            $t->string('work_hours')->nullable();
            $t->string('brand_en')->nullable();
            $t->string('brand')->nullable();
            $t->string('x')->nullable();
            $t->string('tiktok')->nullable();
            $t->string('skype')->nullable();
            $t->string('vat_code')->nullable();
            $t->boolean('is_vat_payer')->nullable();
            $t->string('registered_at')->nullable();
            $t->boolean('bankrupt')->nullable();
            $t->string('logo')->nullable();
            $t->text('about_en')->nullable();
        });

        $this->firm(['code' => '111', 'name' => 'Alpha UAB', 'website' => 'alpha.lt', 'employees' => 5]);
        $this->firm(['code' => '222', 'name' => 'Beta UAB', 'website' => null, 'employees' => 50]);
        $this->firm(['code' => '333', 'name' => 'Gamma UAB', 'website' => 'gamma.lt', 'employees' => 500]);
        // Non-gap: has an email -> excluded by BASE_WHERE.
        $this->firm(['code' => '444', 'name' => 'Delta UAB', 'employees' => 5, 'email' => 'x@delta.lt']);
        // Non-gap: dormant (0 employees).
        $this->firm(['code' => '555', 'name' => 'Epsilon UAB', 'employees' => 0]);
    }

    /** @param array<string, mixed> $overrides */
    private function firm(array $overrides): void
    {
        DB::connection('company_registry')->table('firms')->insert(array_merge([
            'code' => '000', 'name' => 'X', 'website' => null, 'address' => 'Vilnius',
            'employees' => 1, 'description' => null, 'email' => null, 'phone' => null,
            'mobile' => null, 'deregistered_at' => null,
        ], $overrides));
    }

    public function test_returns_only_gap_firms(): void
    {
        $codes = array_column((new CompanyRegistryRepository())->searchGapFirms([]), 'code');
        sort($codes);
        $this->assertSame(['111', '222', '333'], $codes);
    }

    public function test_filters_by_site_presence(): void
    {
        $with = array_column((new CompanyRegistryRepository())->searchGapFirms(['site' => 'with']), 'code');
        sort($with);
        $this->assertSame(['111', '333'], $with);

        $without = array_column((new CompanyRegistryRepository())->searchGapFirms(['site' => 'without']), 'code');
        $this->assertSame(['222'], $without);
    }

    public function test_filters_by_size_and_query(): void
    {
        $small = array_column((new CompanyRegistryRepository())->searchGapFirms(['size' => 'small']), 'code');
        $this->assertSame(['111'], $small); // <10 employees

        $byName = array_column((new CompanyRegistryRepository())->searchGapFirms(['q' => 'Beta']), 'code');
        $this->assertSame(['222'], $byName);

        $byCode = array_column((new CompanyRegistryRepository())->searchGapFirms(['q' => '333']), 'code');
        $this->assertSame(['333'], $byCode);
    }

    public function test_facts_for_code_returns_activity_and_manager(): void
    {
        $this->firm([
            'code' => '999',
            'name' => 'Transporto UAB',
            'employees' => 3,
            'categories' => json_encode(['en' => 'transportation', 'lt' => 'transportas']),
            'manager' => 'Jonas Jonaitis, direktorius',
            'website' => 'x.lt',
            'email' => 'a@x.lt',
        ]);

        $facts = (new CompanyRegistryRepository())->factsForCode('999');

        $this->assertNotNull($facts);
        $this->assertSame('transportas', $facts['activity']);
        $this->assertStringContainsString('Jonas', $facts['manager']);
        $this->assertSame('a@x.lt', $facts['email']);
        $this->assertSame('x.lt', $facts['site']);
    }

    public function test_decode_categories_prefers_lt_then_en_and_is_safe(): void
    {
        $this->firm([
            'code' => '888',
            'name' => 'English Only UAB',
            'employees' => 4,
            'categories' => json_encode(['en' => 'construction']),
        ]);
        $this->firm([
            'code' => '777',
            'name' => 'No Categories UAB',
            'employees' => 2,
            'categories' => '',
        ]);

        $repo = new CompanyRegistryRepository();

        $this->assertSame('construction', $repo->factsForCode('888')['activity']);
        $this->assertNull($repo->factsForCode('777')['activity']);
    }
}
