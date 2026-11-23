<?php

namespace Tests\Unit;

use App\Models\CollectionAction;
use App\Models\CollectionRun;
use App\Models\CompanyProfile;
use App\Services\CollectionResult;
use App\Services\CollectorService;
use App\Services\CompanyMatchJudge;
use App\Services\ContactsSource;
use App\Services\FieldRequirements;
use App\Services\NewsSource;
use App\Services\OfficialSiteSource;
use App\Services\OpenWebSource;
use App\Services\ProfileCoverageScorer;
use App\Services\CompanyRegistrySource;
use App\Services\RunRegistry;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CollectorServiceTest extends TestCase
{
    use RefreshDatabase;

    private $companyRegistry;
    private $official;
    private $openWeb;
    private $contacts;
    private $news;
    private $judge;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('react.coverage.gap_threshold', 0.5);

        $this->companyRegistry = Mockery::mock(CompanyRegistrySource::class);
        $this->companyRegistry->shouldReceive('name')->andReturn('registry_db')->byDefault();
        $this->official = Mockery::mock(OfficialSiteSource::class);
        $this->official->shouldReceive('name')->andReturn('official_site')->byDefault();
        $this->openWeb = Mockery::mock(OpenWebSource::class);
        $this->openWeb->shouldReceive('name')->andReturn('open_web')->byDefault();
        $this->contacts = Mockery::mock(ContactsSource::class);
        $this->contacts->shouldReceive('name')->andReturn('contacts')->byDefault();
        $this->contacts->shouldReceive('supports')->andReturn(true)->byDefault();
        $this->contacts->shouldReceive('collect')->andReturn(new CollectionResult('contacts', 'empty', []))->byDefault();

        $this->news = Mockery::mock(\App\Services\NewsSource::class);
        $this->news->shouldReceive('name')->andReturn('news')->byDefault();
        $this->news->shouldReceive('supports')->andReturn(false)->byDefault();

        $this->judge = Mockery::mock(CompanyMatchJudge::class);
        $this->judge->shouldReceive('judge')->andReturn(['verdict' => 'pass', 'match_confidence' => 0.9, 'data_coverage' => 0.8, 'reasons' => []])->byDefault();
        $this->judge->shouldReceive('rejects')->andReturnUsing(static fn (array $r): bool => ($r['verdict'] ?? 'warn') === 'fail')->byDefault();
    }

    private function service(): CollectorService
    {
        return new CollectorService(
            new RunRegistry(),
            new ProfileCoverageScorer(),
            $this->judge,
            new FieldRequirements(),
            $this->companyRegistry,
            $this->official,
            $this->openWeb,
            $this->contacts,
            $this->news,
        );
    }

    private function longText(): string
    {
        return str_repeat('Įmonė projektuoja, gamina ir montuoja aliuminio konstrukcijas. ', 4);
    }

    public function test_accumulates_across_sources_and_stops_when_sufficient(): void
    {
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Laisvės pr. 1, Vilnius'], ['employees' => 10])
        );

        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(
            new CollectionResult('official_site', 'ok', [
                'description' => $this->longText(),
                'activity' => 'Aliuminio-stiklo fasadai, langai ir durys',
                'emails' => ['info@acmena.lt'],
                'phones' => ['+37060000000'],
                'social_links' => ['https://linkedin.com/company/acmena'],
            ], ['domain' => 'acmena.lt', 'quality' => ['verdict' => 'pass']])
        );

        // Enough after the official site -> open web must NOT be reached.
        $this->openWeb->shouldNotReceive('collect');

        $out = $this->service()->collect(['code' => '111', 'name' => 'Acmena', 'session' => 's1']);

        $this->assertSame('sufficient', $out['verdict']);
        $this->assertSame(['registry_db', 'official_site'], $out['sources_used']);
        $this->assertSame('Laisvės pr. 1, Vilnius', $out['profile']['address']);
        $this->assertArrayHasKey('description', $out['profile']);

        // Provenance recorded per field.
        $this->assertSame('registry_db', $out['field_sources']['address']);
        $this->assertSame('official_site', $out['field_sources']['description']);

        // Registry persisted the run + actions.
        $run = CollectionRun::where('run_hash', $out['run_hash'])->first();
        $this->assertNotNull($run);
        $this->assertSame('done', $run->status);
        $this->assertSame(2, CollectionAction::where('run_hash', $out['run_hash'])->count());
    }

    public function test_contacts_source_fills_email_phone_gap(): void
    {
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius'], [])
        );
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(new CollectionResult('official_site', 'empty', []));
        $this->openWeb->shouldReceive('supports')->andReturn(true);
        $this->openWeb->shouldReceive('collect')->andReturn(new CollectionResult('open_web', 'empty', []));

        // Contacts source finds the email/phone the other sources couldn't.
        $this->contacts->shouldReceive('collect')->andReturn(
            new CollectionResult('contacts', 'ok', ['emails' => ['info@gruzutis.lt'], 'phones' => ['+37060012345']], ['content' => 'Gružutis UAB info@gruzutis.lt'])
        );

        $out = $this->service()->collect(['code' => '111', 'name' => 'Gružutis', 'session' => 's3']);

        $this->assertContains('contacts', $out['sources_used']);
        $this->assertSame(['info@gruzutis.lt'], $out['profile']['emails']);
        $this->assertSame(['+37060012345'], $out['profile']['phones']);
        $this->assertSame('contacts', $out['field_sources']['emails']);
    }

    public function test_open_web_contribution_rejected_when_judge_fails(): void
    {
        // Registry + official leave declared fields unfilled, so open web is reached.
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius'], [])
        );
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(
            new CollectionResult('official_site', 'empty', [], ['error' => 'no_site_found'])
        );

        $this->openWeb->shouldReceive('supports')->andReturn(true);
        $this->openWeb->shouldReceive('collect')->andReturn(
            new CollectionResult('open_web', 'ok', ['description' => $this->longText(), 'emails' => ['x@wrong.com']], ['pages' => ['https://wrong.example/x']])
        );

        // Judge says the open-web content is a different company.
        $this->judge->shouldReceive('judge')->andReturn(['verdict' => 'fail', 'match_confidence' => 0.1, 'data_coverage' => 0.5, 'reasons' => ['different company']]);

        $out = $this->service()->collect(['code' => '111', 'name' => 'Acmena', 'session' => 's2']);

        // Open-web fields were NOT merged; only the trusted registry address stuck.
        $this->assertNotContains('open_web', $out['sources_used']);
        $this->assertArrayNotHasKey('description', $out['profile']);
        $this->assertSame('Vilnius', $out['profile']['address']);

        // The rejected attempt is still audited.
        $rejected = CollectionAction::where('run_hash', $out['run_hash'])->where('source', 'open_web')->first();
        $this->assertNotNull($rejected);
        $this->assertSame('rejected', $rejected->status);

        // Persisted profile score is gap-aware (only 1 of 6 fields), NOT the naive
        // average of present fields — it matches the run/manifest coverage.
        $profile = CompanyProfile::where('company_code', '111')->first();
        $this->assertNotNull($profile);
        $this->assertLessThan(0.5, $profile->overall_score);
        $this->assertEqualsWithDelta(0.167, $profile->overall_score, 0.01);
        $this->assertEqualsWithDelta($out['coverage']['overall'], $profile->overall_score, 0.001);
    }

    public function test_verdict_is_data_limited_when_sources_exhaust_cleanly(): void
    {
        // Every source runs cleanly (ok/empty) with NO recoverable error, but the
        // firm genuinely lacks most public fields, so declared fields stay unfilled
        // after the whole ladder. This is the firm's ceiling, not a failure to
        // collect — it must NOT be 'partial' (which implies a retryable gap).
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Kaunas'], [])
        );
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(
            new CollectionResult('official_site', 'empty', [], ['error' => 'no_site_found'])
        );
        $this->openWeb->shouldReceive('supports')->andReturn(true);
        $this->openWeb->shouldReceive('collect')->andReturn(new CollectionResult('open_web', 'empty', []));
        // contacts defaults to an 'empty' result in setUp.

        $out = $this->service()->collect(['code' => '222', 'name' => 'Azerna', 'session' => 'sdl']);

        $this->assertSame('data_limited', $out['verdict']);
        $this->assertLessThan(0.7, $out['coverage']['overall']);
        $this->assertContains('registry_db', $out['sources_used']);
    }

    public function test_news_is_a_separate_dimension_and_does_not_change_verdict(): void
    {
        // Coverage sources fill only `address` (-> 0.167), leaving five gaps.
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Kaunas'], [])
        );
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(new CollectionResult('official_site', 'empty', []));
        $this->openWeb->shouldReceive('supports')->andReturn(true);
        $this->openWeb->shouldReceive('collect')->andReturn(new CollectionResult('open_web', 'empty', []));

        // News finds mentions.
        $this->news->shouldReceive('supports')->andReturn(true);
        $this->news->shouldReceive('collect')->andReturn(new CollectionResult('news', 'ok', [], [
            'news' => ['score' => 0.667, 'fresh' => true, 'mentions' => [
                ['portal' => 'delfi.lt', 'title' => 't', 'url' => 'https://delfi.lt/a', 'date' => '2026-05-01'],
            ]],
        ]));

        $out = $this->service()->collect(['code' => '111', 'name' => 'Acmena', 'session' => 'sn']);

        // News never enters the 6-field coverage / verdict.
        $this->assertSame('data_limited', $out['verdict']);
        $this->assertEqualsWithDelta(0.167, $out['coverage']['overall'], 0.01);
        $this->assertArrayNotHasKey('news', $out['coverage']['fields']);
        // News persisted as a separate dimension + audited + in sources_used.
        $this->assertContains('news', $out['sources_used']);
        $this->assertSame(0.667, $out['profile']['news']['score']);
        $this->assertSame(1, CollectionAction::where('run_hash', $out['run_hash'])->where('source', 'news')->count());
    }

    public function test_an_article_title_with_invalid_utf8_fails_the_run_instead_of_finishing_it_quietly(): void
    {
        // The review named this the third door into the console reading an EARLIER run's news
        // report as this one's: a scraped title carrying one invalid UTF-8 byte makes
        // json_encode() return false, so recordAction stores no detail. The first half is
        // real and asserted below. The second half is NOT: mergeNews() runs immediately after
        // and saves the same bytes onto the profile, where Eloquent's own cast throws — so the
        // run aborts and the console is handed an error, never a finished result it could
        // decorate with an older run's mentions.
        //
        // Pinned because both halves matter. If someone ever makes the encoding lenient (a
        // JSON_INVALID_UTF8_SUBSTITUTE on the model cast, say), the run would start finishing
        // with a detail-less news action, and this test is what will notice. The console
        // refuses to fall back either way, which is the defence that does not depend on this.
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Kaunas'], [])
        );
        $this->official->shouldReceive('supports')->andReturn(false);
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $this->news->shouldReceive('supports')->andReturn(true);
        $this->news->shouldReceive('collect')->andReturn(new CollectionResult('news', 'ok', [], [
            'news' => [
                'score' => 0.5,
                'fresh' => true,
                'mentions' => [
                    // A lone 0xB1 byte: valid Latin-1, invalid UTF-8.
                    ['portal' => 'delfi.lt', 'title' => "Antra\xB1te", 'url' => 'https://delfi.lt/a', 'date' => '2026-05-01'],
                ],
                'search_state' => NewsSource::SEARCH_ANSWERED,
                'people_searched' => ['Ieškotas Vardenis'],
                'people_dropped' => [],
                'people_dropped_reasons' => [],
            ],
        ]));

        try {
            $this->service()->collect(['code' => '333', 'name' => 'Acmena', 'session' => 'sn-utf8']);
            $this->fail('a malformed scraped title must not produce a finished run');
        } catch (JsonEncodingException) {
            // mergeNews() -> $profile->save() -> the fields cast refuses the bytes.
        }

        $action = CollectionAction::query()->where('source', 'news')->first();
        $this->assertNotNull($action, 'the news action is recorded before the profile write');
        $this->assertNull($action->detail, 'json_encode() returned false, so no detail could be stored');
    }

    public function test_news_runs_even_when_coverage_target_already_reached(): void
    {
        // the registry alone fills every declared field -> the ladder breaks before
        // official/open_web/contacts. `activity` must clear the gap threshold too
        // (a one-word stub would still count as missing under the new gap-based stop).
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(new CollectionResult('registry_db', 'ok', [
            'description' => $this->longText(), 'activity' => 'Krovinių pervežimo ir logistikos paslaugos.', 'emails' => ['a@a.lt'],
            'phones' => ['+37060000000'], 'address' => 'Kaunas', 'social_links' => ['https://x.com/a'],
        ], []));
        $this->official->shouldReceive('collect')->never();

        $this->news->shouldReceive('supports')->andReturn(true);
        $this->news->shouldReceive('collect')->once()->andReturn(new CollectionResult('news', 'ok', [], [
            'news' => ['score' => 1.0, 'fresh' => true, 'mentions' => [
                ['portal' => 'delfi.lt', 'title' => 't', 'url' => 'https://delfi.lt/a', 'date' => '2026-05-01'],
            ]],
        ]));

        $out = $this->service()->collect(['code' => '222', 'name' => 'Acmena', 'session' => 'sn2']);

        $this->assertSame('sufficient', $out['verdict']);
        // Score survives the JSON round-trip; 1.0 may come back as int 1.
        $this->assertEqualsWithDelta(1.0, $out['profile']['news']['score'], 0.001);
    }

    public function test_verdict_is_partial_when_a_source_hits_a_recoverable_error(): void
    {
        // Same thin coverage, but open web hit a recoverable error (e.g. an LLM
        // failure) rather than cleanly returning nothing — a retry could still
        // fill the gaps, so the run stays 'partial', not 'data_limited'.
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Kaunas'], [])
        );
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(new CollectionResult('official_site', 'empty', []));
        $this->openWeb->shouldReceive('supports')->andReturn(true);
        $this->openWeb->shouldReceive('collect')->andReturn(
            new CollectionResult('open_web', 'error', [], ['detail' => 'llm extraction failed'])
        );

        $out = $this->service()->collect(['code' => '333', 'name' => 'Acmena', 'session' => 'sp']);

        $this->assertSame('partial', $out['verdict']);
    }

    /**
     * A registry row that reports a manager in meta, exactly as CompanyRegistrySource
     * does. Address alone leaves five of the six declared fields as gaps, so the
     * ladder would keep going — the remaining sources are stubbed out to keep these
     * tests about people only.
     */
    private function registryReportsManager(string $manager): void
    {
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius'], ['manager' => $manager]),
        );
        $this->official->shouldReceive('supports')->andReturn(false);
        $this->openWeb->shouldReceive('supports')->andReturn(false);
    }

    public function test_caller_people_win_over_the_registry_manager(): void
    {
        // the registry reports a (possibly stale) manager; the caller sends the current
        // director. Same person, different role -> caller's entry wins, no duplicate.
        $this->registryReportsManager('Senas Vadovas');

        $this->service()->collect([
            'name' => 'Azerna, UAB',
            'people' => [['name' => 'Senas Vadovas', 'role' => 'Generalinis direktorius']],
        ]);

        $people = CompanyProfile::query()->first()->fields['people'];
        $this->assertCount(1, $people);
        $this->assertSame('Generalinis direktorius', $people[0]['role']);
        $this->assertSame('caller', $people[0]['source']);
    }

    public function test_only_the_registry_may_turn_a_manager_field_into_a_role(): void
    {
        // peopleFrom() runs for EVERY accepted source, and RegistryPeople implies 'Vadovas' for
        // a manager column that states none. That is true of `firms.manager` — the column
        // IS the registry's manager field — and true of nothing else. Read from a web source
        // (an About-page extractor being the obvious candidate) the same line would INVENT a
        // role a page never gave and store it indistinguishably from a stated one; and since
        // the manager column leads, the guess would spend the run's single unit of person
        // research. A source that learns who runs a company reports them in meta['people'],
        // where the role is carried only when it was actually read.
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius'], ['manager' => 'Registro Vadovas']),
        );
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(
            new CollectionResult('official_site', 'ok', ['brands' => ['Acmena']], [
                'quality' => ['verdict' => 'pass'],
                'manager' => 'Svetainės Zmogus',
            ]),
        );
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $this->service()->collect(['name' => 'Azerna, UAB', 'fields' => ['address', 'brands']]);

        $this->assertSame(
            [['name' => 'Registro Vadovas', 'role' => 'Vadovas', 'source' => 'registry_db']],
            CompanyProfile::query()->first()->fields['people'],
        );
    }

    public function test_a_caller_who_states_no_role_keeps_the_role_the_registry_stated(): void
    {
        // "Caller wins over the registry" is precedence over VALUES. An entry with no role
        // states nothing about the role rather than "this person has none", so it must not
        // erase what the registry did state — a caller naming the director without their title
        // used to downgrade `Vadovas` to null, throwing away data we already held. The rule
        // lives in the merge so every caller is covered, not only the admin console.
        $this->registryReportsManager('Registro Vadovas');

        $this->service()->collect([
            'name' => 'Azerna, UAB',
            'people' => [['name' => 'Registro Vadovas', 'role' => null]],
        ]);

        $people = CompanyProfile::query()->first()->fields['people'];
        $this->assertCount(1, $people);
        $this->assertSame('Vadovas', $people[0]['role']);
        // Still the caller's entry: silence is filled in, precedence is not reversed.
        $this->assertSame('caller', $people[0]['source']);
    }

    public function test_a_stated_role_survives_a_later_run_that_cannot_reach_the_registry(): void
    {
        // The same loss as the test above, one door further out: within a run mergedPeople()
        // backfills the role from the source entry, but only when there IS a source entry. Here
        // there is not — run B cannot reach the registry, which is not always reachable and so fails
        // routinely — and mergePeople() used to replace fields['people'] wholesale, writing
        // `role: null` over a role the registry had already stated in run A.
        //
        // Two runs, deliberately: a single-run test is exactly the blind spot that let this
        // survive the within-run fix.
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius'], ['manager' => 'Tautrimas Girdvainis']),
            CollectionResult::none('registry_db', 'error', ['detail' => 'SQLSTATE[HY000] [2002] Connection timed out']),
        );
        $this->official->shouldReceive('supports')->andReturn(false);
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $service = $this->service();

        $service->collect(['code' => '111', 'name' => 'Azerna, UAB', 'session' => 'run-a']);

        $this->assertSame(
            [['name' => 'Tautrimas Girdvainis', 'role' => 'Vadovas', 'source' => 'registry_db']],
            CompanyProfile::query()->first()->fields['people'],
        );

        $out = $service->collect([
            'code' => '111',
            'name' => 'Azerna, UAB',
            'session' => 'run-b',
            'people' => [['name' => 'Tautrimas Girdvainis', 'role' => null]],
        ]);

        // Same company key, so the same profile row: one person, and the role the registry
        // stated in run A is still there.
        $this->assertSame(1, CompanyProfile::query()->count());
        $people = CompanyProfile::query()->first()->fields['people'];
        $this->assertCount(1, $people);
        $this->assertSame('Tautrimas Girdvainis', $people[0]['name']);
        $this->assertSame('Vadovas', $people[0]['role']);

        // And the run that could not reach the registry still ANSWERS with the role, because
        // the response is read back from the persisted profile.
        $this->assertSame('Vadovas', $out['profile']['people'][0]['role']);
    }

    public function test_registry_manager_is_stored_when_the_caller_sends_nobody(): void
    {
        $this->registryReportsManager('Registro Vadovas');

        $this->service()->collect(['name' => 'Azerna, UAB']);

        $people = CompanyProfile::query()->first()->fields['people'];
        $this->assertSame('Registro Vadovas', $people[0]['name']);
        $this->assertSame('registry_db', $people[0]['source']);
    }

    public function test_the_manager_column_is_stored_as_a_name_and_a_role_not_as_one_phrase(): void
    {
        // Most of the registry's `firms.manager` values glue the role onto the name.
        // Stored whole, the person becomes a phrase nothing can match — and the role we were
        // told is thrown away in favour of the generic 'Vadovas' the column implies.
        $this->registryReportsManager('Indrė Šilkūnienė, direktorė');

        $this->service()->collect(['name' => 'Azerna, UAB']);

        $people = CompanyProfile::query()->first()->fields['people'];
        $this->assertSame('Indrė Šilkūnienė', $people[0]['name']);
        $this->assertSame('direktorė', $people[0]['role']);
    }

    public function test_a_manager_column_value_that_names_nobody_is_kept_as_the_registry_holds_it(): void
    {
        // Two people in one field: no name can be read out of it, and guessing one of them
        // is exactly the confident-wrong-claim this rule exists to avoid. The value is still
        // stored, so the operator can see what the registry has — and the news layer reports
        // it as unresearched by name. 'Vadovas' stays the role: the column is the manager
        // field, and that much IS true of whoever this is.
        $this->registryReportsManager('Donatas Tarvydūnas ir Monika Šermukšnienė');

        $this->service()->collect(['name' => 'Azerna, UAB']);

        $people = CompanyProfile::query()->first()->fields['people'];
        $this->assertSame('Donatas Tarvydūnas ir Monika Šermukšnienė', $people[0]['name']);
        $this->assertSame('Vadovas', $people[0]['role']);
    }

    public function test_distinct_people_from_both_sides_are_all_kept(): void
    {
        $this->registryReportsManager('Registro Vadovas');

        $this->service()->collect([
            'name' => 'Azerna, UAB',
            'people' => [['name' => 'Kitas Zmogus', 'role' => 'Finansų direktorius']],
        ]);

        $people = CompanyProfile::query()->first()->fields['people'];
        $this->assertCount(2, $people);
        $this->assertEqualsCanonicalizing(
            ['Kitas Zmogus', 'Registro Vadovas'],
            array_column($people, 'name'),
        );
    }

    public function test_the_registry_manager_is_researched_not_only_stored(): void
    {
        // The whole defect in one assertion: the registry named the director, the
        // profile stored him, and the news dimension never heard of him. Two runs in
        // this system's history had a found manager and zero person-attributed
        // mentions, because collect() kept the merged list to itself.
        $this->registryReportsManager('Registro Vadovas');

        $researched = null;
        $this->news->shouldReceive('supports')->andReturn(true);
        $this->news->shouldReceive('collect')
            ->once()
            ->andReturnUsing(function (array $target) use (&$researched): CollectionResult {
                $researched = array_column((array) ($target['people'] ?? []), 'name');

                return CollectionResult::none('news', 'empty');
            });

        $this->service()->collect(['name' => 'Azerna, UAB']);

        $this->assertSame(['Registro Vadovas'], $researched);
    }

    public function test_the_caller_keeps_the_head_of_the_research_queue(): void
    {
        // Order is the budget: react.news.max_queries currently leaves room for ONE
        // person, so whoever is first is the only one searched. mergedPeople() puts the
        // caller first for a reason (they know the current role) and that order must
        // survive into the news target.
        $this->registryReportsManager('Registro Vadovas');

        $researched = null;
        $this->news->shouldReceive('supports')->andReturn(true);
        $this->news->shouldReceive('collect')
            ->once()
            ->andReturnUsing(function (array $target) use (&$researched): CollectionResult {
                $researched = array_column((array) ($target['people'] ?? []), 'name');

                return CollectionResult::none('news', 'empty');
            });

        $this->service()->collect([
            'name' => 'Azerna, UAB',
            'people' => [['name' => 'Kitas Zmogus', 'role' => 'Finansų direktorius']],
        ]);

        $this->assertSame(['Kitas Zmogus', 'Registro Vadovas'], $researched);
    }

    public function test_coverage_score_is_unaffected_by_people(): void
    {
        $this->registryReportsManager('Registro Vadovas');

        $result = $this->service()->collect([
            'name' => 'Azerna, UAB',
            'people' => [['name' => 'Kitas Zmogus', 'role' => null]],
        ]);

        // people is not one of ProfileCoverageScorer::FIELDS: one of six fields
        // present (address) must still score 1/6, and never appear in coverage.
        $this->assertEqualsWithDelta(0.167, $result['coverage']['overall'], 0.001);
        $this->assertArrayNotHasKey('people', $result['coverage']['fields']);
    }

    public function test_the_ladder_continues_past_the_old_threshold_while_declared_fields_are_missing(): void
    {
        config(['react.coverage.ladder_budget_s' => 120]);
        // the registry fills all six legacy fields — which used to end the run at 1.00.
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(new CollectionResult('registry_db', 'ok', [
            'description' => str_repeat('a', 200), 'activity' => str_repeat('b', 60),
            'emails' => ['a@b.lt'], 'phones' => ['+37060000000'],
            'address' => 'Vilnius', 'social_links' => ['https://x.lt'],
        ]));
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->once()->andReturn(
            new CollectionResult('official_site', 'ok', ['brands' => ['Acmena']]),
        );
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $out = $this->service()->collect(['name' => 'Acmena', 'fields' => ['description', 'brands']]);

        // official_site must have been reached: `brands` was still missing.
        $this->assertContains('official_site', $out['sources_used']);
        $this->assertSame('sufficient', $out['verdict']);
    }

    public function test_the_ladder_stops_once_every_declared_field_is_filled(): void
    {
        config(['react.coverage.ladder_budget_s' => 120]);
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius']),
        );
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldNotReceive('collect');
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $out = $this->service()->collect(['name' => 'Acmena', 'fields' => ['address']]);

        $this->assertSame('sufficient', $out['verdict']);
        $this->assertSame(['registry_db'], $out['sources_used']);
    }

    public function test_a_budget_cut_run_is_partial_and_not_data_limited(): void
    {
        // 0 means the budget is already spent: the first source still runs, the rest
        // do not. The verdict must say "we stopped", not "the data does not exist".
        config(['react.coverage.ladder_budget_s' => 0]);
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius']),
        );
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldNotReceive('collect');
        $this->openWeb->shouldReceive('supports')->andReturn(true);
        $this->openWeb->shouldNotReceive('collect');

        $out = $this->service()->collect(['name' => 'Acmena', 'fields' => ['address', 'brands']]);

        $this->assertSame('partial', $out['verdict']);
    }

    public function test_an_exhausted_ladder_with_gaps_is_data_limited(): void
    {
        config(['react.coverage.ladder_budget_s' => 120]);
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius']),
        );
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(new CollectionResult('official_site', 'empty', []));
        $this->openWeb->shouldReceive('supports')->andReturn(true);
        $this->openWeb->shouldReceive('collect')->andReturn(new CollectionResult('open_web', 'empty', []));

        $out = $this->service()->collect(['name' => 'Acmena', 'fields' => ['address', 'brands']]);

        // Every source really ran and `brands` is still absent: a real ceiling.
        $this->assertSame('data_limited', $out['verdict']);
    }

    public function test_declared_fields_are_passed_to_every_source(): void
    {
        config(['react.coverage.ladder_budget_s' => 120]);

        // Capture what the source actually received rather than asserting on a
        // Mockery constraint — a real assertion names the failure when it breaks.
        $seen = null;
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturnUsing(function (array $target) use (&$seen): CollectionResult {
            $seen = $target['declared_fields'] ?? null;

            return new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius']);
        });
        $this->official->shouldReceive('supports')->andReturn(false);
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $this->service()->collect(['name' => 'Acmena', 'fields' => ['address', 'brands']]);

        $this->assertSame(['address', 'brands'], $seen);
    }

    public function test_no_fields_key_declares_the_legacy_six_by_default(): void
    {
        // The live API consumer never sends fields[] and stores `verdict`/
        // `coverage` in its own DB — the default declared set must stay the
        // pre-catalogue six (the seed's `required` fields), not silently widen to
        // all 12 enabled catalogue fields, or its stored scores would drift on
        // this deploy with nobody having asked for that.
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius']),
        );
        $this->official->shouldReceive('supports')->andReturn(false);
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $out = $this->service()->collect(['name' => 'Acmena']);

        $this->assertEqualsCanonicalizing(FieldRequirements::LEGACY, array_keys($out['coverage']['fields']));
        $this->assertEqualsWithDelta(0.167, $out['coverage']['overall'], 0.001);
    }

    public function test_an_all_unknown_request_still_reports_what_the_ladder_collected(): void
    {
        // RunRequest deliberately lets a well-formed but unrecognised key through
        // (spec §8: drop it, never 422). If that leaves the declared set empty, the
        // run still fetches the site and builds a profile — and then reports
        // field_status: {} with the website nowhere in sources_used, i.e. a run that
        // looks like it worked while every collected value was discarded.
        config(['react.coverage.ladder_budget_s' => 120]);
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(new CollectionResult('registry_db', 'empty', []));
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(new CollectionResult('official_site', 'ok', [
            'description' => $this->longText(),
            'activity' => 'Aliuminio-stiklo fasadai, langai ir durys',
            'emails' => ['info@acmena.lt'],
            'phones' => ['+37060000000'],
            'address' => 'Vilnius',
            'social_links' => ['https://x.lt/acmena'],
        ], ['domain' => 'acmena.lt', 'quality' => ['verdict' => 'pass']]));
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $out = $this->service()->collect([
            'name' => 'Acmena',
            'session' => 'sunknown',
            'fields' => ['nera_tokio_lauko'],
        ]);

        $this->assertContains('official_site', $out['sources_used']);
        $this->assertNotSame([], $out['field_status']);
        $this->assertSame('found', $out['field_status']['description']);
        $this->assertSame(['info@acmena.lt'], $out['profile']['emails']);
        $this->assertSame('official_site', $out['field_sources']['description']);
        $this->assertSame('sufficient', $out['verdict']);
    }

    public function test_field_status_separates_supplied_found_and_not_found(): void
    {
        config(['react.coverage.ladder_budget_s' => 120]);
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius']),
        );
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(new CollectionResult('official_site', 'empty', []));
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $out = $this->service()->collect(['name' => 'Acmena', 'fields' => ['address', 'brands']]);

        $this->assertSame('found', $out['field_status']['address']);
        // The ladder really finished, so the gap is a fact about the company.
        $this->assertSame('not_found', $out['field_status']['brands']);
    }

    public function test_a_budget_cut_leaves_missing_fields_unresolved_rather_than_not_found(): void
    {
        config(['react.coverage.ladder_budget_s' => 0]);
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius']),
        );
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldNotReceive('collect');
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $out = $this->service()->collect(['name' => 'Acmena', 'fields' => ['address', 'brands']]);

        // We stopped early: claiming `not_found` would tell the consumer the company
        // has no brands when we never looked.
        $this->assertArrayNotHasKey('brands', $out['field_status']);
    }

    public function test_a_caller_supplied_field_is_recorded_as_supplied_and_never_searched(): void
    {
        config(['react.coverage.ladder_budget_s' => 120]);
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius']),
        );
        $this->official->shouldReceive('supports')->andReturn(false);
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $out = $this->service()->collect([
            'name' => 'Acmena',
            'fields' => ['address', 'description'],
            'known_fields' => ['description' => str_repeat('a', 200)],
        ]);

        $this->assertSame('supplied', $out['field_status']['description']);
        $this->assertSame('found', $out['field_status']['address']);
        $this->assertSame('caller', $out['field_sources']['description']);
    }

    public function test_a_source_cannot_overwrite_a_caller_supplied_field(): void
    {
        config(['react.coverage.ladder_budget_s' => 120]);
        // the registry also has a description, and a longer one that would score higher.
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['description' => str_repeat('b', 500)]),
        );
        $this->official->shouldReceive('supports')->andReturn(false);
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $out = $this->service()->collect([
            'name' => 'Acmena',
            'fields' => ['description'],
            'known_fields' => ['description' => 'Kviečiančiojo tekstas'],
        ]);

        $this->assertSame('Kviečiančiojo tekstas', $out['profile']['description']);
        $this->assertSame('caller', $out['field_sources']['description']);
        $this->assertSame('supplied', $out['field_status']['description']);
    }

    public function test_every_named_person_the_registry_knows_reaches_the_profile(): void
    {
        // A firm can have several named people in the registry's manager
        // tables, and the collector used to store none, because it only ever read the single
        // `manager` column. The manager is kept as the fallback for firms missing from
        // those tables, so a name appearing in both must not be listed twice.
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius'], [
                'manager' => 'Kristupas Daubys',
                'people' => [
                    ['name' => 'Jolanta Girdenytė', 'role' => 'Direktorė'],
                    ['name' => 'Kristupas Daubys', 'role' => null],
                ],
            ]),
        );
        $this->official->shouldReceive('supports')->andReturn(false);
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $out = $this->service()->collect(['code' => '300000123', 'name' => 'Didmena LT, UAB']);

        $profile = CompanyProfile::where('company_key', 'code:300000123')->firstOrFail();
        $people = $profile->fields['people'] ?? [];

        // Keyed by name: the order these are collected in is an implementation detail,
        // the set and the roles are the contract.
        $byName = array_column($people, null, 'name');

        $this->assertSame(['Kristupas Daubys', 'Jolanta Girdenytė'], array_keys($byName));
        $this->assertSame('Direktorė', $byName['Jolanta Girdenytė']['role']);
        $this->assertSame('registry_db', $byName['Jolanta Girdenytė']['source']);
        // Daubys is in both sources; the `manager` column states his role and the manager
        // tables do not, so the run must keep the role rather than lose it to a null.
        $this->assertSame('Vadovas', $byName['Kristupas Daubys']['role']);
    }

    public function test_the_registry_people_survive_a_row_that_mapped_no_declared_field(): void
    {
        // accepted() also demands fields !== [], so a sparse registry row lost its
        // manager too. Identity by company code has nothing to do with how many of OUR
        // declared fields the row happened to fill.
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', [], ['manager' => 'Registro Vadovas']),
        );
        $this->official->shouldReceive('supports')->andReturn(false);
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $this->service()->collect(['name' => 'Azerna, UAB']);

        $people = CompanyProfile::query()->first()->fields['people'];
        $this->assertSame('Registro Vadovas', $people[0]['name']);
        $this->assertSame('registry_db', $people[0]['source']);
    }

    public function test_a_judge_rejected_source_never_contributes_its_people(): void
    {
        // The people gate reads `ok && ($accepted || registry_db)`, and the $accepted half
        // is the whole defence. A source whose identity rests on the match judge, and which
        // the judge REJECTED, is content about some OTHER company: storing its manager
        // would file a stranger's director as this firm's — the same false-attribution
        // class as a namesake news mention, except written into the profile rather than
        // shown once. The registry arm is the deliberate exception, because its row is keyed
        // by company code and needs no verdict.
        //
        // Only CompanyRegistrySource populates that meta today, so until a second source does,
        // this test is the only thing standing between the invariant and a silent removal.
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius'], ['manager' => 'Registro Vadovas']),
        );
        $this->official->shouldReceive('supports')->andReturn(false);

        // 'ok' with fields, so it reaches the judge at all, carrying people in both shapes
        // peopleFrom() reads — the `manager` column and the manager-table list.
        $this->openWeb->shouldReceive('supports')->andReturn(true);
        $this->openWeb->shouldReceive('collect')->andReturn(new CollectionResult(
            'open_web',
            'ok',
            ['description' => $this->longText()],
            [
                'manager' => 'Svetimas Vadovas',
                'people' => [['name' => 'Svetimas Zmogus', 'role' => 'Direktorius']],
            ],
        ));
        $this->judge->shouldReceive('judge')->andReturn(
            ['verdict' => 'fail', 'match_confidence' => 0.1, 'data_coverage' => 0.5, 'reasons' => ['different company']]
        );

        $out = $this->service()->collect(['code' => '222', 'name' => 'Azerna, UAB', 'session' => 's-reject']);

        // The premise, asserted rather than assumed: the judge really did reject it, so a
        // missing stranger below is the identity gate's work and not some other accident.
        $rejected = CollectionAction::where('run_hash', $out['run_hash'])->where('source', 'open_web')->first();
        $this->assertNotNull($rejected);
        $this->assertSame('rejected', $rejected->status);

        $people = CompanyProfile::query()->first()->fields['people'];
        $this->assertSame(['Registro Vadovas'], array_column($people, 'name'));
    }

    public function test_a_supplied_value_for_an_undeclared_field_is_ignored(): void
    {
        config(['react.coverage.ladder_budget_s' => 120]);
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Vilnius']),
        );
        $this->official->shouldReceive('supports')->andReturn(false);
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $out = $this->service()->collect([
            'name' => 'Acmena',
            'fields' => ['address'],
            'known_fields' => ['brands' => ['Acmena Pro']],
        ]);

        // Nobody declared `brands`, so a value for it is noise, not data.
        $this->assertArrayNotHasKey('brands', $out['field_status']);
        $this->assertArrayNotHasKey('brands', $out['profile'] ?? []);
    }

    public function test_a_supplied_field_is_never_requested_from_any_source(): void
    {
        config(['react.coverage.ladder_budget_s' => 120]);
        // A short supplied value scores under the gap threshold on the raw scorer —
        // this is exactly the case that must be resolved by provenance, not by score.
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(new CollectionResult('registry_db', 'empty', []));
        // If the ladder wrongly continues, these fail as a TypeError about the final
        // class CollectionResult, not as Mockery's usual unexpected-call message:
        // CollectionResult is final, so Mockery cannot auto-stub a return value for a
        // call it was told to forbid. A confusing message here still means one thing —
        // a source was asked for a field the caller already supplied.
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldNotReceive('collect');
        $this->openWeb->shouldReceive('supports')->andReturn(true);
        $this->openWeb->shouldNotReceive('collect');

        $out = $this->service()->collect([
            'name' => 'Acmena',
            'fields' => ['description'],
            'known_fields' => ['description' => 'Kviečiančiojo tekstas'],
        ]);

        $this->assertSame('supplied', $out['field_status']['description']);
        // the registry (the mandatory first source) ran but yielded nothing — nobody
        // actually contributed the field.
        $this->assertSame([], $out['sources_used']);
    }

    public function test_a_run_where_no_source_contributes_anything_is_none_not_sufficient(): void
    {
        // Every source runs cleanly but yields nothing. The initial $coverage must be
        // truthful ("nothing collected yet" = every declared field still a gap), or
        // the placeholder's lying empty `missing` wins the verdict's first branch.
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(new CollectionResult('registry_db', 'empty', []));
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(new CollectionResult('official_site', 'empty', []));
        $this->openWeb->shouldReceive('supports')->andReturn(true);
        $this->openWeb->shouldReceive('collect')->andReturn(new CollectionResult('open_web', 'empty', []));

        $out = $this->service()->collect(['name' => 'Acmena']);

        $this->assertSame('none', $out['verdict']);
        $this->assertSame([], $out['sources_used']);
    }

    public function test_a_short_supplied_value_beats_a_longer_previously_stored_value(): void
    {
        config(['react.coverage.ladder_budget_s' => 120]);
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(new CollectionResult('registry_db', 'empty', []));
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(
            new CollectionResult('official_site', 'ok', ['description' => $this->longText()]),
        );
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $service = $this->service();

        // Run 1: official_site stores a long, high-scoring description.
        $service->collect(['name' => 'Acmena', 'fields' => ['description']]);

        // Run 2: the caller now supplies a short value for the same field/company.
        // Rule 1 says the supplied value is merged with `caller` provenance
        // unconditionally — it must win even though it scores lower than what is
        // already stored.
        $out = $service->collect([
            'name' => 'Acmena',
            'fields' => ['description'],
            'known_fields' => ['description' => 'Trumpas tekstas'],
        ]);

        $this->assertSame('Trumpas tekstas', $out['profile']['description']);
        $this->assertSame('caller', $out['field_sources']['description']);
        $this->assertSame('supplied', $out['field_status']['description']);
    }

    public function test_a_supplied_scalar_is_coerced_to_the_catalogues_array_type(): void
    {
        // The caller's shape survives from the request untouched, and a supplied
        // value is authoritative — never re-derived — so a string here would be
        // persisted and returned in a field the consumer's schema declares a list.
        config(['react.coverage.ladder_budget_s' => 120]);
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(new CollectionResult('registry_db', 'empty', []));
        $this->official->shouldReceive('supports')->andReturn(false);
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $out = $this->service()->collect([
            'name' => 'Acmena',
            'fields' => ['emails'],
            'known_fields' => ['emails' => 'info@acmena.lt'],
        ]);

        $this->assertSame(['info@acmena.lt'], $out['profile']['emails']);
        $this->assertSame('supplied', $out['field_status']['emails']);
        // ...and the coerced shape is what got persisted, not just what we returned.
        $this->assertSame(['info@acmena.lt'], CompanyProfile::query()->first()->fields['emails']);
    }

    public function test_a_supplied_list_is_joined_for_a_catalogue_string_field(): void
    {
        config(['react.coverage.ladder_budget_s' => 120]);
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(new CollectionResult('registry_db', 'empty', []));
        $this->official->shouldReceive('supports')->andReturn(false);
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        // `address` is seeded as type `string`; a list would break the same schema
        // contract in the other direction.
        $out = $this->service()->collect([
            'name' => 'Acmena',
            'fields' => ['address'],
            'known_fields' => ['address' => ['Laisvės pr. 1, Vilnius', 'Kaunas']],
        ]);

        $this->assertSame('Laisvės pr. 1, Vilnius, Kaunas', $out['profile']['address']);
        $this->assertSame('supplied', $out['field_status']['address']);
    }

    public function test_overall_score_is_synced_after_a_supplied_only_premerge(): void
    {
        // Two declared fields, only one supplied and nothing else ever contributes —
        // mergeProfile()'s internal field_coverage average only ever sees the
        // supplied key, so CompanyProfile.overall_score would read ~1.0 (looks
        // complete) while the true gap-aware overall (address still missing) is 0.5.
        // CollectionRun.overall_score already gets the correct value from $overall;
        // the bug is that CompanyProfile never gets resynced with it.
        config(['react.coverage.ladder_budget_s' => 120]);
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(new CollectionResult('registry_db', 'empty', []));
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(new CollectionResult('official_site', 'empty', []));
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $out = $this->service()->collect([
            'name' => 'Acmena',
            'fields' => ['address', 'description'],
            'known_fields' => ['description' => str_repeat('a', 200)],
        ]);

        $profile = CompanyProfile::query()->first();
        $run = CollectionRun::where('run_hash', $out['run_hash'])->first();

        $this->assertEqualsWithDelta(0.5, $profile->overall_score, 0.001);
        $this->assertSame($run->overall_score, $profile->overall_score);
        $this->assertEqualsWithDelta($out['coverage']['overall'], $profile->overall_score, 0.001);
    }

    public function test_a_caller_supplied_field_from_an_earlier_run_is_searched_again_when_not_resupplied(): void
    {
        config(['react.coverage.ladder_budget_s' => 120]);
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(new CollectionResult('registry_db', 'empty', []));
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->once()->andReturn(
            new CollectionResult('official_site', 'ok', ['description' => $this->longText()]),
        );
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $service = $this->service();

        // Run 1: the caller supplies `description` directly — the ladder never
        // reaches official_site for it (proven above; here we only need the state
        // it leaves behind).
        $service->collect([
            'name' => 'Acmena',
            'fields' => ['description'],
            'known_fields' => ['description' => 'Pirmojo skambučio aprašymas'],
        ]);

        // Run 2: same company, same declared field, but no known_fields this time.
        // field_sources is cumulative across runs, so the `caller` label from run 1
        // is still on the stored profile — it must NOT be read as "already
        // resolved". official_site must actually be searched (asserted via the
        // `->once()` total call-count above, not just via field_status).
        $out = $service->collect(['name' => 'Acmena', 'fields' => ['description']]);

        $this->assertSame('official_site', $out['field_sources']['description']);
        $this->assertSame('found', $out['field_status']['description']);
    }

    public function test_a_comma_separated_scalar_becomes_several_values_for_a_list_field(): void
    {
        // The console's key/value table gives one input per field, so two emails arrive as
        // "a@b.lt, c@d.lt". Wrapping that whole string as one element would store both
        // addresses as a single unusable address.
        config(['react.coverage.ladder_budget_s' => 120]);
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(new CollectionResult('registry_db', 'empty', []));
        $this->official->shouldReceive('supports')->andReturn(false);
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $out = $this->service()->collect([
            'name' => 'Acmena',
            'fields' => ['emails'],
            'known_fields' => ['emails' => 'info@acmena.lt , sales@acmena.lt,  '],
        ]);

        $this->assertSame(['info@acmena.lt', 'sales@acmena.lt'], $out['profile']['emails']);
    }

    public function test_a_run_that_throws_is_closed_as_failed_not_left_running(): void
    {
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andThrow(new \RuntimeException('Unable to create a directory'));

        try {
            $this->service()->collect(['code' => '444', 'name' => 'Acmena', 'session' => 's-fail']);
            $this->fail('the exception must still reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('Unable to create a directory', $e->getMessage());
        }

        $run = CollectionRun::query()->where('company_code', '444')->first();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->finished_at);
    }

    public function test_the_verified_official_site_domain_is_stored_on_the_profile(): void
    {
        $this->companyRegistry->shouldReceive('supports')->andReturn(false);
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(
            new CollectionResult('official_site', 'ok', ['description' => $this->longText()], ['domain' => 'www.Acmena.lt', 'quality' => ['verdict' => 'pass']])
        );
        $this->openWeb->shouldReceive('supports')->andReturn(false);

        $this->service()->collect(['code' => '555', 'name' => 'Acmena', 'session' => 's-dom']);

        $this->assertSame('acmena.lt', \App\Models\CompanyProfile::query()->where('company_code', '555')->value('domain'));
    }
}
