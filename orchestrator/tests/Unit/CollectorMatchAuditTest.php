<?php

namespace Tests\Unit;

use App\IO\Contracts\LlmClient;
use App\Models\CollectionAction;
use App\Models\CompanyProfile;
use App\Services\CollectionResult;
use App\Services\CollectorService;
use App\Services\CompanyMatchJudge;
use App\Services\ContactsSource;
use App\Services\FieldRequirements;
use App\Services\MatchAssessment;
use App\Services\NewsSource;
use App\Services\OfficialSiteSource;
use App\Services\OpenWebSource;
use App\Services\ProfileCoverageScorer;
use App\Services\CompanyRegistrySource;
use App\Services\RunRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The match gate has to be auditable after the fact: for every source whose data ended up
 * in a company's profile, the stored run must say what verdict that acceptance rested on.
 *
 * A run once accepted `open_web` — contributing description, activity, address and a
 * "social link" that was actually the parent group's website — and stored a detail of
 * `{"pages":[...]}`, nothing else. Whether the judge had passed it or had been unreachable
 * and failed open was, by then, unanswerable. These tests pin the answer into storage.
 */
class CollectorMatchAuditTest extends TestCase
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
        $this->companyRegistry->shouldReceive('supports')->andReturn(false)->byDefault();

        $this->official = Mockery::mock(OfficialSiteSource::class);
        $this->official->shouldReceive('name')->andReturn('official_site')->byDefault();
        $this->official->shouldReceive('supports')->andReturn(false)->byDefault();

        $this->openWeb = Mockery::mock(OpenWebSource::class);
        $this->openWeb->shouldReceive('name')->andReturn('open_web')->byDefault();
        $this->openWeb->shouldReceive('supports')->andReturn(false)->byDefault();

        $this->contacts = Mockery::mock(ContactsSource::class);
        $this->contacts->shouldReceive('name')->andReturn('contacts')->byDefault();
        $this->contacts->shouldReceive('supports')->andReturn(false)->byDefault();

        $this->news = Mockery::mock(NewsSource::class);
        $this->news->shouldReceive('name')->andReturn('news')->byDefault();
        $this->news->shouldReceive('supports')->andReturn(false)->byDefault();

        $this->judge = Mockery::mock(CompanyMatchJudge::class);
        $this->judge->shouldReceive('rejects')->andReturnUsing(static fn (array $r): bool => ($r['verdict'] ?? 'warn') === 'fail')->byDefault();
    }

    private function service(?CompanyMatchJudge $judge = null): CollectorService
    {
        return new CollectorService(
            new RunRegistry(),
            new ProfileCoverageScorer(),
            $judge ?? $this->judge,
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

    /**
     * The stored `match` block for one source of one run.
     *
     * @return array<string, mixed>|null
     */
    private function storedMatch(string $runHash, string $source): ?array
    {
        $action = CollectionAction::where('run_hash', $runHash)->where('source', $source)->first();
        $decoded = json_decode((string) ($action->detail ?? ''), true);

        return is_array($decoded) && is_array($decoded['match'] ?? null) ? $decoded['match'] : null;
    }

    /**
     * The invariant, stated over whatever the run happened to accept: a source whose fields
     * were merged into the profile must carry the verdict its acceptance rested on. This is
     * the check that was impossible to answer for the `open_web` acceptance in §11.1 — the
     * judge was consulted inline and its answer thrown away.
     */
    public function test_every_accepted_source_records_the_verdict_it_rested_on(): void
    {
        $this->openWeb->shouldReceive('supports')->andReturn(true);
        $this->openWeb->shouldReceive('collect')->andReturn(
            new CollectionResult('open_web', 'ok', ['description' => $this->longText(), 'activity' => 'Aliuminio fasadai'], ['pages' => ['https://acmena.lt/apie']])
        );
        $this->contacts->shouldReceive('supports')->andReturn(true);
        $this->contacts->shouldReceive('collect')->andReturn(
            new CollectionResult('contacts', 'ok', ['emails' => ['info@acmena.lt']], ['content' => 'Acmena UAB info@acmena.lt'])
        );
        $this->judge->shouldReceive('judge')->andReturn([
            'verdict' => 'pass', 'verified' => true, 'match_confidence' => 0.93, 'data_coverage' => 0.6, 'reasons' => ['LT address matches'],
        ]);

        $out = $this->service()->collect(['code' => '111', 'name' => 'Acmena', 'session' => 'audit-1']);

        $this->assertSame(['open_web', 'contacts'], $out['sources_used']);

        foreach ($out['sources_used'] as $source) {
            $match = $this->storedMatch($out['run_hash'], $source);

            $this->assertIsArray($match, "accepted source {$source} stored no match verdict");
            $this->assertTrue($match['accepted'], "stored match for {$source} contradicts the acceptance");
            $this->assertSame('pass', $match['verdict'], "accepted source {$source} recorded no verdict");
            $this->assertSame(MatchAssessment::BASIS_JUDGE, $match['basis']);
            $this->assertTrue($match['verified']);
            $this->assertSame(0.93, $match['match_confidence']);
            $this->assertSame(['LT address matches'], $match['reasons']);
        }
    }

    /**
     * The fail-open itself, end to end through the REAL judge: the LLM throws, the judge
     * returns its non-blocking 'warn', the source is accepted — and the stored row says
     * `unverified`, not `warn`. An LLM outage that silently accepts everything is a
     * deliberate operational trade; one that accepts everything while LOOKING judged is the
     * defect.
     */
    public function test_an_acceptance_on_a_judge_outage_is_recorded_unverified(): void
    {
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')->andThrow(new \RuntimeException('LLM down'));

        $this->openWeb->shouldReceive('supports')->andReturn(true);
        $this->openWeb->shouldReceive('collect')->andReturn(
            new CollectionResult('open_web', 'ok', ['description' => $this->longText(), 'social_links' => ['https://www.baltkela.eu']], ['pages' => ['https://baltkela.eu']])
        );

        $out = $this->service(new CompanyMatchJudge($llm))->collect(['code' => '222', 'name' => 'Girmeta', 'session' => 'audit-2']);

        // The decision is unchanged: a judge outage must not stop collection.
        $this->assertContains('open_web', $out['sources_used']);
        $this->assertArrayHasKey('description', $out['profile']);

        $match = $this->storedMatch($out['run_hash'], 'open_web');
        $this->assertIsArray($match);
        $this->assertSame(MatchAssessment::VERDICT_UNVERIFIED, $match['verdict'], 'a fail-open must never be stored as a judged verdict');
        $this->assertSame(MatchAssessment::BASIS_JUDGE_UNAVAILABLE, $match['basis']);
        $this->assertFalse($match['verified']);
        $this->assertNull($match['match_confidence']);
        $this->assertTrue($match['accepted']);
        $this->assertStringContainsString('LLM down', implode(' ', $match['reasons']));

        // Same fact on the consumer's side of the wall, not only in the audit trail.
        $this->assertFalse($out['match_verdicts']['open_web']['verified']);
    }

    /**
     * "Nobody produced a verdict" and "a judge said maybe" are different facts and must not
     * share a word in storage. `official_site` accepts both, unchanged — the profile the
     * pipeline hands over may legitimately carry no `quality` block (a profile.json
     * rehydrated by CHECK_ASSETS from an older run) — but only one of them is a verdict.
     */
    public function test_a_missing_verdict_is_recorded_unverified_and_a_judged_warn_stays_warn(): void
    {
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(
            new CollectionResult('official_site', 'ok', ['description' => $this->longText()], ['domain' => 'acmena.lt']),
            new CollectionResult('official_site', 'ok', ['description' => $this->longText()], [
                'domain' => 'acmena.lt',
                'quality' => ['verdict' => 'warn', 'verified' => true, 'match_confidence' => 0.55, 'data_coverage' => 0.4, 'reasons' => ['name matches, location unclear']],
            ]),
        );

        $noVerdict = $this->service()->collect(['code' => '333', 'name' => 'Acmena', 'session' => 'audit-3a']);
        $judgedWarn = $this->service()->collect(['code' => '333', 'name' => 'Acmena', 'session' => 'audit-3b']);

        // Both accept — that behaviour is deliberately untouched.
        $this->assertContains('official_site', $noVerdict['sources_used']);
        $this->assertContains('official_site', $judgedWarn['sources_used']);

        $missing = $this->storedMatch($noVerdict['run_hash'], 'official_site');
        $this->assertIsArray($missing);
        $this->assertSame(MatchAssessment::VERDICT_UNVERIFIED, $missing['verdict'], 'no verdict must not be recorded as a warn');
        $this->assertSame(MatchAssessment::BASIS_NO_VERDICT, $missing['basis']);
        $this->assertFalse($missing['verified']);

        $warned = $this->storedMatch($judgedWarn['run_hash'], 'official_site');
        $this->assertIsArray($warned);
        $this->assertSame('warn', $warned['verdict'], "a judge's warn must survive as a warn");
        $this->assertSame(MatchAssessment::BASIS_STORED_VERDICT, $warned['basis']);
        $this->assertTrue($warned['verified']);
        $this->assertSame(0.55, $warned['match_confidence']);

        $this->assertNotSame($missing['verdict'], $warned['verdict'], 'no verdict and warn must be distinguishable in storage');
    }

    /**
     * A stored verdict written before the `verified` flag existed is read by its signature:
     * the judge derives pass/fail from a number, so a 'warn' carrying no match_confidence is
     * the fail-open's own shape (or a model answer with an unusable confidence) — never a
     * judgement someone reached.
     */
    public function test_a_confidence_less_warn_from_an_older_blob_is_not_treated_as_a_verdict(): void
    {
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(
            new CollectionResult('official_site', 'ok', ['description' => $this->longText()], [
                'domain' => 'acmena.lt',
                'quality' => ['verdict' => 'warn', 'match_confidence' => null, 'data_coverage' => null, 'reasons' => ['judge error: 529 overloaded']],
            ])
        );

        $out = $this->service()->collect(['code' => '444', 'name' => 'Acmena', 'session' => 'audit-4']);

        $match = $this->storedMatch($out['run_hash'], 'official_site');
        $this->assertIsArray($match);
        $this->assertSame(MatchAssessment::VERDICT_UNVERIFIED, $match['verdict']);
        $this->assertSame(MatchAssessment::BASIS_JUDGE_UNAVAILABLE, $match['basis']);
    }

    /**
     * The source that reaches identity without asking the judge says which mechanism it used,
     * so a recorded 'pass' can never be mistaken for an LLM opinion: the registry row is keyed
     * by company code.
     */
    public function test_non_judge_acceptances_record_the_mechanism_they_rested_on(): void
    {
        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', [
                'address' => 'Laisvės pr. 1, Vilnius',
                'social_links' => ['https://linkedin.com/company/acmena'],
            ], ['employees' => 10])
        );
        $this->judge->shouldNotReceive('judge');

        $out = $this->service()->collect(['code' => '555', 'name' => 'Acmena', 'session' => 'audit-5']);

        $registry = $this->storedMatch($out['run_hash'], 'registry_db');
        $this->assertIsArray($registry);
        $this->assertSame('pass', $registry['verdict']);
        $this->assertSame(MatchAssessment::BASIS_REGISTRY_CODE, $registry['basis']);
    }

    /**
     * A field is only as verified as the source that contributed it. Observed in a live run
     * with an invalid API key: `official_site` was accepted `unverified` during a total LLM
     * outage and contributed the social links — so the stored field must read `unverified`
     * too, and a consumer filtering on `verified` must be left with nothing.
     */
    public function test_a_field_from_an_unverified_source_cannot_claim_verification(): void
    {
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(
            new CollectionResult('official_site', 'ok', ['social_links' => ['https://linkedin.com/company/stranger']], [
                'domain' => 'stranger.lt',
                'quality' => ['verdict' => 'warn', 'verified' => false, 'match_confidence' => null, 'data_coverage' => null, 'reasons' => ['judge error: LLM down']],
            ])
        );

        $out = $this->service()->collect(['code' => '999', 'name' => 'Hausta Group', 'session' => 'audit-9']);

        // Still accepted: the decision is unchanged, only the claim is.
        $this->assertSame(['official_site'], $out['sources_used']);

        $root = $this->storedMatch($out['run_hash'], 'official_site');
        $this->assertFalse($root['verified'], 'precondition: the source of the links was not verified');

        $this->assertSame(CompanyProfile::VERIFICATION_UNVERIFIED, $out['field_verification']['social_links']['status']);
        $this->assertSame('official_site', $out['field_verification']['social_links']['source']);

        $this->assertSame([], array_keys(array_filter(
            $out['match_verdicts'],
            static fn (array $match): bool => $match['verified'] === true,
        )));
    }

    /**
     * A profile row written before verification was stored — which is EVERY row that existed
     * when the column was added — holds no record, and no record may read as verification. It
     * reads `unknown`: distinct from a value we know nobody verified, because unknown clears
     * itself the next time a verified source touches the field while unverified is an outage or
     * a mismatch that did not clear. Getting this backwards would mark the whole accumulated
     * history as checked, which is strictly worse than the defect this work started from.
     */
    public function test_a_row_written_before_verification_was_stored_reads_unknown_not_verified(): void
    {
        CompanyProfile::create([
            'company_key' => 'code:1010',
            'company_code' => '1010',
            'name' => 'Acmena',
            'fields' => ['social_links' => ['https://linkedin.com/company/acmena']],
            'field_coverage' => ['social_links' => 1.0],
            'field_sources' => ['social_links' => 'official_site'],
            'overall_score' => 0.2,
        ]);

        $out = $this->service()->collect(['code' => '1010', 'name' => 'Acmena', 'session' => 'audit-10']);

        $this->assertSame(CompanyProfile::VERIFICATION_UNKNOWN, $out['field_verification']['social_links']['status'], 'a historical row must never confer verification');
    }

    /**
     * REQUIREMENT: verification survives across runs. A field a genuinely verified
     * `official_site` contributed in run 1 still reads as verified in run 2, where that source
     * is never seen again — which is the whole reason it is stored rather than re-derived, and
     * what closes the permanently-`unverified` state the label-based version produced.
     */
    public function test_a_verified_contribution_still_reads_verified_in_a_later_run(): void
    {
        $this->official->shouldReceive('supports')->andReturn(true, false);
        $this->official->shouldReceive('collect')->andReturn(
            new CollectionResult('official_site', 'ok', [
                'description' => $this->longText(),
                'social_links' => ['https://linkedin.com/company/acmena'],
            ], [
                'domain' => 'acmena.lt',
                'quality' => ['verdict' => 'pass', 'verified' => true, 'match_confidence' => 0.97, 'data_coverage' => 0.8, 'reasons' => ['domain matches']],
            ])
        );

        $first = $this->service()->collect(['code' => '1313', 'name' => 'Acmena', 'session' => 'audit-13a']);
        $this->assertSame(CompanyProfile::VERIFICATION_VERIFIED, $first['field_verification']['social_links']['status']);

        // Run 2: official_site is not available at all, so nothing re-establishes anything.
        $second = $this->service()->collect(['code' => '1313', 'name' => 'Acmena', 'session' => 'audit-13b']);

        $this->assertSame([], $second['sources_used']);
        $this->assertSame(CompanyProfile::VERIFICATION_VERIFIED, $second['field_verification']['social_links']['status'], 'the field was verified in an earlier run and that survives');
        $this->assertSame('official_site', $second['field_verification']['social_links']['source']);
    }

    /**
     * The permanent-`unverified` state the reviewer measured, closed. `social_links` scores
     * 1.0/0.0 and mergeProfile() relabels only on a strict increase, so the first contributor
     * owns `field_sources['social_links']` forever — here `open_web`, with no verification
     * record. A verified `official_site` re-contributing the identical links corroborates the
     * STORED value, so the verification moves even though the owner cannot.
     */
    public function test_a_verified_source_can_establish_verification_for_a_field_it_does_not_own(): void
    {
        CompanyProfile::create([
            'company_key' => 'code:1212',
            'company_code' => '1212',
            'name' => 'Acmena',
            'fields' => ['social_links' => ['https://linkedin.com/company/acmena']],
            'field_coverage' => ['social_links' => 1.0],
            'field_sources' => ['social_links' => 'open_web'],
            'overall_score' => 0.2,
        ]);

        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->andReturn(
            new CollectionResult('official_site', 'ok', [
                'description' => $this->longText(),
                'social_links' => ['https://linkedin.com/company/acmena'],
            ], [
                'domain' => 'acmena.lt',
                'quality' => ['verdict' => 'pass', 'verified' => true, 'match_confidence' => 0.96, 'data_coverage' => 0.8, 'reasons' => []],
            ])
        );

        $out = $this->service()->collect(['code' => '1212', 'name' => 'Acmena', 'session' => 'audit-12']);

        $this->assertSame(CompanyProfile::VERIFICATION_VERIFIED, $out['field_verification']['social_links']['status'], 'a verified source corroborated the stored links');
        $this->assertSame('official_site', $out['field_verification']['social_links']['source'], 'who verified the links, not who owns the field');

        // The owner provably did not move, so only the stored verification could have answered.
        $profile = CompanyProfile::where('company_key', 'code:1212')->first();
        $this->assertSame('open_web', $profile->field_sources['social_links']);
    }

    /**
     * `unknown` does NOT clear itself for an already-enriched firm, and the report says so
     * because of this test. The registry alone fills every declared field, so the ladder's
     * coverage break ends the run at the first source: `official_site::collect()` is never
     * called, and the fields only it can speak for keep whatever record they have — nothing —
     * on every future run. This pins the honest default; the pair below pins the way out.
     */
    public function test_a_complete_profile_stops_at_the_registry_so_unknown_does_not_clear_itself(): void
    {
        $this->seedCompleteProfileWithNoVerification('code:1414');

        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Laisvės pr. 1, Vilnius'], [])
        );
        // The one source that could speak for `description` is never even asked. Given a
        // return value on purpose: a bare shouldNotReceive() makes Mockery try to auto-mock
        // the final CollectionResult when the expectation is violated, and the resulting
        // error talks about final classes instead of about the call that should not have
        // happened.
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->never()->andReturn(
            new CollectionResult('official_site', 'empty', [])
        );

        $out = $this->service()->collect(['code' => '1414', 'name' => 'Acmena', 'session' => 'audit-14']);

        $this->assertSame(['registry_db'], $out['sources_used']);
        $this->assertSame(
            CompanyProfile::VERIFICATION_UNKNOWN,
            $out['field_verification']['description']['status'],
            'nothing in this run could speak for a field the registry does not carry',
        );
    }

    /**
     * The way out, and the reason it is opt-in. Asked for a verification pass, the ladder keeps
     * going past the coverage break, so `official_site` runs and its verified verdict lands on
     * the fields it re-confirms. Nothing widens a budget: the wall-clock ceiling still applies,
     * and a run that does not ask for this behaves exactly as before.
     */
    public function test_a_requested_verification_pass_lets_the_sources_confirm_what_is_stored(): void
    {
        $this->seedCompleteProfileWithNoVerification('code:1515');

        $this->companyRegistry->shouldReceive('supports')->andReturn(true);
        $this->companyRegistry->shouldReceive('collect')->andReturn(
            new CollectionResult('registry_db', 'ok', ['address' => 'Laisvės pr. 1, Vilnius'], [])
        );
        $this->official->shouldReceive('supports')->andReturn(true);
        $this->official->shouldReceive('collect')->once()->andReturn(
            new CollectionResult('official_site', 'ok', ['description' => $this->longText()], [
                'domain' => 'acmena.lt',
                'quality' => ['verdict' => 'pass', 'verified' => true, 'match_confidence' => 0.95, 'data_coverage' => 0.8, 'reasons' => []],
            ])
        );

        $out = $this->service()->collect([
            'code' => '1515', 'name' => 'Acmena', 'session' => 'audit-15', 'verify_fields' => true,
        ]);

        $this->assertContains('official_site', $out['sources_used']);
        $this->assertSame(CompanyProfile::VERIFICATION_VERIFIED, $out['field_verification']['description']['status']);
        $this->assertSame('official_site', $out['field_verification']['description']['source']);
    }

    /**
     * A verification pass run while the judge is unreachable establishes NOTHING: the
     * confirming source is itself unverified, and an unverified confirmer cannot confer
     * verification. The right behaviour, and a surprising one — the run spends a full ladder and
     * every field stays `unknown` — which is why it is now stated in the API document and in the
     * console's own helper text. An operator who is not told this concludes the checkbox is
     * broken.
     */
    public function test_a_verification_pass_during_a_judge_outage_establishes_nothing(): void
    {
        $this->seedCompleteProfileWithNoVerification('code:1717');

        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')->andThrow(new \RuntimeException('LLM down'));
        $judge = new CompanyMatchJudge($llm);

        $this->companyRegistry->shouldReceive('supports')->andReturn(false);
        $this->official->shouldReceive('supports')->andReturn(true);
        // The site says exactly what we already hold — the ordinary confirmation case — but the
        // judge that would vouch for the site is down, so `quality` is its fail-open.
        $this->official->shouldReceive('collect')->once()->andReturn(
            new CollectionResult('official_site', 'ok', ['description' => $this->longText()], [
                'domain' => 'acmena.lt',
                'quality' => $judge->judge(['name' => 'Acmena'], ['domain' => 'acmena.lt']),
            ])
        );

        $out = $this->service($judge)->collect([
            'code' => '1717', 'name' => 'Acmena', 'session' => 'audit-17', 'verify_fields' => true,
        ]);

        // The cost was real: the source ran and was accepted.
        $this->assertContains('official_site', $out['sources_used']);
        $this->assertFalse($out['match_verdicts']['official_site']['verified']);

        // And nothing was established.
        $this->assertSame(
            CompanyProfile::VERIFICATION_UNKNOWN,
            $out['field_verification']['description']['status'],
            'an unverified confirmer may not turn unknown into verified',
        );
    }

    /**
     * A profile whose declared fields are all filled and whose values carry no verification
     * record — the ordinary steady state of a firm enriched before the column existed.
     */
    private function seedCompleteProfileWithNoVerification(string $key): void
    {
        CompanyProfile::create([
            'company_key' => $key,
            'company_code' => explode(':', $key)[1],
            'name' => 'Acmena',
            'fields' => [
                'description' => $this->longText(),
                'activity' => 'Aliuminio-stiklo fasadai, langai, durys ir vitrinos',
                'emails' => ['info@acmena.lt'],
                'phones' => ['+37060000000'],
                'address' => 'Laisvės pr. 1, Vilnius',
                'social_links' => ['https://facebook.com/acmena'],
            ],
            'field_coverage' => array_fill_keys(['description', 'activity', 'emails', 'phones', 'address', 'social_links'], 1.0),
            'field_sources' => array_fill_keys(['description', 'activity', 'emails', 'phones', 'address', 'social_links'], 'official_site'),
            'overall_score' => 1.0,
        ]);
    }

    /**
     * The caller's own assertion is not a derivation and has no chain to break: values the
     * caller handed over as known_fields are the caller's to be right about, and the stored
     * verification says whose assertion it is.
     */
    public function test_caller_asserted_identity_stays_verified(): void
    {
        $out = $this->service()->collect([
            'code' => '1112', 'name' => 'Acmena', 'session' => 'audit-11b',
            'fields' => ['description', 'social_links'],
            'known_fields' => ['social_links' => ['https://linkedin.com/company/acmena']],
        ]);

        $this->assertSame(CompanyProfile::VERIFICATION_VERIFIED, $out['field_verification']['social_links']['status']);
        $this->assertSame('caller', $out['field_verification']['social_links']['source']);
    }

    /**
     * A rejection is a verdict too, and the row that says `rejected` must show what rejected
     * it — otherwise the only readable outcome is the one nobody disputes.
     */
    public function test_a_rejection_records_the_fail_it_rested_on(): void
    {
        $this->openWeb->shouldReceive('supports')->andReturn(true);
        $this->openWeb->shouldReceive('collect')->andReturn(
            new CollectionResult('open_web', 'ok', ['description' => $this->longText()], ['pages' => ['https://wrong.example']])
        );
        $this->judge->shouldReceive('judge')->andReturn([
            'verdict' => 'fail', 'verified' => true, 'match_confidence' => 0.05, 'data_coverage' => 0.5, 'reasons' => ['US firm, target is Lithuanian'],
        ]);

        $out = $this->service()->collect(['code' => '666', 'name' => 'Pergola', 'session' => 'audit-6']);

        $this->assertNotContains('open_web', $out['sources_used']);

        $action = CollectionAction::where('run_hash', $out['run_hash'])->where('source', 'open_web')->first();
        $this->assertSame('rejected', $action->status);

        $match = $this->storedMatch($out['run_hash'], 'open_web');
        $this->assertIsArray($match);
        $this->assertFalse($match['accepted']);
        $this->assertSame('fail', $match['verdict']);
        $this->assertSame(MatchAssessment::BASIS_JUDGE, $match['basis']);
    }

    /**
     * A source that yielded nothing was never accepted, so there is no verdict to record —
     * and inventing an `unverified` row for it would make the flag that matters unreadable,
     * since most runs have an empty source or two.
     */
    public function test_a_source_that_yielded_nothing_records_no_match_block(): void
    {
        $this->openWeb->shouldReceive('supports')->andReturn(true);
        $this->openWeb->shouldReceive('collect')->andReturn(
            new CollectionResult('open_web', 'empty', [], ['pages' => []])
        );

        $out = $this->service()->collect(['code' => '777', 'name' => 'Acmena', 'session' => 'audit-7']);

        $this->assertNull($this->storedMatch($out['run_hash'], 'open_web'));
        $this->assertSame([], $out['match_verdicts']);
    }

    /**
     * Scraped text decides whether the whole detail can be encoded at all: json_encode()
     * returns false on invalid UTF-8, and that used to leave `detail` null. The verdict must
     * outlive a broken byte in somebody's page content, or the audit gap reopens exactly
     * where the evidence is dirtiest.
     */
    public function test_the_verdict_survives_meta_that_cannot_be_encoded(): void
    {
        $this->contacts->shouldReceive('supports')->andReturn(true);
        $this->contacts->shouldReceive('collect')->andReturn(
            new CollectionResult('contacts', 'ok', ['emails' => ['info@acmena.lt']], ['content' => "Acmena \xB1\x1F broken bytes"])
        );
        $this->judge->shouldReceive('judge')->andReturn([
            'verdict' => 'pass', 'verified' => true, 'match_confidence' => 0.88, 'data_coverage' => 0.5, 'reasons' => [],
        ]);

        $out = $this->service()->collect(['code' => '888', 'name' => 'Acmena', 'session' => 'audit-8']);

        $this->assertContains('contacts', $out['sources_used']);

        $match = $this->storedMatch($out['run_hash'], 'contacts');
        $this->assertIsArray($match, 'the match verdict must survive un-encodable meta');
        $this->assertSame('pass', $match['verdict']);
        $this->assertTrue($match['verified']);
    }
}
