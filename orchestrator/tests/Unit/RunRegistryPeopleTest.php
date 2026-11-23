<?php

namespace Tests\Unit;

use App\Models\CompanyProfile;
use App\Services\RunRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunRegistryPeopleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{code: string, name: string} */
    private function target(): array
    {
        return ['code' => '111', 'name' => 'Azerna, UAB'];
    }

    public function test_people_are_stored_without_touching_coverage(): void
    {
        $registry = new RunRegistry();
        $target = $this->target();

        $registry->mergeProfile($target, ['address' => 'Vilnius'], ['address' => 1.0], ['address' => 'registry_db']);
        $before = CompanyProfile::query()->first();

        $profile = $registry->mergePeople($target, [
            ['name' => 'Vardas Pavardė', 'role' => 'Direktorius', 'source' => 'caller'],
        ]);

        $this->assertSame('Vardas Pavardė', $profile->fields['people'][0]['name']);
        $this->assertSame('Vilnius', $profile->fields['address']);
        $this->assertSame($before->overall_score, $profile->overall_score);
        $this->assertArrayNotHasKey('people', $profile->field_coverage ?? []);
    }

    public function test_second_write_replaces_people_rather_than_being_ignored(): void
    {
        $registry = new RunRegistry();
        $target = $this->target();

        $registry->mergePeople($target, [['name' => 'Senas Vadovas', 'role' => 'Vadovas', 'source' => 'registry_db']]);
        $profile = $registry->mergePeople($target, [['name' => 'Naujas Vadovas', 'role' => 'Direktorius', 'source' => 'caller']]);

        // mergeProfile() would have dropped this: it only accepts a field whose
        // incoming coverage beats the stored one, and a non-coverage field is
        // always 0.0 — so the first writer would win forever.
        $this->assertCount(1, $profile->fields['people']);
        $this->assertSame('Naujas Vadovas', $profile->fields['people'][0]['name']);
    }

    public function test_empty_people_list_leaves_the_stored_list_untouched(): void
    {
        $registry = new RunRegistry();
        $target = $this->target();
        $registry->mergePeople($target, [['name' => 'Vardas Pavardė', 'role' => null, 'source' => 'caller']]);

        $profile = $registry->mergePeople($target, []);

        $this->assertCount(1, $profile->fields['people']);
    }

    public function test_a_stated_role_survives_a_later_list_that_states_none(): void
    {
        // Absence must not overwrite presence ACROSS runs either: a later list naming the
        // same director without a title says nothing about the role, so the role the
        // registry stated stays.
        $registry = new RunRegistry();
        $target = $this->target();
        $registry->mergePeople($target, [['name' => 'Tautrimas Girdvainis', 'role' => 'Vadovas', 'source' => 'registry_db']]);

        $profile = $registry->mergePeople($target, [['name' => 'Tautrimas Girdvainis', 'role' => null, 'source' => 'caller']]);

        $this->assertCount(1, $profile->fields['people']);
        $this->assertSame('Vadovas', $profile->fields['people'][0]['role']);
        // Provenance is the incoming list's to state; only the role was recovered.
        $this->assertSame('caller', $profile->fields['people'][0]['source']);
    }

    public function test_a_role_the_new_list_states_replaces_the_stored_one(): void
    {
        // Precedence over VALUES is untouched: the newer list knows the current title.
        $registry = new RunRegistry();
        $target = $this->target();
        $registry->mergePeople($target, [['name' => 'Tautrimas Girdvainis', 'role' => 'Vadovas', 'source' => 'registry_db']]);

        $profile = $registry->mergePeople($target, [['name' => 'Tautrimas Girdvainis', 'role' => 'Generalinis direktorius', 'source' => 'caller']]);

        $this->assertSame('Generalinis direktorius', $profile->fields['people'][0]['role']);
    }

    public function test_a_person_the_new_list_drops_is_dropped(): void
    {
        // The deliberate half of the rule: the current list decides WHO is present, so the
        // registry's manager cannot outlive the caller removing them. Only the role of a
        // person who is still named gets recovered.
        $registry = new RunRegistry();
        $target = $this->target();
        $registry->mergePeople($target, [
            ['name' => 'Tautrimas Girdvainis', 'role' => 'Vadovas', 'source' => 'registry_db'],
            ['name' => 'Buvęs Zmogus', 'role' => 'Finansų direktorius', 'source' => 'registry_db'],
        ]);

        $profile = $registry->mergePeople($target, [['name' => 'Tautrimas Girdvainis', 'role' => null, 'source' => 'caller']]);

        $this->assertSame(['Tautrimas Girdvainis'], array_column($profile->fields['people'], 'name'));
        $this->assertSame('Vadovas', $profile->fields['people'][0]['role']);
    }

    public function test_every_silent_person_recovers_their_role_not_only_the_first(): void
    {
        // The recovery is per person, and that is the axis this rule exists on: the API accepts
        // up to 10 people (RunRequest: 'people' => 'nullable|array|max:10') and the calling agent
        // is expected to name several directors, usually without titles. A one-person incoming
        // list cannot tell a loop that recovers every entry from one that recovers only the head,
        // so the list here is deliberately longer than one.
        $registry = new RunRegistry();
        $target = $this->target();
        $registry->mergePeople($target, [
            ['name' => 'Tautrimas Girdvainis', 'role' => 'Vadovas', 'source' => 'registry_db'],
            ['name' => 'Indrė Šilkūnienė', 'role' => 'direktorė', 'source' => 'registry_db'],
            ['name' => 'Rasa Petraitienė', 'role' => 'Finansų direktorė', 'source' => 'registry_db'],
        ]);

        $profile = $registry->mergePeople($target, [
            ['name' => 'Tautrimas Girdvainis', 'role' => null, 'source' => 'caller'],
            ['name' => 'Indrė Šilkūnienė', 'role' => null, 'source' => 'caller'],
            ['name' => 'Rasa Petraitienė', 'role' => null, 'source' => 'caller'],
        ]);

        $this->assertSame(
            ['Vadovas', 'direktorė', 'Finansų direktorė'],
            array_column($profile->fields['people'], 'role'),
        );
    }

    public function test_a_role_is_matched_across_spellings_of_the_same_name(): void
    {
        // The name key is CompanyNameTokens::normalize(), the same key the collector and
        // RegistryPeople dedupe on, so the layers cannot disagree about who is one person.
        $registry = new RunRegistry();
        $target = $this->target();
        $registry->mergePeople($target, [['name' => 'Tautrimas  Girdvainis', 'role' => 'Vadovas', 'source' => 'registry_db']]);

        $profile = $registry->mergePeople($target, [['name' => 'TAUTRIMAS GIRDVAINIS', 'role' => '', 'source' => 'caller']]);

        $this->assertCount(1, $profile->fields['people']);
        $this->assertSame('Vadovas', $profile->fields['people'][0]['role']);
        $this->assertSame('TAUTRIMAS GIRDVAINIS', $profile->fields['people'][0]['name']);
    }

    public function test_a_newly_named_person_keeps_a_null_role(): void
    {
        // Nothing is invented: with no stored role for this person, silence stays silence.
        $registry = new RunRegistry();
        $target = $this->target();
        $registry->mergePeople($target, [['name' => 'Tautrimas Girdvainis', 'role' => 'Vadovas', 'source' => 'registry_db']]);

        $profile = $registry->mergePeople($target, [['name' => 'Naujas Zmogus', 'role' => null, 'source' => 'caller']]);

        $this->assertSame([['name' => 'Naujas Zmogus', 'role' => null, 'source' => 'caller']], $profile->fields['people']);
    }
}
