<?php

namespace Tests\Unit;

use App\Tools\RegistryPeople;
use Tests\TestCase;

/**
 * The shared ordering rule both the collector and `/admin/request-console` read, pinned on its
 * own. Whoever leads this list is the only person a run researches, so every claim here is
 * about the head of a research queue rather than about presentation.
 *
 * @see \Tests\Feature\ConsolePrefillResearchSubjectTest for the two call sites agreeing on it.
 */
class RegistryPeopleTest extends TestCase
{
    public function test_the_manager_column_leads_even_when_the_tables_list_them_last(): void
    {
        // The observed firm: the manager tables name three directors and put the actual
        // manager last, so reading them as they come spends the single research slot on
        // somebody the registry never called the boss.
        $people = RegistryPeople::ordered('Tautrimas Girdvainis', [
            ['name' => 'Rolandas Zabulis', 'role' => null],
            ['name' => 'Mindaugas Strakšas', 'role' => null],
            ['name' => 'Tautrimas Girdvainis', 'role' => null],
        ]);

        $this->assertSame(
            ['Tautrimas Girdvainis', 'Rolandas Zabulis', 'Mindaugas Strakšas'],
            array_column($people, 'name'),
        );
        $this->assertSame('Vadovas', $people[0]['role']);
    }

    public function test_the_role_glued_onto_the_manager_column_is_split_off_the_name(): void
    {
        // Most `firms.manager` values glue the role onto the name. Passed on whole,
        // the "person" is a phrase NewsQueryBuilder refuses as unusable — the slot is spent
        // reporting a name nobody can search for.
        $people = RegistryPeople::ordered('Indrė Šilkūnienė, direktorė', []);

        $this->assertSame([['name' => 'Indrė Šilkūnienė', 'role' => 'direktorė']], $people);
    }

    public function test_a_person_listed_twice_appears_once_and_keeps_the_stated_role(): void
    {
        // Same rule as the merge one layer up: a row that says nothing about the role is
        // silent, not authoritative, so it must not fix the person as role-less.
        $people = RegistryPeople::ordered(null, [
            ['name' => 'Vardas Pavardė', 'role' => null],
            ['name' => 'vardas pavardė', 'role' => 'Direktorius'],
        ]);

        $this->assertSame([['name' => 'Vardas Pavardė', 'role' => 'Direktorius']], $people);
    }

    public function test_an_absent_manager_column_adds_nobody(): void
    {
        $this->assertSame(
            [['name' => 'Rolandas Zabulis', 'role' => null]],
            RegistryPeople::ordered(null, [['name' => 'Rolandas Zabulis', 'role' => null]]),
        );
        $this->assertSame([], RegistryPeople::ordered('   ', []));
    }
}
