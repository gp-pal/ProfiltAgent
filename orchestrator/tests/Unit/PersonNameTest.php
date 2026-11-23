<?php

namespace Tests\Unit;

use App\Tools\PersonName;
use Tests\TestCase;

/**
 * Every fixture below has the shape of a value a real company registry holds, with the
 * names invented (or, where marked, the shape a caller sends over HTTP). The awkward ones are the point: a parser
 * proven only on "Vardas Pavardė, direktorė" says nothing about the fifth of the registry
 * where the role is capitalised, leads the string, carries its own commas, or where the
 * field holds two people, a company or a phone number.
 */
class PersonNameTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ?string, 2: ?string}>
     */
    public static function registryValues(): array
    {
        return [
            // The observed defect: researched whole, as a person name, with role null.
            'name then lower-case role' => ['Indrė Šilkūnienė, direktorė', 'Indrė Šilkūnienė', 'direktorė'],
            'clean name, no role' => ['Kristupas Daubys', 'Kristupas Daubys', null],
            'role capitalised' => ['Ramūnas Čiurlauskas, Direktorius', 'Ramūnas Čiurlauskas', 'Direktorius'],
            'role capitalised and of the other gender' => ['Edgaras Vilkevičius, Prezidentė', 'Edgaras Vilkevičius', 'Prezidentė'],
            'surname ending in -a' => ['Valerija Skliauta, direktorė', 'Valerija Skliauta', 'direktorė'],
            'role first' => ['direktorė, Asta Norvilavičienė', 'Asta Norvilavičienė', 'direktorė'],
            'abbreviated acting role first' => [
                'l. e. direktoriaus pareigas, Albertas Tarvainavičius',
                'Albertas Tarvainavičius',
                'l. e. direktoriaus pareigas',
            ],
            'role abbreviated to initials' => ['Daiva Girkūnienė, L.e.p.', 'Daiva Girkūnienė', 'L.e.p.'],
            'multi-word role' => ['Angelė Tarvikonienė, Administracijos vadovė', 'Angelė Tarvikonienė', 'Administracijos vadovė'],
            'role containing its own commas' => [
                'Lina Ąžuolienė, Gyventojų aptarnavimo skyriaus vedėja, laikinai vykdanti direktoriaus funkcijas',
                'Lina Ąžuolienė',
                'Gyventojų aptarnavimo skyriaus vedėja, laikinai vykdanti direktoriaus funkcijas',
            ],
            'role leads without a comma' => [
                'Administracijos direktorius Andrius Berčiūnas',
                'Andrius Berčiūnas',
                'Administracijos direktorius',
            ],
            'acting role leads and names the person last' => [
                'laikinai vykdanti direktoriaus funkcijas Sigita Vainorienė',
                'Sigita Vainorienė',
                'laikinai vykdanti direktoriaus funkcijas',
            ],
            // No comma anywhere, so nothing but the occupational vocabulary decides where the
            // role ends: "pareigas" has to be recognised or it joins the name.
            'abbreviated acting role leads, no comma' => [
                'L. e. direktoriaus pareigas Alina Girdauskė',
                'Alina Girdauskė',
                'L. e. direktoriaus pareigas',
            ],
            'role in brackets' => ['Andrej Podgorodin (Direktorius)', 'Andrej Podgorodin', '(Direktorius)'],
            'no space after the comma' => ['Aida Duobkalienė,direktorė', 'Aida Duobkalienė', 'direktorė'],
            'trailing comma, no role' => ['Aivaras Daubauskas,', 'Aivaras Daubauskas', null],
            'upper-cased name as firm_current_managers stores it' => ['RIMGAUDAS DAUGVIČIUS, Direktorius', 'RIMGAUDAS DAUGVIČIUS', 'Direktorius'],
            'double surname' => ['Kristina Liepaitė-Šilavičienė, direktorė', 'Kristina Liepaitė-Šilavičienė', 'direktorė'],
            'three given names, foreign, commas between them' => [
                'Bastien, victor, lucien morel, direktorius',
                'lucien morel',
                'Bastien, victor, direktorius',
            ],
            'honorific before the name' => ['dr. Aistė Girmantaitė, ACC, direktorė', 'Aistė Girmantaitė', 'dr., ACC, direktorė'],
            'academic titles before the name' => [
                'Prof. Dr. (HP) Edita Žiedūnienė',
                'Edita Žiedūnienė',
                'Prof. Dr. (HP)',
            ],
            'profession before the name' => ['Notarė Vita Daugėlienė', 'Vita Daugėlienė', 'Notarė'],
            'English role after the name' => ['Eimis Bruckus General Manager', 'Eimis Bruckus', 'General Manager'],
            // A legal form naming the person's EMPLOYER inside their job title, which is the one
            // place a legal form does not mean "this field holds a company".
            'legal form inside a job title' => [
                'Rolandas Šilniškis, įmonės Arvydonas, UAB vadovas.',
                'Rolandas Šilniškis',
                'įmonės Arvydonas, UAB vadovas.',
            ],
            'company word inside a job title' => [
                'Andrius Daubiukas, bendrovės vadovas',
                'Andrius Daubiukas',
                'bendrovės vadovas',
            ],
            // "II" folds to the same token as the legal form IĮ, and here it is a generational
            // suffix: this row does name a person.
            'generational suffix' => ['Arthur Dunmore Hale II', 'Arthur Dunmore Hale', 'II'],

            // The apostrophe arrives HTML-encoded, and `&#039;` carries a semicolon — which this
            // class splits segments on, so the name was cut and the entity's tail ("id") opened
            // it. Decoded first, the whole name survives.
            'encoded apostrophe mid-name' => [
                'Karim Sa&#039;id Hassan Farouk, direktorius',
                "Karim Sa'id Hassan Farouk",
                'direktorius',
            ],
            'encoded apostrophe in a surname' => [
                'Leonardo D&#039;amico, direktorius',
                "Leonardo D'amico",
                'direktorius',
            ],

            // A double surname the registry spaces out. Before, the name lost its second half
            // and that half was reported as the person's ROLE — 75 distinct values in the live
            // registry are written this way.
            'double surname spaced with a hyphen' => [
                'Milda Žiogutė - Daugžienė',
                'Milda Žiogutė-Daugžienė',
                null,
            ],
            'double surname spaced with an en dash, plus a role' => [
                'Eva Lapaitė – Bondarina, Generalinė direktorė',
                'Eva Lapaitė-Bondarina',
                'Generalinė direktorė',
            ],
            'double given name spaced with a hyphen' => [
                'Andrius - Paulius Zaborskis',
                'Andrius-Paulius Zaborskis',
                null,
            ],
            // The same dash meaning "and now the role". Nothing is joined across an occupational
            // word, in either direction.
            'dash before a role' => ['Kun. Dieter Brandtner - klebonas', 'Dieter Brandtner', 'Kun. - klebonas'],
            'dash after a role' => [
                'Administracijos vadovė - Aistė Vėtraitė',
                'Aistė Vėtraitė',
                'Administracijos vadovė',
            ],
            'two professions and a name, dash between the professions' => [
                'Gyd. odontologas - implantologas Audrius Čiuplys',
                'Audrius Čiuplys',
                'Gyd. odontologas - implantologas',
            ],
            'five-word foreign name' => [
                'Farouk Tarek Mansour Nabil Haddad',
                'Farouk Tarek Mansour Nabil Haddad',
                null,
            ],
            // Six words is past the cap: a run that long is far likelier to be a phrase than
            // a name, and 55 rows in the whole registry are.
            'six-word foreign name is past the cap' => ['Ernesto Luis Marquez De Soltero Fernandez', null, null],
            'role separated by slashes' => ['ANATOLIJUS RADOVAS // DIREKTORIUS', 'ANATOLIJUS RADOVAS', '// DIREKTORIUS'],

            // Nothing to research. The caller keeps the raw value; the news layer names it.
            'two people in one field' => ['Donatas Tarvydūnas ir Monika Šermukšnienė', null, null],
            'two people with roles, separated by a semicolon' => [
                'Dominykas Daunevičius, Direktorius ; Tautvydas Daugirdskis, Direktorius',
                null,
                null,
            ],
            'three people in one field' => ['Andrius Girdžiūnas, Laurynas Vilkevičius, Robert Vladimirovič', null, null],
            'bare role, nobody named' => ['Generalinis direktorius', null, null],
            'given name only' => ['Mindaugas', null, null],
            'initial and surname' => ['A. Morkūnė, vadovas', null, null],
            'initials only' => ['A. B., direktorius', null, null],
            'a company in the manager column' => ['Uždaroji Akcinė Bendrovė Šiaurės Administravimo Centras', null, null],

            // Abbreviated legal forms, which is how the registry usually writes them. Each of
            // these produced a "person" before: the legal form only demoted its own segment,
            // and the tier-2 fallback then handed the company name back as a name.
            'legal form leads' => ['UAB Blizgesio Meistrai', null, null],
            'legal form trails' => ['Pentra Electronics UAB', null, null],
            'legal form in a segment of its own' => ['Alfa Turto Valdymas, UAB', null, null],
            'legal form before a lower-cased company' => ['MB Norma tech', null, null],
            'legal form and a place name' => ['UAB Aqva Vilnius', null, null],
            'legal form and an upper-cased company' => ['UAB ARVANDO GRUPE', null, null],
            'legal form before a word the vocabulary knows' => ['UAB Vivanto servisas', null, null],
            'legal form after a word the vocabulary knows' => ['Automobilių estetikos studija UAB', null, null],
            // The worst of that set: the extraction was 'Stangrus Radviloviene', a chimera of the
            // company and this person's surname, ordinary enough that nothing downstream could
            // tell it was wrong. The person is Audra Radvilovienė, and refusing is the only
            // honest answer available here.
            'a company in brackets inside a person name' => ['Audra (UAB Stangrus) Radviloviene', null, null],
            'a phone number' => ['+370 601 06011', null, null],
            'registry placeholder text' => ['(prašau nerodyti vadovo vardo)', null, null],
            'empty' => ['', null, null],
            'whitespace only' => ['   ', null, null],
        ];
    }

    /**
     * @dataProvider registryValues
     */
    public function test_it_reads_the_name_and_the_role_out_of_a_real_registry_value(
        string $entry,
        ?string $name,
        ?string $role,
    ): void {
        $parsed = PersonName::parse($entry);

        $this->assertSame($name, $parsed['name'], "name from [{$entry}]");
        $this->assertSame($role, $parsed['role'], "role from [{$entry}]");
    }

    public function test_a_name_it_extracted_is_researchable_and_the_raw_value_is_not(): void
    {
        // The two halves have to agree: whatever parse() hands the caller as a name must
        // pass the research gate, and the glued value it came from must not. If they ever
        // disagreed, either a clean director would go unresearched or the phrase would go
        // out as a query after all.
        $entry = 'Indrė Šilkūnienė, direktorė';

        $this->assertFalse(PersonName::isResearchable($entry));
        $this->assertTrue(PersonName::isResearchable((string) PersonName::parse($entry)['name']));
    }

    public function test_a_clean_name_survives_the_gate_however_it_is_spelled(): void
    {
        $this->assertTrue(PersonName::isResearchable('Kristupas Daubys'));
        $this->assertTrue(PersonName::isResearchable('RIMGAUDAS DAUGVIČIUS'), 'the registry stores names upper-cased too');
        $this->assertTrue(PersonName::isResearchable('Kristina Liepaitė-Šilavičienė'));
        $this->assertTrue(PersonName::isResearchable('Jonas Povilas Girdulis'), 'two given names');
        $this->assertTrue(PersonName::isResearchable('  Kristupas   Daubys  '), 'only whitespace differs');
    }

    public function test_the_research_gate_refuses_everything_that_is_not_purely_a_name(): void
    {
        $this->assertFalse(PersonName::isResearchable('direktorė, Asta Norvilavičienė'), 'role still attached');
        $this->assertFalse(PersonName::isResearchable('Generalinis direktorius'), 'nobody named');
        $this->assertFalse(PersonName::isResearchable('Mindaugas'), 'a given name alone is not a research subject');
        $this->assertFalse(PersonName::isResearchable('A. Morkūnė'), 'an initial is not a given name');
        $this->assertFalse(PersonName::isResearchable('0'));
        $this->assertFalse(PersonName::isResearchable(''));
    }

    public function test_a_surname_that_is_also_an_occupational_word_is_refused_rather_than_guessed(): void
    {
        // The cost of the rule, stated rather than hidden: "Seniūnas" is both a real
        // surname (17 rows in the live registry) and the word for the role, in the same
        // spelling, so no rule can tell them apart. Those people are reported unresearched
        // instead of being searched for as "Algis" or as a role phrase — the whole reason
        // the unusable-name reason exists.
        $this->assertNull(PersonName::parse('Algis Seniūnas')['name']);

        // Where the surname merely shares a stem with the role word, it survives: the
        // occupational entry is matched whole for exactly these ("pastorius" the role does
        // not swallow Girdaitis the surname).
        $this->assertSame('Saulius Girdaitis', PersonName::parse('Saulius Girdaitis, direktorius')['name']);
        $this->assertSame('Martynas Kancleris', PersonName::parse('Martynas Kancleris, direktorius')['name']);
        $this->assertSame('Šarūnas Skyrius', PersonName::parse('Šarūnas Skyrius, direktorius')['name']);
        $this->assertSame('Kristina Pareigienė', PersonName::parse('Kristina Pareigienė, direktorė')['name']);
    }

    public function test_a_company_is_never_researchable_however_the_legal_form_is_placed(): void
    {
        // The gate is what the news layer asks, so it is asserted here directly: every one of
        // these passed it before, which is how a company came to be searched for as a person
        // and badged "ieškota naujienų".
        $this->assertFalse(PersonName::isResearchable('UAB Blizgesio Meistrai'));
        $this->assertFalse(PersonName::isResearchable('Pentra Electronics UAB'));
        $this->assertFalse(PersonName::isResearchable('Alfa Turto Valdymas, UAB'));
        $this->assertFalse(PersonName::isResearchable('Audra (UAB Stangrus) Radviloviene'));

        // And the exception stays researchable, so the rule cannot be satisfied by refusing
        // everything that mentions a legal form.
        $this->assertTrue(PersonName::isResearchable('Arthur Dunmore Hale'));
    }

    public function test_a_field_naming_two_people_keeps_the_one_the_tier_rule_prefers(): void
    {
        // The documented limit of the "several people means no name" rule, pinned so nobody
        // reads it as absolute: when only one of the two shares a segment with a role word, the
        // clean one wins outright instead of the pair being refused. Refusing here would cost
        // correct names wholesale, because a role phrase routinely produces a run of its own.
        //
        // The co-founder is therefore neither researched nor reported as dropped — but the raw
        // text survives in the role, which is the only reason this trade is acceptable.
        $parsed = PersonName::parse('Julija Gintvičienė, Dovydas Gintvičius - įkūrėjai');

        $this->assertSame('Julija Gintvičienė', $parsed['name']);
        $this->assertSame('Dovydas Gintvičius - įkūrėjai', $parsed['role']);
    }

    public function test_a_role_phrase_never_becomes_the_name_when_a_clean_segment_holds_one(): void
    {
        // The tie-break that carries the awkward fifth of the data: "Gyventojų aptarnavimo"
        // is two words the occupational vocabulary does not know, sitting in the role
        // segment. It must lose to the segment that has no role word in it at all.
        $entry = 'Lina Ąžuolienė, Gyventojų aptarnavimo skyriaus vedėja, laikinai vykdanti direktoriaus funkcijas';

        $this->assertSame('Lina Ąžuolienė', PersonName::parse($entry)['name']);
    }
}
