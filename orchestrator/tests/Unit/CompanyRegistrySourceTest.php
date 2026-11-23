<?php

namespace Tests\Unit;

use App\Services\RegistryFieldMapper;
use App\Services\CompanyRegistrySource;
use App\Tools\CompanyRegistryRepository;
use Mockery;
use Tests\TestCase;

class CompanyRegistrySourceTest extends TestCase
{
    public function test_not_supported_when_no_registry_host_is_configured(): void
    {
        config()->set('company_registry.connection.host', '');
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldNotReceive('factsForCode');

        $this->assertFalse((new CompanyRegistrySource($repo, new RegistryFieldMapper()))->supports(['code' => '123456789', 'name' => 'Acmena']));
    }

    public function test_supports_accepts_code_or_name(): void
    {
        $src = new CompanyRegistrySource(Mockery::mock(CompanyRegistryRepository::class), new RegistryFieldMapper());

        $this->assertTrue($src->supports(['code' => '111']));
        $this->assertTrue($src->supports(['name' => 'Acmena']));   // resolvable by name now
        $this->assertFalse($src->supports([]));
    }

    public function test_collect_resolves_code_by_name_and_prefills_contacts_from_db(): void
    {
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldReceive('resolveCode')->with(null, 'Baltkela Logistics')->andReturn('111');
        $repo->shouldReceive('factsForCode')->with('111')->andReturn([
            'code' => '111', 'name' => 'Baltkela Logistics, UAB', 'employees' => 500,
            'email' => 'info@baltkela.eu', 'phone' => '+37060000000', 'mobile' => null,
        ]);

        $r = (new CompanyRegistrySource($repo, new RegistryFieldMapper()))->collect(['name' => 'Baltkela Logistics']);

        // Contacts filled from OUR DB (no web search needed for these).
        $this->assertSame('ok', $r->status);
        $this->assertSame(['info@baltkela.eu'], $r->fields['emails']);
        $this->assertSame(['+37060000000'], $r->fields['phones']);
    }

    public function test_collect_skips_when_name_does_not_resolve(): void
    {
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldReceive('resolveCode')->with(null, 'Obscure Ltd')->andReturn(null);
        $repo->shouldNotReceive('factsForCode');

        $r = (new CompanyRegistrySource($repo, new RegistryFieldMapper()))->collect(['name' => 'Obscure Ltd']);

        $this->assertSame('skipped', $r->status);
    }

    public function test_collect_returns_address_field_and_size_meta(): void
    {
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldReceive('factsForCode')->with('111')->andReturn([
            'code' => '111', 'name' => 'Acmena UAB', 'address' => 'Laisvės pr. 1, Vilnius', 'employees' => 12,
        ]);

        $r = (new CompanyRegistrySource($repo, new RegistryFieldMapper()))->collect(['code' => '111', 'name' => 'Acmena']);

        $this->assertSame('ok', $r->status);
        $this->assertSame('registry_db', $r->source);
        $this->assertSame('Laisvės pr. 1, Vilnius', $r->fields['address']);
        $this->assertSame(12, $r->meta['employees']);
        $this->assertSame('Acmena UAB', $r->meta['name']);
    }

    public function test_collect_maps_rich_registry_facts_into_standard_profile_fields(): void
    {
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldReceive('factsForCode')->with('222')->andReturn([
            'code' => '222',
            'name' => 'Azerna UAB',
            'address' => 'Laisvės pr. 1, Vilnius',
            'employees' => 34,
            'activity' => 'transporto paslaugos',
            'manager' => 'Jonas Jonaitis',
            'work_hours' => 'I-V 8-17',
            'brand' => 'Azerna',
            'email' => 'info@azerna.lt',
            'phone' => '+37052121212',
            'mobile' => '+37061234567',
            'x' => 'https://x.com/azerna',
            'tiktok' => 'https://tiktok.com/@azerna',
            'skype' => 'azerna.support',
            'vat_code' => 'LT123456789',
            'is_vat_payer' => true,
            'registered' => '2005-03-01',
            'bankrupt' => false,
            'site' => null,
            'logo' => null,
            'about' => 'Azerna UAB is a Lithuanian transport services company.',
        ]);

        $r = (new CompanyRegistrySource($repo, new RegistryFieldMapper()))->collect(['code' => '222', 'name' => 'Azerna']);

        $this->assertSame('ok', $r->status);
        $this->assertSame('registry_db', $r->source);
        $this->assertSame('transporto paslaugos', $r->fields['activity']);
        $this->assertSame('Laisvės pr. 1, Vilnius', $r->fields['address']);
        $this->assertSame(['info@azerna.lt'], $r->fields['emails']);
        $this->assertSame(['+37052121212', '+37061234567'], $r->fields['phones']);
        $this->assertSame(
            ['https://x.com/azerna', 'https://tiktok.com/@azerna', 'azerna.support'],
            $r->fields['social_links'],
        );
        $this->assertSame('Azerna UAB is a Lithuanian transport services company.', $r->fields['description']);

        $this->assertSame('Azerna UAB', $r->meta['name']);
        $this->assertSame(34, $r->meta['employees']);
        $this->assertSame('transporto paslaugos', $r->meta['activity']);
        $this->assertSame('Jonas Jonaitis', $r->meta['manager']);
        $this->assertSame('LT123456789', $r->meta['vat_code']);
        $this->assertSame('2005-03-01', $r->meta['registered']);
    }

    public function test_collect_omits_optional_fields_when_facts_are_minimal(): void
    {
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldReceive('factsForCode')->with('333')->andReturn([
            'code' => '333',
            'name' => 'Minimal UAB',
            'address' => 'Gedimino pr. 5, Vilnius',
            'employees' => null,
            'activity' => null,
            'manager' => null,
            'work_hours' => null,
            'brand' => null,
            'email' => null,
            'phone' => null,
            'mobile' => null,
            'x' => null,
            'tiktok' => null,
            'skype' => null,
            'vat_code' => null,
            'is_vat_payer' => false,
            'registered' => null,
            'bankrupt' => false,
            'site' => null,
            'logo' => null,
            'about' => null,
        ]);

        $r = (new CompanyRegistrySource($repo, new RegistryFieldMapper()))->collect(['code' => '333', 'name' => 'Minimal']);

        $this->assertSame('ok', $r->status);
        $this->assertSame(['address' => 'Gedimino pr. 5, Vilnius'], $r->fields);
    }

    public function test_collect_empty_when_no_registry_row(): void
    {
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldReceive('factsForCode')->with('999')->andReturn(null);

        $r = (new CompanyRegistrySource($repo, new RegistryFieldMapper()))->collect(['code' => '999']);

        $this->assertSame('empty', $r->status);
        $this->assertSame([], $r->fields);
    }

    public function test_collect_fails_soft_on_db_error(): void
    {
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldReceive('factsForCode')->andThrow(new \RuntimeException('connection refused'));

        $r = (new CompanyRegistrySource($repo, new RegistryFieldMapper()))->collect(['code' => '111']);

        $this->assertSame('error', $r->status);
        $this->assertSame([], $r->fields);
    }
}
