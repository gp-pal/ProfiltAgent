<?php

namespace Tests\Unit;

use App\Services\RegistryFieldMapper;
use Tests\TestCase;

/**
 * One owner for the registry -> catalogue mapping. The console's prefill and the
 * collector's registry source both read it, so a second copy would let the suggestions
 * drift from what the pipeline actually takes.
 */
class RegistryFieldMapperTest extends TestCase
{
    private function mapper(): RegistryFieldMapper
    {
        return new RegistryFieldMapper();
    }

    public function test_it_maps_every_fact_that_has_a_catalogue_field(): void
    {
        $fields = $this->mapper()->fields([
            'activity' => 'mažmeninė prekyba',
            'address' => 'Naugarduko g. 84, Vilnius',
            'email' => 'info@acmena.lt',
            'phone' => '+37060000000',
            'mobile' => '+37069999999',
            'x' => 'https://x.com/acmena',
            'tiktok' => 'https://tiktok.com/@acmena',
            'skype' => 'acmena.support',
            'about' => 'Įmonė gamina aliuminio konstrukcijas.',
        ]);

        $this->assertSame('mažmeninė prekyba', $fields['activity']);
        $this->assertSame('Naugarduko g. 84, Vilnius', $fields['address']);
        $this->assertSame(['info@acmena.lt'], $fields['emails']);
        $this->assertSame(['+37060000000', '+37069999999'], $fields['phones']);
        $this->assertSame(
            ['https://x.com/acmena', 'https://tiktok.com/@acmena', 'acmena.support'],
            $fields['social_links'],
        );
        $this->assertSame('Įmonė gamina aliuminio konstrukcijas.', $fields['description']);
    }

    public function test_an_absent_fact_yields_no_key_at_all(): void
    {
        // A minimal registry row must not produce empty strings the coverage scorer would
        // then score as present.
        $fields = $this->mapper()->fields(['address' => 'Vilnius']);

        $this->assertSame(['address' => 'Vilnius'], $fields);
    }

    public function test_a_repeated_phone_is_listed_once(): void
    {
        $fields = $this->mapper()->fields(['phone' => '+37060000000', 'mobile' => '+37060000000']);

        $this->assertSame(['+37060000000'], $fields['phones']);
    }

    public function test_facts_without_a_catalogue_field_are_not_mapped(): void
    {
        // employees / work_hours / vat_code / registered / bankrupt / logo have no
        // catalogue field, and no new field is invented for them.
        $fields = $this->mapper()->fields([
            'employees' => 31,
            'work_hours' => 'I-V 8-17',
            'vat_code' => 'LT100001738313',
            'registered' => '2005-01-01',
            'bankrupt' => false,
            'logo' => 'https://acmena.lt/logo.png',
            'name' => 'Acmena, UAB',
            'code' => '111',
            'site' => 'acmena.lt',
            'manager' => 'Vardas Pavardė',
        ]);

        $this->assertSame([], $fields);
    }
}
