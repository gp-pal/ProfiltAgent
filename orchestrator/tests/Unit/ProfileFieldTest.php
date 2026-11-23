<?php

namespace Tests\Unit;

use App\Models\ProfileField;
use App\Services\FieldRequirements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_the_legacy_six_as_required(): void
    {
        // These six are what every existing consumer reads; the seed must keep them
        // required so behaviour does not change on deploy.
        $legacy = ['description', 'activity', 'emails', 'phones', 'address', 'social_links'];

        $this->assertEqualsCanonicalizing(
            $legacy,
            ProfileField::query()->where('required', true)->pluck('key')->all(),
        );
    }

    public function test_migration_seeds_the_new_fields_enabled_but_not_required(): void
    {
        $new = ['products_services', 'customer_segments', 'positioning', 'recent_developments', 'locations', 'brands'];

        foreach ($new as $key) {
            $field = ProfileField::query()->where('key', $key)->first();
            $this->assertNotNull($field, "{$key} must be seeded");
            $this->assertTrue($field->enabled, "{$key} must be enabled");
            $this->assertFalse($field->required, "{$key} must not be required — a missing new field must not turn every run partial");
            $this->assertNotSame('', trim((string) $field->extraction_hint), "{$key} needs an extraction hint for the prompt");
        }
    }

    public function test_key_is_unique(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        ProfileField::create(['key' => 'description', 'label' => 'Dublis', 'type' => 'text']);
    }

    public function test_flags_are_cast_to_boolean(): void
    {
        $field = ProfileField::create(['key' => 'test_field', 'label' => 'T', 'type' => 'string', 'enabled' => 1, 'required' => 0]);

        $this->assertTrue($field->fresh()->enabled);
        $this->assertFalse($field->fresh()->required);
    }

    public function test_saving_through_the_model_invalidates_the_catalogue_cache(): void
    {
        // FieldRequirementsTest.php:57,90,97 write via ProfileField::query()->update(),
        // which does NOT fire model events — so nothing else in the suite exercises
        // ProfileField::booted(). An admin's edit is meant to land on the very next
        // run, not after the 300s TTL.
        $requirements = new FieldRequirements();
        $this->assertArrayHasKey('brands', $requirements->catalogue());
        $this->assertContains('brands', $requirements->resolve(['brands']));

        $field = ProfileField::query()->where('key', 'brands')->firstOrFail();
        $field->enabled = false;
        $field->save(); // through the model -> `saved` event -> Cache::forget()

        $this->assertArrayNotHasKey('brands', $requirements->catalogue());
        $this->assertNotContains('brands', $requirements->resolve(['brands']));
    }

    public function test_deleting_through_the_model_invalidates_the_catalogue_cache(): void
    {
        $requirements = new FieldRequirements();
        $this->assertArrayHasKey('brands', $requirements->catalogue());

        ProfileField::query()->where('key', 'brands')->firstOrFail()->delete();

        $this->assertArrayNotHasKey('brands', $requirements->catalogue());
    }
}
