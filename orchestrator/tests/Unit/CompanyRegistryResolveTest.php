<?php

namespace Tests\Unit;

use App\Tools\CompanyRegistryRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyRegistryResolveTest extends TestCase
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
        });

        $this->firm('111', 'Baltkela Logistics, UAB', 'http://www.baltkela.eu');
        $this->firm('222', 'Acmena UAB', 'acmena.lt');
        // Two firms whose names normalise to the same "dublis" -> ambiguous.
        $this->firm('333', 'Dublis UAB', null);
        $this->firm('334', 'Dublis, MB', null);
    }

    private function firm(string $code, string $name, ?string $site): void
    {
        DB::connection('company_registry')->table('firms')->insert(['code' => $code, 'name' => $name, 'website' => $site]);
    }

    private function repo(): CompanyRegistryRepository
    {
        return new CompanyRegistryRepository();
    }

    public function test_resolves_by_domain_ignoring_scheme_and_www(): void
    {
        $this->assertSame('111', $this->repo()->resolveCode('baltkela.eu', null));
        $this->assertSame('111', $this->repo()->resolveCode('https://www.baltkela.eu/kontaktai', null));
    }

    public function test_resolves_by_unambiguous_normalised_name_ignoring_legal_form(): void
    {
        // Target lacks the legal form the registry stores ("…, UAB").
        $this->assertSame('111', $this->repo()->resolveCode(null, 'Baltkela Logistics'));
    }

    public function test_skips_ambiguous_name(): void
    {
        $this->assertNull($this->repo()->resolveCode(null, 'Dublis'));
    }

    public function test_domain_wins_over_name(): void
    {
        $this->assertSame('222', $this->repo()->resolveCode('acmena.lt', 'Baltkela Logistics'));
    }

    public function test_null_when_no_match(): void
    {
        $this->assertNull($this->repo()->resolveCode('nope.lt', 'Nonexistent Ltd'));
        $this->assertNull($this->repo()->resolveCode(null, null));
    }
}
