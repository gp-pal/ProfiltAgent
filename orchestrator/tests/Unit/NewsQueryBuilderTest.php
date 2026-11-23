<?php

namespace Tests\Unit;

use App\Services\NewsQueryBuilder;
use Tests\TestCase;

class NewsQueryBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('react.news.portals_per_query', 5);
        config()->set('react.news.max_people', 3);
        config()->set('react.news.max_queries', 8);
    }

    /** @return array<int, string> */
    private function portals(int $count): array
    {
        return array_map(static fn (int $i): string => "portal{$i}.lt", range(1, $count));
    }

    public function test_chunks_portals_into_site_or_groups(): void
    {
        $out = (new NewsQueryBuilder())->build('Azerna, UAB', $this->portals(14), []);

        // 14 portals / 5 per query = 3 groups
        $this->assertCount(3, $out['queries']);
        $this->assertSame(0, $out['dropped']);
        $this->assertSame(
            '"Azerna" (site:portal1.lt OR site:portal2.lt OR site:portal3.lt OR site:portal4.lt OR site:portal5.lt)',
            $out['queries'][0]['q'],
        );
        $this->assertSame('company', $out['queries'][0]['subject']);
        $this->assertNull($out['queries'][0]['person']);
    }

    public function test_last_group_holds_the_remainder(): void
    {
        $out = (new NewsQueryBuilder())->build('Azerna', $this->portals(12), []);

        $this->assertCount(3, $out['queries']);
        $this->assertSame('"Azerna" (site:portal11.lt OR site:portal12.lt)', $out['queries'][2]['q']);
    }

    public function test_person_query_pairs_the_person_with_the_company_and_is_not_site_scoped(): void
    {
        $out = (new NewsQueryBuilder())->build('Azerna, UAB', $this->portals(5), [
            ['name' => 'Vardas Pavardė', 'role' => 'Direktorius'],
        ]);

        $person = $out['queries'][1];
        $this->assertSame('"Vardas Pavardė" "Azerna"', $person['q']);
        $this->assertSame('person', $person['subject']);
        $this->assertSame('Vardas Pavardė', $person['person']);
    }

    public function test_each_person_also_gets_a_name_only_query_scoped_to_the_portals(): void
    {
        // Pairing the name with the company finds only articles that already name the
        // company — which the company query found anyway. A name-only query is what
        // reaches pieces about the person that never spell the employer out. It stays
        // site-scoped so a namesake anywhere on the web does not flood the results.
        $out = (new NewsQueryBuilder())->build('Azerna, UAB', $this->portals(5), [
            ['name' => 'Vardas Pavardė', 'role' => 'Direktorius'],
        ]);

        $qs = array_column($out['queries'], 'q');

        $this->assertContains('"Vardas Pavardė" "Azerna"', $qs);
        $this->assertContains(
            '"Vardas Pavardė" (site:portal1.lt OR site:portal2.lt OR site:portal3.lt OR site:portal4.lt OR site:portal5.lt)',
            $qs,
        );

        foreach ($out['queries'] as $query) {
            if (str_contains($query['q'], 'Vardas Pavardė')) {
                $this->assertSame('person', $query['subject']);
                $this->assertSame('Vardas Pavardė', $query['person']);
            }
        }
    }

    public function test_people_are_capped_by_max_people(): void
    {
        config()->set('react.news.max_people', 2);
        $out = (new NewsQueryBuilder())->build('Azerna', $this->portals(5), [
            ['name' => 'Aaa Aaaa'], ['name' => 'Bbb Bbbb'], ['name' => 'Ccc Cccc'],
        ]);

        // The cap is on PEOPLE, not on queries: each person now yields two query
        // forms, so count distinct names rather than rows.
        $persons = array_values(array_filter($out['queries'], static fn (array $q): bool => $q['subject'] === 'person'));
        $this->assertSame(['Aaa Aaaa', 'Bbb Bbbb'], array_values(array_unique(array_column($persons, 'person'))));
    }

    public function test_max_queries_truncates_and_reports_how_many_were_dropped(): void
    {
        config()->set('react.news.max_queries', 2);
        $out = (new NewsQueryBuilder())->build('Azerna', $this->portals(14), [['name' => 'Aaa Aaaa']]);

        // 3 company groups + 2 person forms = 5 wanted, 2 allowed
        $this->assertCount(2, $out['queries']);
        $this->assertSame(3, $out['dropped']);
    }

    public function test_blank_company_yields_no_queries(): void
    {
        $out = (new NewsQueryBuilder())->build('  ', $this->portals(5), []);

        $this->assertSame([], $out['queries']);
    }

    public function test_company_without_distinctive_tokens_yields_no_queries(): void
    {
        // "UAB" is a legal form, not a name — nothing searchable remains.
        $out = (new NewsQueryBuilder())->build('UAB', $this->portals(5), []);

        $this->assertSame([], $out['queries']);
    }

    public function test_no_portals_yields_no_company_queries(): void
    {
        $out = (new NewsQueryBuilder())->build('Azerna', [], []);

        $this->assertSame([], $out['queries']);
    }

    public function test_people_without_a_usable_name_are_skipped(): void
    {
        $out = (new NewsQueryBuilder())->build('Azerna', $this->portals(5), [
            ['name' => '  '],
            ['name' => 'Geras Vardas'],
        ]);

        $persons = array_values(array_filter($out['queries'], static fn (array $q): bool => $q['subject'] === 'person'));
        $this->assertSame(['Geras Vardas'], array_values(array_unique(array_column($persons, 'person'))));
    }

    public function test_build_reports_which_people_the_query_ceiling_can_afford(): void
    {
        // The cap is honest or it is a lie: an operator who lists three names and gets
        // one searched must be able to see which two were left out — by name, not as a
        // count of discarded query rows.
        //
        // Planned, not searched: no query has been issued at this point, so the runtime
        // can still fall short of this list — NewsSource owns that half.
        config()->set('react.news.portals_per_query', 7);
        config()->set('react.news.max_queries', 4);

        $out = (new NewsQueryBuilder())->build('Azerna', $this->portals(14), [
            ['name' => 'Pirmas Vardenis'], ['name' => 'Antras Vardenis'], ['name' => 'Trecias Vardenis'],
        ]);

        // 2 company groups + 2 forms for the first person = the whole budget of 4.
        $this->assertSame(['Pirmas Vardenis'], $out['people_planned']);
        $this->assertSame(['Antras Vardenis', 'Trecias Vardenis'], $out['people_over_budget']);
    }

    public function test_people_beyond_max_people_are_reported_as_dropped_not_forgotten(): void
    {
        // max_people stops the loop before those names are ever considered, so they used
        // to vanish without trace. They are still not searched — they are now named.
        config()->set('react.news.max_people', 1);
        config()->set('react.news.max_queries', 8);

        $out = (new NewsQueryBuilder())->build('Azerna', $this->portals(5), [
            ['name' => 'Aaa Aaaa'], ['name' => 'Bbb Bbbb'],
        ]);

        $this->assertSame(['Aaa Aaaa'], $out['people_planned']);
        $this->assertSame(['Bbb Bbbb'], $out['people_over_budget']);
    }

    public function test_a_numeric_name_is_refused_by_name_not_silently_dropped(): void
    {
        // "0" is not a person's name, so it gets no query — and it is still reported, by
        // name, which is what this test has always been about. It is not over budget
        // either: the ceiling had room, and blaming one would send an operator to raise a
        // setting that cannot help.
        config()->set('react.news.portals_per_query', 7);
        config()->set('react.news.max_queries', 4);

        $out = (new NewsQueryBuilder())->build('Azerna', $this->portals(7), [['name' => '0']]);

        $persons = array_values(array_filter($out['queries'], static fn (array $q): bool => $q['subject'] === 'person'));
        $this->assertSame([], $persons);
        $this->assertSame([], $out['people_planned']);
        $this->assertSame([], $out['people_over_budget']);
        $this->assertSame(['0'], $out['people_unusable']);
    }

    public function test_a_name_with_the_role_glued_on_is_refused_and_does_not_spend_a_person_slot(): void
    {
        // The registry writes the role into the same column as the name for two firms in
        // three, and a quoted query for "Vardas Pavardė, direktorė" matches no article ever
        // written. Refused BEFORE max_people, so an unusable value cannot cost the clean
        // director behind it their query.
        config()->set('react.news.max_people', 1);
        config()->set('react.news.max_queries', 8);

        $out = (new NewsQueryBuilder())->build('Azerna', $this->portals(5), [
            ['name' => 'Indrė Šilkūnienė, direktorė'],
            ['name' => 'Kristupas Daubys'],
        ]);

        $this->assertSame(['Kristupas Daubys'], $out['people_planned']);
        $this->assertSame(['Indrė Šilkūnienė, direktorė'], $out['people_unusable']);
        $this->assertSame([], $out['people_over_budget']);

        foreach (array_column($out['queries'], 'q') as $q) {
            $this->assertStringNotContainsString('direktorė', $q);
        }
    }

    public function test_a_company_in_the_people_list_is_refused_rather_than_searched_as_a_person(): void
    {
        // The manager column sometimes holds a company. "UAB Blizgesio Meistrai" used to reach
        // this planner as the person "Blizgesio Meistrai" — two site-scoped queries for a cleaning
        // firm's trade name, then a green badge beside it. Whatever the value is, if it is not
        // a person it gets no query and is named as unresearched.
        $out = (new NewsQueryBuilder())->build('Azerna', $this->portals(5), [
            ['name' => 'UAB Blizgesio Meistrai'],
            ['name' => 'Kristupas Daubys'],
        ]);

        $this->assertSame(['Kristupas Daubys'], $out['people_planned']);
        $this->assertSame(['UAB Blizgesio Meistrai'], $out['people_unusable']);

        foreach (array_column($out['queries'], 'q') as $q) {
            $this->assertStringNotContainsString('Meistrai', $q);
        }
    }

    public function test_a_value_naming_two_people_is_refused_rather_than_halved(): void
    {
        // One field, two directors: picking one of them is a guess about which one the
        // registry meant, and searching the pair as a phrase finds nothing. Named, refused.
        $out = (new NewsQueryBuilder())->build('Azerna', $this->portals(5), [
            ['name' => 'Donatas Tarvydūnas ir Monika Šermukšnienė'],
        ]);

        $this->assertSame([], $out['people_planned']);
        $this->assertSame(['Donatas Tarvydūnas ir Monika Šermukšnienė'], $out['people_unusable']);
    }

    public function test_people_budget_is_the_query_ceiling_minus_the_company_groups(): void
    {
        config()->set('react.news.portals_per_query', 7);
        config()->set('react.news.max_people', 3);

        config()->set('react.news.max_queries', 4);
        $this->assertSame(1, NewsQueryBuilder::peopleBudget($this->portals(14))); // 2 groups, 2 slots left, 2 forms each
        $this->assertSame(0, NewsQueryBuilder::peopleBudget($this->portals(22))); // 4 groups fill the budget alone

        config()->set('react.news.max_queries', 10);
        $this->assertSame(3, NewsQueryBuilder::peopleBudget($this->portals(14))); // max_people is still the ceiling
    }

    public function test_people_budget_is_exactly_the_number_build_plans(): void
    {
        // The rule has one implementation, and this is the assertion that says so. The
        // budget used to re-derive it — company groups, then a hardcoded two forms per
        // person — so changing how many forms build() gives a person moved the plan and
        // left the number on the console's form untouched, with nothing failing. The odd
        // remainder below is the case that separated them: it pays for one of person 2's
        // two forms, so build() plans two people where the old arithmetic promised one.
        config()->set('react.news.portals_per_query', 7);
        config()->set('react.news.max_queries', 5);
        config()->set('react.news.max_people', 3);

        $portals = $this->portals(14);
        $out = (new NewsQueryBuilder())->build('Azerna', $portals, [
            ['name' => 'Aaa Aaaa'], ['name' => 'Bbb Bbbb'], ['name' => 'Ccc Cccc'],
        ]);

        $this->assertSame(2, count($out['people_planned']));
        $this->assertSame(count($out['people_planned']), NewsQueryBuilder::peopleBudget($portals));
    }

    public function test_people_budget_is_zero_when_no_person_may_be_researched(): void
    {
        // max_people 0 means nobody, whatever room the query ceiling leaves — and the
        // budget must say so without asking build() for a plan it would refuse to make.
        config()->set('react.news.portals_per_query', 7);
        config()->set('react.news.max_queries', 10);
        config()->set('react.news.max_people', 0);

        $this->assertSame(0, NewsQueryBuilder::peopleBudget($this->portals(7)));
    }
}
