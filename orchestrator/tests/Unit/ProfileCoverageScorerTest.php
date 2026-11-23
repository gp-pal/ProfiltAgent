<?php

namespace Tests\Unit;

use App\Services\ProfileCoverageScorer;
use Tests\TestCase;

class ProfileCoverageScorerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('react.coverage.gap_threshold', 0.5);
    }

    public function test_empty_profile_is_all_gaps(): void
    {
        $r = (new ProfileCoverageScorer())->score([]);

        $this->assertSame(0.0, $r['overall']);
        $this->assertCount(6, $r['missing']);
    }

    public function test_full_profile_has_no_gaps(): void
    {
        $r = (new ProfileCoverageScorer())->score([
            'description' => str_repeat('Įmonė gamina ir montuoja aliuminio konstrukcijas. ', 4),
            'activity' => 'Aliuminio-stiklo fasadai, langai, durys',
            'emails' => ['info@x.lt'],
            'phones' => ['+37060000000'],
            'address' => 'Drobės g. 62, Kaunas',
            'social_links' => ['https://linkedin.com/company/x'],
        ]);

        $this->assertSame([], $r['missing']);
        $this->assertGreaterThanOrEqual(0.99, $r['overall']);
    }

    public function test_partial_profile_reports_specific_gaps(): void
    {
        $r = (new ProfileCoverageScorer())->score([
            'description' => str_repeat('Detali veiklos aprašymo pastraipa. ', 5),
            'emails' => ['info@x.lt'],
        ]);

        $this->assertNotContains('description', $r['missing']);
        $this->assertNotContains('emails', $r['missing']);
        $this->assertContains('activity', $r['missing']);
        $this->assertContains('phones', $r['missing']);
        $this->assertContains('address', $r['missing']);
        $this->assertContains('social_links', $r['missing']);
    }

    public function test_short_prose_scores_partial_and_counts_as_gap(): void
    {
        $r = (new ProfileCoverageScorer())->score(['description' => 'Trumpa.']);

        $this->assertLessThan(1.0, $r['fields']['description']);
        $this->assertContains('description', $r['missing']);
    }

    public function test_scores_the_declared_fields_instead_of_the_constant(): void
    {
        $scorer = new ProfileCoverageScorer();

        $out = $scorer->score(['brands' => ['Acmena'], 'locations' => []], ['brands', 'locations']);

        $this->assertSame(['brands', 'locations'], array_keys($out['fields']));
        $this->assertEqualsWithDelta(0.5, $out['overall'], 0.001);
        $this->assertSame(['locations'], $out['missing']);
    }

    public function test_a_declared_field_absent_from_the_profile_scores_zero(): void
    {
        $out = (new ProfileCoverageScorer())->score([], ['positioning']);

        $this->assertSame(0.0, $out['fields']['positioning']);
    }

    public function test_without_a_declared_list_the_legacy_six_are_scored(): void
    {
        // Backward compatibility: existing callers pass no list and must be unaffected.
        $out = (new ProfileCoverageScorer())->score(['address' => 'Vilnius']);

        $this->assertSame(ProfileCoverageScorer::FIELDS, array_keys($out['fields']));
        $this->assertEqualsWithDelta(0.167, $out['overall'], 0.001);
    }

    public function test_a_non_legacy_text_field_is_scored_present_or_absent(): void
    {
        // `positioning` has no legacy prose ramp — it is either stated or it is not.
        $scorer = new ProfileCoverageScorer();

        $this->assertSame(1.0, $scorer->score(['positioning' => 'Unikalūs sprendimai'], ['positioning'])['fields']['positioning']);
        $this->assertSame(0.0, $scorer->score(['positioning' => '   '], ['positioning'])['fields']['positioning']);
    }
}
