<?php

namespace Tests\Unit;

use App\Services\OfficialSiteSource;
use App\Services\ReActLoopService;
use Mockery;
use Tests\TestCase;

class OfficialSiteSourceTest extends TestCase
{
    public function test_maps_built_profile_to_fields_and_meta(): void
    {
        $react = Mockery::mock(ReActLoopService::class);
        $react->shouldReceive('runStep1Bootstrap')
            ->once()
            ->with('Acmena', 'acmena.lt', Mockery::type('string'), Mockery::on(
                static fn (array $opts): bool => ($opts['targetFacts']['name'] ?? null) === 'Acmena'
            ))
            ->andReturn([
                'domain' => 'acmena.lt',
                'resolved_via' => null,
                'error' => null,
                'profile' => [
                    'description' => 'Statybos įmonė',
                    'activity' => 'Statyba',
                    'emails' => ['info@acmena.lt'],
                    'phones' => [],          // empty -> excluded
                    'address' => '',         // empty -> excluded
                    'social_links' => ['https://linkedin.com/company/acmena'],
                    'quality' => ['verdict' => 'pass', 'match_confidence' => 0.9],
                ],
            ]);

        $r = (new OfficialSiteSource($react))->collect(['name' => 'Acmena', 'domain' => 'acmena.lt', 'session' => 's1']);

        $this->assertSame('ok', $r->status);
        $this->assertSame('Statybos įmonė', $r->fields['description']);
        $this->assertSame(['https://linkedin.com/company/acmena'], $r->fields['social_links']);
        $this->assertArrayNotHasKey('phones', $r->fields);
        $this->assertArrayNotHasKey('address', $r->fields);
        $this->assertSame('acmena.lt', $r->meta['domain']);
        $this->assertSame('pass', $r->meta['quality']['verdict']);
    }

    public function test_no_site_found_is_empty_not_error(): void
    {
        // No site could be resolved -> the firm simply has no official website.
        // That is a clean absence (nothing to collect), NOT a recoverable failure,
        // so the collector's saturation check must not treat it as a retryable error.
        $react = Mockery::mock(ReActLoopService::class);
        $react->shouldReceive('runStep1Bootstrap')->once()->andReturn([
            'domain' => null,
            'error' => 'no_site_found',
            'profile' => null,
        ]);

        $r = (new OfficialSiteSource($react))->collect(['name' => 'Obscure', 'session' => 's2']);

        $this->assertSame('empty', $r->status);
        $this->assertSame([], $r->fields);
        $this->assertSame('no_site_found', $r->meta['error']);
    }

    public function test_resolved_site_that_fails_to_capture_is_a_recoverable_error(): void
    {
        // A site WAS resolved but the capture/pipeline failed -> retrying could
        // succeed, so this stays 'error' (a recoverable gap, not clean exhaustion).
        $react = Mockery::mock(ReActLoopService::class);
        $react->shouldReceive('runStep1Bootstrap')->once()->andReturn([
            'domain' => 'acmena.lt',
            'error' => 'capture_failed',
            'profile' => null,
        ]);

        $r = (new OfficialSiteSource($react))->collect(['name' => 'Acmena', 'domain' => 'acmena.lt', 'session' => 's3']);

        $this->assertSame('error', $r->status);
        $this->assertSame('acmena.lt', $r->meta['domain']);
    }

    public function test_an_empty_declared_list_keeps_the_legacy_six_rather_than_discarding_everything(): void
    {
        // `?? ` does not fire on an empty array, so an empty declared list used to
        // make the harvest loop iterate nothing and report `empty` — throwing away a
        // fully built profile. Every other consumer of the declared list reads []
        // as "no list given" and uses the legacy six; this one must agree.
        $react = Mockery::mock(ReActLoopService::class);
        $react->shouldReceive('runStep1Bootstrap')->once()->andReturn([
            'domain' => 'acmena.lt',
            'resolved_via' => null,
            'error' => null,
            'profile' => [
                'description' => 'Statybos įmonė',
                'emails' => ['info@acmena.lt'],
                'quality' => ['verdict' => 'pass', 'match_confidence' => 0.9],
            ],
        ]);

        $r = (new OfficialSiteSource($react))->collect([
            'name' => 'Acmena',
            'domain' => 'acmena.lt',
            'session' => 's5',
            'declared_fields' => [],
        ]);

        $this->assertSame('ok', $r->status);
        $this->assertSame('Statybos įmonė', $r->fields['description']);
        $this->assertSame(['info@acmena.lt'], $r->fields['emails']);
    }

    public function test_declared_catalogue_fields_beyond_the_legacy_six_survive_the_harvest_loop(): void
    {
        // The harvest loop was widened to iterate the target's declared_fields
        // instead of the hardcoded legacy six; if that ever regresses back to
        // ProfileCoverageScorer::FIELDS, a new catalogue field the LLM actually
        // answered (e.g. `brands`) would be silently dropped here, one file
        // downstream of where CompanyProfileService puts it in the profile.
        $react = Mockery::mock(ReActLoopService::class);
        $react->shouldReceive('runStep1Bootstrap')->once()->andReturn([
            'domain' => 'acmena.lt',
            'resolved_via' => null,
            'error' => null,
            'profile' => [
                'description' => 'Statybos įmonė',
                'activity' => 'Statyba', // present on the profile but NOT declared below
                'brands' => ['Acmena Pro'],
                'quality' => ['verdict' => 'pass', 'match_confidence' => 0.9],
            ],
        ]);

        $r = (new OfficialSiteSource($react))->collect([
            'name' => 'Acmena',
            'domain' => 'acmena.lt',
            'session' => 's4',
            'declared_fields' => ['description', 'brands'],
        ]);

        $this->assertSame('ok', $r->status);
        $this->assertSame(['Acmena Pro'], $r->fields['brands']);
        // 'activity' is in the legacy six but NOT in this run's declared_fields;
        // it must not be pulled in just because it happens to be present on the
        // profile and in the ProfileCoverageScorer::FIELDS fallback.
        $this->assertArrayNotHasKey('activity', $r->fields);
    }
}
