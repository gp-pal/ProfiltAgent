<?php

namespace Tests\Unit;

use App\Models\CollectionAction;
use App\Models\CompanyProfile;
use App\Services\MatchAssessment;
use App\Services\RunRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): RunRegistry
    {
        return new RunRegistry();
    }

    public function test_start_and_finish_run(): void
    {
        $reg = $this->registry();

        $run = $reg->startRun('hash-1', ['code' => '111', 'name' => 'Acmena', 'session' => 's1']);
        $this->assertSame('running', $run->status);
        $this->assertSame('code:111', $run->company_key);
        $this->assertNotNull($run->started_at);

        $reg->finishRun('hash-1', [
            'sources_used' => ['registry_db', 'official_site'],
            'overall_score' => 0.85,
            'verdict' => 'pass',
            'status' => 'done',
        ]);

        $run->refresh();
        $this->assertSame('done', $run->status);
        $this->assertSame(0.85, $run->overall_score);
        $this->assertSame(['registry_db', 'official_site'], $run->sources_used);
        $this->assertNotNull($run->finished_at);
    }

    /**
     * The write side of per-field verification: a field records what its contributor's identity
     * rested on, so a LATER run can read it instead of re-deriving it. Both directions in one
     * test, because the pair is the point — a verified contributor and an unverified one must
     * not leave the same trace.
     */
    public function test_a_merged_field_stores_what_its_source_rested_on(): void
    {
        $reg = $this->registry();
        $target = ['code' => '111', 'name' => 'Acmena'];

        $reg->mergeProfile(
            $target,
            ['social_links' => ['https://facebook.com/acmena']],
            ['social_links' => 1.0],
            ['social_links' => 'official_site'],
            assessment: new MatchAssessment(true, 'pass', MatchAssessment::BASIS_STORED_VERDICT, 0.96, 0.8),
        );
        $reg->mergeProfile(
            $target,
            ['description' => str_repeat('Aliuminio konstrukcijų gamyba. ', 8)],
            ['description' => 1.0],
            ['description' => 'open_web'],
            assessment: new MatchAssessment(true, MatchAssessment::VERDICT_UNVERIFIED, MatchAssessment::BASIS_JUDGE_UNAVAILABLE),
        );

        $profile = CompanyProfile::where('company_key', 'code:111')->firstOrFail();

        $this->assertSame(CompanyProfile::VERIFICATION_VERIFIED, $profile->verificationOf('social_links')['status']);
        $this->assertSame('official_site', $profile->verificationOf('social_links')['source']);
        $this->assertSame('pass', $profile->verificationOf('social_links')['verdict']);
        $this->assertSame(MatchAssessment::BASIS_STORED_VERDICT, $profile->verificationOf('social_links')['basis']);
        $this->assertNotNull($profile->verificationOf('social_links')['at']);

        $this->assertSame(CompanyProfile::VERIFICATION_UNVERIFIED, $profile->verificationOf('description')['status']);
    }

    /**
     * A field with no stored record reads `unknown` — never `verified`. Every profile row that
     * existed when the column was added is exactly this shape, and reading it as verified would
     * silently mark the whole history as checked: the worst available outcome for the one signal
     * this storage exists to carry.
     */
    public function test_a_row_with_no_verification_record_reads_unknown(): void
    {
        $profile = CompanyProfile::create([
            'company_key' => 'code:222',
            'company_code' => '222',
            'name' => 'Acmena',
            'fields' => ['social_links' => ['https://facebook.com/acmena']],
            'field_coverage' => ['social_links' => 1.0],
            'field_sources' => ['social_links' => 'official_site'],
            'overall_score' => 0.2,
        ]);

        $this->assertNull($profile->field_verification);
        $this->assertSame(CompanyProfile::VERIFICATION_UNKNOWN, $profile->verificationOf('social_links')['status']);
        $this->assertNull($profile->verificationOf('social_links')['source']);

        // Nor may a malformed or unrecognised status be read as verification.
        $profile->update(['field_verification' => ['social_links' => ['status' => 'probably', 'source' => 'official_site']]]);

        $this->assertSame(CompanyProfile::VERIFICATION_UNKNOWN, $profile->fresh()->verificationOf('social_links')['status']);
    }

    /**
     * A merge that replaces a value while saying nothing about verification must CLEAR the old
     * record, not inherit it: the record described the value that is now gone, so keeping it
     * would report `verified` for something no longer stored.
     *
     * Latent today — both callers pass an assessment — and fixed anyway, because the parameter
     * is optional and the docblock promises this: the next caller (a repair command, a backfill
     * script, a new merge path) would otherwise mint a verified record for an unverified value,
     * which is the whole defect class this storage exists to remove.
     */
    public function test_replacing_a_value_without_a_verdict_clears_the_old_verification(): void
    {
        $reg = $this->registry();
        $target = ['code' => '555', 'name' => 'Acmena'];

        $reg->mergeProfile($target, ['description' => 'Trumpas'], ['description' => 0.4], ['description' => 'official_site'],
            assessment: new MatchAssessment(true, 'pass', MatchAssessment::BASIS_STORED_VERDICT, 0.95, 0.8));
        $this->assertSame(
            CompanyProfile::VERIFICATION_VERIFIED,
            CompanyProfile::where('company_key', 'code:555')->firstOrFail()->verificationOf('description')['status'],
        );

        // A different, better-covered value from a caller that states no verdict.
        $reg->mergeProfile($target, ['description' => str_repeat('Visai kitas aprašymas. ', 8)], ['description' => 1.0], ['description' => 'open_web']);

        $profile = CompanyProfile::where('company_key', 'code:555')->firstOrFail();
        $this->assertSame('open_web', $profile->field_sources['description'], 'precondition: the value really was replaced');
        $this->assertSame(
            CompanyProfile::VERIFICATION_UNKNOWN,
            $profile->verificationOf('description')['status'],
            'the old record described a value that is gone',
        );
    }

    /**
     * The other half of the clearing rule, and the half the docblock now scopes explicitly: a
     * field this merge did NOT store keeps its record, because that record still describes the
     * value that is still there. Pinned so the clearing fix cannot later be widened into
     * "a null assessment wipes everything it touched", which would throw away verification
     * that is still accurate.
     */
    public function test_a_losing_value_without_a_verdict_leaves_the_stored_verification_alone(): void
    {
        $reg = $this->registry();
        $target = ['code' => '666', 'name' => 'Acmena'];
        $stored = str_repeat('Ilgas patikrintas aprašymas. ', 8);

        $reg->mergeProfile($target, ['description' => $stored], ['description' => 1.0], ['description' => 'official_site'],
            assessment: new MatchAssessment(true, 'pass', MatchAssessment::BASIS_STORED_VERDICT, 0.95, 0.8));

        // Shorter, so it loses the coverage gate; and it says nothing about verification.
        $reg->mergeProfile($target, ['description' => 'Trumpas'], ['description' => 0.2], ['description' => 'open_web']);

        $profile = CompanyProfile::where('company_key', 'code:666')->firstOrFail();
        $this->assertSame($stored, $profile->fields['description'], 'precondition: the value did not change');
        $this->assertSame(
            CompanyProfile::VERIFICATION_VERIFIED,
            $profile->verificationOf('description')['status'],
            'the record still describes the value that is still stored',
        );
    }

    /**
     * A tie keeps the earlier source's VALUE and label — but the value's verification can still
     * be established by a verified source contributing the identical value. Without this, a
     * field could never gain verification after its first writer: `social_links` scores 1.0/0.0
     * and the merge relabels only on a strict increase, so the first contributor owns it
     * forever, and its verification would be frozen with it.
     */
    public function test_a_tied_identical_value_gains_verification_without_changing_owner(): void
    {
        $reg = $this->registry();
        $target = ['code' => '333', 'name' => 'Acmena'];
        $links = ['https://facebook.com/acmena', 'https://instagram.com/acmena'];

        // First writer: a row shaped like one from before verification was stored.
        $reg->mergeProfile($target, ['social_links' => $links], ['social_links' => 1.0], ['social_links' => 'open_web']);
        // Same links, different order — order is how a page was parsed, not a different finding.
        $reg->mergeProfile($target, ['social_links' => array_reverse($links)], ['social_links' => 1.0], ['social_links' => 'official_site'],
            assessment: new MatchAssessment(true, 'pass', MatchAssessment::BASIS_STORED_VERDICT, 0.97, 0.8));

        $profile = CompanyProfile::where('company_key', 'code:333')->firstOrFail();

        $this->assertSame('open_web', $profile->field_sources['social_links'], 'the tie must not move the owner');
        $this->assertSame($links, $profile->fields['social_links'], 'nor the stored value');
        $this->assertSame(CompanyProfile::VERIFICATION_VERIFIED, $profile->verificationOf('social_links')['status']);
        $this->assertSame('official_site', $profile->verificationOf('social_links')['source'], 'who verified it, not who owns it');
    }

    /**
     * Corroboration is about the VALUE, not the field: a verified source that contributed
     * something else verified its own value. Marking the stored value verified because a
     * different, losing value was verified would be the same false claim one step removed.
     */
    public function test_a_tied_different_value_confers_no_verification(): void
    {
        $reg = $this->registry();
        $target = ['code' => '444', 'name' => 'Acmena'];

        $reg->mergeProfile($target, ['social_links' => ['https://facebook.com/acmena']], ['social_links' => 1.0], ['social_links' => 'open_web']);
        $reg->mergeProfile($target, ['social_links' => ['https://facebook.com/somebody-else']], ['social_links' => 1.0], ['social_links' => 'official_site'],
            assessment: new MatchAssessment(true, 'pass', MatchAssessment::BASIS_STORED_VERDICT, 0.97, 0.8));

        $profile = CompanyProfile::where('company_key', 'code:444')->firstOrFail();

        $this->assertSame(['https://facebook.com/acmena'], $profile->fields['social_links']);
        $this->assertSame(
            CompanyProfile::VERIFICATION_UNKNOWN,
            $profile->verificationOf('social_links')['status'],
            'a verified source verified ITS value, not the one we kept',
        );
    }

    public function test_record_action_persists_yield_and_scores(): void
    {
        $reg = $this->registry();
        $reg->startRun('hash-1', ['code' => '111', 'name' => 'Acmena']);

        $action = $reg->recordAction('hash-1', 'registry_db', 'fetch', 'ok', ['address' => 'Vilnius'], ['address' => 0.9], 'from registry');

        $this->assertSame('registry_db', $action->source);
        $this->assertSame(['address' => 'Vilnius'], $action->yielded);
        $this->assertSame(['address' => 0.9], $action->field_scores);
        $this->assertSame(1, CollectionAction::where('run_hash', 'hash-1')->count());
    }

    public function test_merge_profile_accumulates_keeping_higher_coverage(): void
    {
        $reg = $this->registry();
        $target = ['code' => '111', 'name' => 'Acmena'];

        // Run 1 (registry): strong address, no description yet.
        $reg->mergeProfile($target, ['address' => 'Laisvės pr. 1, Vilnius'], ['address' => 0.9], ['address' => 'registry_db']);

        // Run 2 (site): adds a description, but a weaker address that must NOT win.
        $reg->mergeProfile($target, ['description' => 'Statybos įmonė', 'address' => 'stale'], ['description' => 0.8, 'address' => 0.2], ['description' => 'official_site', 'address' => 'official_site']);

        $p = $reg->profileFor('code:111');
        $this->assertNotNull($p);

        // Higher-coverage address is preserved; description added from the site.
        $this->assertSame('Laisvės pr. 1, Vilnius', $p->fields['address']);
        $this->assertSame('Statybos įmonė', $p->fields['description']);
        $this->assertSame(0.9, $p->field_coverage['address']);
        $this->assertSame('registry_db', $p->field_sources['address']);
        $this->assertSame('official_site', $p->field_sources['description']);

        // overall = mean(0.9, 0.8)
        $this->assertSame(0.85, $p->overall_score);
    }

    public function test_merge_keeps_earlier_source_on_a_coverage_tie(): void
    {
        $reg = $this->registry();
        $target = ['code' => '111', 'name' => 'Acmena'];

        // Same field, same coverage from two sources — the earlier (more
        // authoritative, legal-first) one must win the tie.
        $reg->mergeProfile($target, ['description' => 'from the official site'], ['description' => 1.0], ['description' => 'official_site']);
        $reg->mergeProfile($target, ['description' => 'from open web'], ['description' => 1.0], ['description' => 'open_web']);

        $p = $reg->profileFor('code:111');
        $this->assertSame('from the official site', $p->fields['description']);
        $this->assertSame('official_site', $p->field_sources['description']);
    }

    public function test_runs_for_lists_company_runs(): void
    {
        $reg = $this->registry();
        $reg->startRun('h1', ['code' => '111', 'name' => 'Acmena']);
        $reg->startRun('h2', ['code' => '111', 'name' => 'Acmena']);
        $reg->startRun('h3', ['code' => '222', 'name' => 'Beta']);

        $this->assertCount(2, $reg->runsFor('code:111'));
        $this->assertCount(1, $reg->runsFor('code:222'));
    }

    public function test_key_for_falls_back_to_name_slug_without_code(): void
    {
        $reg = $this->registry();
        $this->assertSame('code:111', $reg->keyFor(['code' => '111', 'name' => 'Acmena']));
        $this->assertSame('name:acmena-uab', $reg->keyFor(['name' => 'Acmena UAB']));
    }
}
