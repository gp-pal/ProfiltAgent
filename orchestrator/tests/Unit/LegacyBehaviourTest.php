<?php

namespace Tests\Unit;

use App\Services\FieldRequirements;
use App\Services\ProfileCoverageScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A caller that sends no `fields` and an admin who never opens the catalogue must
 * get exactly today's behaviour: the API consumer is live and reads those
 * six keys.
 *
 * This file does NOT carry that guarantee on its own, and must not be read as if it
 * did. It covers two narrower things: that the legacy six survive whatever the
 * catalogue adds, and that `ProfileCoverageScorer::FIELDS` has not drifted from
 * `FieldRequirements::LEGACY` (a real risk for direct `score()` callers such as the
 * admin infolist, though production always passes an explicit declared list).
 *
 * The end-to-end proof lives elsewhere and is where to look first if this guarantee
 * is ever in doubt:
 *   - `CollectorServiceTest::test_no_fields_key_declares_the_legacy_six_by_default`
 *     drives the real `/api/run` default path.
 *   - `CompanyProfileServiceTest::test_without_a_declared_list_the_legacy_schema_is_used`
 *     covers the legacy `collect:false` path's LLM schema.
 */
class LegacyBehaviourTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_without_fields_is_scored_on_the_legacy_six(): void
    {
        $declared = (new FieldRequirements())->resolve([]);
        $legacyFilled = [
            'description' => str_repeat('a', 200), 'activity' => str_repeat('b', 60),
            'emails' => ['a@b.lt'], 'phones' => ['+37060000000'],
            'address' => 'Vilnius', 'social_links' => ['https://x.lt'],
        ];

        $out = (new ProfileCoverageScorer())->score($legacyFilled, $declared);

        // The six legacy keys must all be present and each fully scored, whatever
        // else the catalogue adds.
        foreach (FieldRequirements::LEGACY as $key) {
            $this->assertArrayHasKey($key, $out['fields']);
            $this->assertSame(1.0, $out['fields'][$key], "{$key} must still score 1.0 when filled as before");
        }
    }

    public function test_scoring_with_no_declared_list_is_unchanged(): void
    {
        $out = (new ProfileCoverageScorer())->score(['address' => 'Vilnius']);

        // Compared against FieldRequirements::LEGACY (not ProfileCoverageScorer::FIELDS
        // itself) so this actually catches the two constants drifting apart, rather
        // than a tautology that always holds no matter what FIELDS is.
        $this->assertSame(FieldRequirements::LEGACY, array_keys($out['fields']));
    }
}
