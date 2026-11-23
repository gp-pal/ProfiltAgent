<?php

namespace Tests\Unit;

use App\Models\ProfileField;
use App\Services\FieldRequirements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FieldRequirementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_a_request_only_the_required_fields_are_declared(): void
    {
        // The seed marks the legacy six `required` and the six new fields
        // `required = false` — a caller that sends no fields[] (the live
        // API consumer, today) must keep seeing exactly the legacy six,
        // not the whole 12-field enabled catalogue.
        $declared = (new FieldRequirements())->resolve();

        $this->assertSame(FieldRequirements::LEGACY, $declared);
    }

    public function test_an_enabled_but_not_required_field_is_declared_only_on_request(): void
    {
        // `enabled` means "requestable"; `required` means "declared by default".
        // `brands` is enabled + not required in the seed, so it must be absent
        // from the default declared set but present when explicitly asked for.
        $requirements = new FieldRequirements();

        $this->assertNotContains('brands', $requirements->resolve());
        $this->assertContains('brands', $requirements->resolve(['brands']));
    }

    public function test_the_caller_wins_over_the_catalogue(): void
    {
        $declared = (new FieldRequirements())->resolve(['description', 'brands']);

        // The consumer knows what its report needs; the catalogue is only a default.
        $this->assertSame(['description', 'brands'], $declared);
    }

    public function test_unknown_keys_are_dropped_rather_than_failing_the_run(): void
    {
        Log::shouldReceive('warning')->once()->withSomeOfArgs('Unknown profile fields requested');

        $declared = (new FieldRequirements())->resolve(['description', 'sugalvotas_laukas']);

        // One bad key must not 422 the whole enrichment — the rest is still useful.
        $this->assertSame(['description'], $declared);
    }

    public function test_disabled_fields_are_never_declared(): void
    {
        ProfileField::query()->where('key', 'brands')->update(['enabled' => false]);

        $this->assertNotContains('brands', (new FieldRequirements())->resolve());
        // Even when explicitly asked for: disabled means "we decided not to collect this".
        $this->assertNotContains('brands', (new FieldRequirements())->resolve(['brands']));
        // ...and with nothing left to declare we fall back to the default rather
        // than to an empty list, which downstream reads as "no list given".
        $this->assertSame(FieldRequirements::LEGACY, (new FieldRequirements())->resolve(['brands']));
    }

    public function test_an_all_unknown_request_falls_back_to_the_default_instead_of_declaring_nothing(): void
    {
        // An empty declared list is ambiguous downstream: ProfileCoverageScorer and
        // CompanyProfileService read it as "no list given" and use the legacy six,
        // while OfficialSiteSource's harvest loop iterates it and keeps nothing —
        // so a single unrecognised key used to fetch the site, extract six fields
        // and then throw all of it away. resolve() must never hand out [].
        $declared = (new FieldRequirements())->resolve(['nera_tokio_lauko']);

        $this->assertSame(FieldRequirements::LEGACY, $declared);
    }

    public function test_an_empty_catalogue_falls_back_to_the_legacy_six(): void
    {
        ProfileField::query()->delete();

        // Backward compatibility: a deploy that has not run the seed must behave
        // exactly like today rather than collecting nothing.
        $this->assertSame(FieldRequirements::LEGACY, (new FieldRequirements())->resolve());
    }

    public function test_catalogue_exposes_type_and_hint_for_the_schema_builder(): void
    {
        $entry = (new FieldRequirements())->catalogue()['positioning'];

        $this->assertSame('text', $entry['type']);
        $this->assertNotSame('', trim((string) $entry['extraction_hint']));
    }

    public function test_required_keys_are_the_legacy_six(): void
    {
        $this->assertEqualsCanonicalizing(FieldRequirements::LEGACY, (new FieldRequirements())->requiredKeys());
    }

    public function test_an_invalid_type_is_coerced_to_string_before_it_reaches_the_schema(): void
    {
        // Task 6 feeds `type` into an LLM JSON schema, which knows only
        // ProfileField::TYPES — anything else must be neutralised here.
        ProfileField::query()->where('key', 'brands')->update(['type' => 'bogus']);

        $this->assertSame('string', (new FieldRequirements())->catalogue()['brands']['type']);
    }

    public function test_required_keys_fall_back_to_legacy_when_nothing_is_marked_required(): void
    {
        ProfileField::query()->update(['required' => false]);

        $this->assertSame(FieldRequirements::LEGACY, (new FieldRequirements())->requiredKeys());
    }

    public function test_a_malformed_catalogue_key_is_skipped_and_logged(): void
    {
        // The Filament form validates `key` on entry, but a row can reach the DB by
        // other means (a raw insert, a future import). An unvalidated key would flow
        // straight into the LLM JSON schema and the API response.
        Log::shouldReceive('warning')->once()->withSomeOfArgs('Skipping malformed profile field key');

        ProfileField::create(['key' => 'Bad Key!', 'label' => 'Bad', 'type' => 'string', 'enabled' => true, 'required' => false]);

        $catalogue = (new FieldRequirements())->catalogue();

        $this->assertArrayNotHasKey('Bad Key!', $catalogue);
        // The rest of the seeded catalogue is unaffected.
        $this->assertArrayHasKey('description', $catalogue);
    }

    public function test_an_empty_catalogue_returns_legacy_even_when_specific_fields_were_requested(): void
    {
        ProfileField::query()->delete();

        // Intended: an unseeded deploy behaves exactly as it did before this feature,
        // rather than honouring a request it has no catalogue to validate against.
        $this->assertSame(FieldRequirements::LEGACY, (new FieldRequirements())->resolve(['brands']));
    }
}
