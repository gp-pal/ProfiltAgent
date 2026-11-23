<?php

namespace Tests\Unit;

use App\Filament\Resources\CompanyProfiles\Schemas\CompanyProfileInfolist;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Locks in the pure transform behind the Fields KeyValueEntry: known keys get
 * a human label, list values (emails/phones/social_links) are comma-joined,
 * and blanks/unknown keys fall back sensibly — so the profile View page
 * never shows a raw JSON blob for `fields`.
 */
class CompanyProfileInfolistFieldsTest extends TestCase
{
    private function fieldsDisplay(mixed $fields): array
    {
        $method = new ReflectionMethod(CompanyProfileInfolist::class, 'fieldsDisplay');
        $method->setAccessible(true);

        return $method->invoke(null, $fields);
    }

    public function test_maps_known_keys_to_human_labels(): void
    {
        $display = $this->fieldsDisplay([
            'description' => 'Widget maker.',
            'activity' => 'Manufacturing',
            'address' => 'Vilnius, LT',
        ]);

        $this->assertSame([
            'Description' => 'Widget maker.',
            'Activity' => 'Manufacturing',
            'Address' => 'Vilnius, LT',
        ], $display);
    }

    public function test_joins_list_values_with_commas(): void
    {
        $display = $this->fieldsDisplay([
            'emails' => ['info@acmena.lt', 'sales@acmena.lt'],
            'phones' => ['+37060000000'],
            'social_links' => [],
        ]);

        $this->assertSame('info@acmena.lt, sales@acmena.lt', $display['Emails']);
        $this->assertSame('+37060000000', $display['Phones']);
        $this->assertSame('-', $display['Social links']);
    }

    public function test_blank_scalar_values_become_a_dash(): void
    {
        $display = $this->fieldsDisplay(['address' => '', 'activity' => null]);

        $this->assertSame('-', $display['Address']);
        $this->assertSame('-', $display['Activity']);
    }

    public function test_unknown_key_falls_back_to_title_cased_label(): void
    {
        $display = $this->fieldsDisplay(['vat_number' => 'LT100008641214']);

        $this->assertSame(['Vat number' => 'LT100008641214'], $display);
    }

    public function test_returns_empty_array_for_missing_or_empty_fields(): void
    {
        $this->assertSame([], $this->fieldsDisplay(null));
        $this->assertSame([], $this->fieldsDisplay([]));
    }
}
