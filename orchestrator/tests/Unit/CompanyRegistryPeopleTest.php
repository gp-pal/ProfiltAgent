<?php

namespace Tests\Unit;

use App\Tools\CompanyRegistryRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The registry knows more people than the single `firms.Manager` column the
 * collector used to read: `firm_managers` holds several named managers per
 * company, and `firm_current_managers` carries the role inline ("VARDAS PAVARDĖ, Direktorius").
 * A firm can have several named people there, and the collector used to store none.
 */
class CompanyRegistryPeopleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.company_registry' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        Schema::connection('company_registry')->create('firm_managers', function ($t): void {
            $t->string('code');
            $t->string('manager')->nullable();
            $t->string('source')->nullable();
        });

        Schema::connection('company_registry')->create('firm_current_managers', function ($t): void {
            $t->string('code');
            $t->string('manager')->nullable();
            $t->string('second_manager')->nullable();
            $t->string('former_manager')->nullable();
        });
    }

    /**
     * @param array<int, array{Code: string, Manager: ?string, Source: ?string}> $rows
     */
    private function seedManagers(array $rows): void
    {
        DB::connection('company_registry')->table('firm_managers')->insert($rows);
    }

    private function repo(): CompanyRegistryRepository
    {
        return new CompanyRegistryRepository();
    }

    public function test_named_managers_are_returned_and_blank_rows_dropped(): void
    {
        // Most rows in this table carry an empty Manager (source jar/rcj); on
        // a firm can have two rows and both blank.
        $this->seedManagers([
            ['code' => '111', 'manager' => 'Jolanta Girdenytė', 'source' => 'user'],
            ['code' => '111', 'manager' => '', 'source' => 'import'],
            ['code' => '111', 'manager' => null, 'source' => 'import'],
            ['code' => '111', 'manager' => 'Kristupas Daubys', 'source' => 'admin'],
            ['code' => '222', 'manager' => 'Kitas Žmogus', 'source' => 'admin'],
        ]);

        $people = $this->repo()->peopleForCode('111');

        $this->assertSame(
            ['Jolanta Girdenytė', 'Kristupas Daubys'],
            array_column($people, 'name'),
        );
    }

    public function test_the_manager_table_states_the_role_inline_and_it_is_split_off(): void
    {
        // Observed live and the defect this splitting fixes: most of the rows in this table
        // glue the role onto the name, and the whole phrase used to be handed on as the
        // person's name with role null — so the news dimension searched for
        // "Indrė Šilkūnienė, direktorė", which no article contains.
        $this->seedManagers([
            ['code' => '777', 'manager' => 'Indrė Šilkūnienė, direktorė', 'source' => 'official'],
            ['code' => '777', 'manager' => 'Administracijos direktorius Andrius Berčiūnas', 'source' => 'admin'],
        ]);

        $this->assertSame([
            ['name' => 'Indrė Šilkūnienė', 'role' => 'direktorė'],
            ['name' => 'Andrius Berčiūnas', 'role' => 'Administracijos direktorius'],
        ], $this->repo()->peopleForCode('777'));
    }

    public function test_a_value_naming_nobody_is_returned_unchanged_rather_than_dropped(): void
    {
        // What the registry holds is worth showing the operator even when we cannot read a
        // person out of it — dropping it here would leave no sign the firm has a manager
        // entry at all. Whether it may be SEARCHED is decided where the person queries are
        // planned, which reports it by name instead of querying a phrase that cannot match.
        $this->seedManagers([
            ['code' => '888', 'manager' => 'Donatas Tarvydūnas ir Monika Šermukšnienė', 'source' => 'user'],
        ]);

        $this->assertSame(
            [['name' => 'Donatas Tarvydūnas ir Monika Šermukšnienė', 'role' => null]],
            $this->repo()->peopleForCode('888'),
        );
    }

    public function test_the_same_person_written_with_and_without_a_role_is_returned_once(): void
    {
        // Splitting is what makes this dedup possible at all: with the role glued on, these
        // two rows are different strings, so the same director was stored twice — once as a
        // name, once as a phrase — and both were then researched.
        $this->seedManagers([
            ['code' => '999', 'manager' => 'Kristupas Daubys, direktorius', 'source' => 'official'],
            ['code' => '999', 'manager' => 'Kristupas Daubys', 'source' => 'admin'],
        ]);

        $this->assertSame(
            [['name' => 'Kristupas Daubys', 'role' => 'direktorius']],
            $this->repo()->peopleForCode('999'),
        );
    }

    public function test_current_managers_table_supplies_the_role_from_the_inline_name_role_pair(): void
    {
        DB::connection('company_registry')->table('firm_current_managers')->insert([
            'code' => '333',
            'manager' => 'Erdvilas Ruškevičius, Direktorius',
            'second_manager' => null,
            'former_manager' => 'Senas Vardenis, Direktorius',
        ]);

        $people = $this->repo()->peopleForCode('333');

        // oldManager now comes too, labelled as the registry describes it.
        $this->assertSame([
            ['name' => 'Erdvilas Ruškevičius', 'role' => 'Direktorius'],
            ['name' => 'Senas Vardenis', 'role' => 'Buvęs vadovas'],
        ], $people);
    }

    public function test_the_same_person_from_both_tables_is_returned_once_with_the_role(): void
    {
        // firm_current_managers stores the name upper-cased, firm_managers properly cased.
        // Without case-insensitive dedup the caller gets the same director twice.
        $this->seedManagers([
            ['code' => '444', 'manager' => 'Erdvilas Ruškevičius', 'source' => 'admin'],
        ]);
        DB::connection('company_registry')->table('firm_current_managers')->insert([
            'code' => '444',
            'manager' => 'ERDVILAS RUŠKEVIČIUS, Direktorius',
            'second_manager' => null,
            'former_manager' => null,
        ]);

        $people = $this->repo()->peopleForCode('444');

        $this->assertCount(1, $people);
        $this->assertSame('Erdvilas Ruškevičius', $people[0]['name'], 'the properly-cased name wins');
        $this->assertSame('Direktorius', $people[0]['role']);
    }

    public function test_an_unknown_code_yields_no_people(): void
    {
        $this->assertSame([], $this->repo()->peopleForCode('999'));
    }

    public function test_a_former_manager_is_returned_as_a_leadership_change_signal(): void
    {
        // Recorded as the registry has it, with the role keeping it apart from whoever
        // runs the company today. What a change of leadership means is not ours to judge.
        DB::connection('company_registry')->table('firm_current_managers')->insert([
            'code' => '555',
            'manager' => 'Nauja Vardenė, Direktorė',
            'second_manager' => null,
            'former_manager' => 'Senas Vardenis, Direktorius',
        ]);

        $people = $this->repo()->peopleForCode('555');
        $byName = array_column($people, null, 'name');

        $this->assertSame('Direktorė', $byName['Nauja Vardenė']['role']);
        $this->assertSame('Buvęs vadovas', $byName['Senas Vardenis']['role']);
    }

    public function test_someone_who_is_both_current_and_former_keeps_the_current_role(): void
    {
        // Registries carry stale duplicates, and labelling a sitting director "former"
        // would misreport who currently runs the company.
        DB::connection('company_registry')->table('firm_current_managers')->insert([
            'code' => '666',
            'manager' => 'Tas Pats, Direktorius',
            'second_manager' => null,
            'former_manager' => 'Tas Pats, Direktorius',
        ]);

        $people = $this->repo()->peopleForCode('666');

        $this->assertCount(1, $people);
        $this->assertSame('Direktorius', $people[0]['role']);
    }
}
