<?php

namespace Tests\Feature;

use App\Filament\Pages\RequestConsole;
use App\Models\CollectionAction;
use App\Models\CompanyProfile;
use App\Models\User;
use App\Services\ArticleExcerpt;
use App\Services\CollectionResult;
use App\Services\CollectorService;
use App\Services\FieldRequirements;
use App\Services\NewsSource;
use App\Tools\CompanyRegistryRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class RequestConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'console@profiltagent.example',
            'password' => 'password123',
        ]);
    }

    /** @param array<int, array<string, mixed>> $firms */
    private function bindRepo(array $firms): void
    {
        $mock = Mockery::mock(CompanyRegistryRepository::class);
        $mock->shouldReceive('searchGapFirms')->andReturn($firms);
        $this->app->instance(CompanyRegistryRepository::class, $mock);
    }

    public function test_the_console_requires_authentication(): void
    {
        $this->get('/admin/request-console')->assertRedirect('/admin/login');
    }

    public function test_it_defaults_the_declared_fields_to_the_catalogue_required_set(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->assertOk()
            ->assertSet('data.fields', ['description', 'activity', 'emails', 'phones', 'address', 'social_links']);
    }

    public function test_it_forwards_known_fields_and_people_to_the_collector(): void
    {
        // The whole point of the console: prove the two caller inputs actually
        // reach the collector, so a test here is a test of the real contract.
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(static function (array $t): bool {
                return ($t['known_fields'] ?? []) === ['description' => 'Jau turim']
                    && ($t['people'] ?? []) === [['name' => 'Jolanta Girdenytė', 'role' => 'Vadovė']]
                    && ($t['name'] ?? null) === 'Didmena LT, UAB';
            }))
            ->andReturn([
                'run_hash' => 'rh', 'profile' => [], 'field_sources' => [],
                'coverage' => ['overall' => 0.5, 'fields' => [], 'missing' => []],
                'sources_used' => [], 'verdict' => 'partial', 'field_status' => [],
            ]);
        $this->app->instance(CollectorService::class, $collector);

        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('data.company', 'Didmena LT, UAB')
            ->set('data.known_fields', [
                'row-a' => ['key' => 'description', 'value' => 'Jau turim'],
                'row-b' => ['key' => 'address', 'value' => '   '],
                'row-c' => ['key' => '', 'value' => 'be lauko'],
            ])
            ->set('data.people', [
                ['name' => 'Jolanta Girdenytė', 'role' => 'Vadovė'],
                ['name' => '  ', 'role' => 'Niekas'],
            ])
            ->call('runRequest')
            ->assertSet('error', null)
            ->assertSet('result.verdict', 'partial');
    }

    /**
     * The operator's own screen has to distinguish data whose source was verified from data
     * whose source was accepted with the judge unreachable. It looks identical otherwise — same
     * status, same provenance label — so without the marker the page states a match check that
     * never happened. Everywhere the source appears: the run badges, the field table, and the
     * people list, which are all gated on the same verdict.
     */
    public function test_a_source_accepted_without_verification_is_marked_everywhere_it_appears(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')->once()->andReturn([
            'run_hash' => 'rh-unverified',
            'profile' => [
                'description' => 'Ilgas aprašymas apie įmonę',
                'address' => 'Laisvės pr. 1, Vilnius',
                'people' => [
                    ['name' => 'Jonas Jonaitis', 'role' => 'Vadovas', 'source' => 'open_web'],
                    ['name' => 'Petras Petraitis', 'role' => null, 'source' => 'registry_db'],
                ],
            ],
            'field_sources' => ['description' => 'open_web', 'address' => 'registry_db'],
            'coverage' => ['overall' => 0.4, 'fields' => ['description' => 0.8, 'address' => 1.0], 'missing' => []],
            'sources_used' => ['registry_db', 'open_web'],
            'verdict' => 'partial',
            'field_status' => ['description' => 'found', 'address' => 'found'],
            'field_verification' => [
                'description' => ['status' => 'unverified', 'source' => 'open_web', 'verdict' => 'unverified', 'basis' => 'judge_unavailable', 'at' => '2026-08-17T10:00:00+00:00'],
                'address' => ['status' => 'verified', 'source' => 'registry_db', 'verdict' => 'pass', 'basis' => 'registry_code', 'at' => '2026-08-17T10:00:00+00:00'],
            ],
            'match_verdicts' => [
                'registry_db' => ['accepted' => true, 'verdict' => 'pass', 'basis' => 'registry_code', 'verified' => true, 'match_confidence' => null, 'data_coverage' => null, 'reasons' => []],
                'open_web' => ['accepted' => true, 'verdict' => 'unverified', 'basis' => 'judge_unavailable', 'verified' => false, 'match_confidence' => null, 'data_coverage' => null, 'reasons' => ['judge error: LLM down']],
            ],
        ]);
        $this->app->instance(CollectorService::class, $collector);

        $this->actingAs($this->admin());

        $html = Livewire::test(RequestConsole::class)
            ->set('data.company', 'Acmena')
            ->call('runRequest')
            ->assertSee('match not verified')
            ->html();

        $this->assertStringContainsString('Source accepted without a match check: judge_unavailable', $html);

        // Three times, and only three: the open_web source badge, the one field it filled, and
        // the one person it named. A fourth would mean something from registry_db — verified
        // by company code — got marked too; two would mean an operator surface was left
        // half-marked, where a missing badge reads as verified.
        $this->assertSame(
            3,
            substr_count($html, 'match not verified'),
            'the marker belongs to every rendering of an unverified source, and to nothing else',
        );
    }

    /**
     * The field table reads each value's STORED verification, not this run's source list — which
     * is what makes a carried-over value markable at all. Here nothing in `match_verdicts`
     * mentions `official_site` (this run never ran it), so a source-keyed marker would leave the
     * field bare and the operator would read it as verified. A value we hold no record for says
     * that too, in its own words, because "nobody checked" and "we have no record" are different
     * facts.
     */
    public function test_the_field_table_marks_a_carried_over_value_this_run_never_touched(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')->once()->andReturn([
            'run_hash' => 'rh-carried',
            'profile' => ['description' => 'Iš ankstesnio paleidimo', 'address' => 'Laisvės pr. 1, Vilnius'],
            'field_sources' => ['description' => 'official_site', 'address' => 'official_site'],
            'coverage' => ['overall' => 0.5, 'fields' => ['description' => 0.9, 'address' => 1.0], 'missing' => []],
            'sources_used' => ['registry_db'],
            'verdict' => 'partial',
            'field_status' => ['description' => 'found', 'address' => 'found'],
            'field_verification' => [
                'description' => ['status' => 'unverified', 'source' => 'official_site', 'verdict' => 'unverified', 'basis' => 'judge_unavailable', 'at' => '2026-08-16T09:00:00+00:00'],
                'address' => ['status' => 'unknown', 'source' => null, 'verdict' => null, 'basis' => null, 'at' => null],
            ],
            'match_verdicts' => [
                'registry_db' => ['accepted' => true, 'verdict' => 'pass', 'basis' => 'registry_code', 'verified' => true, 'derived_from' => null, 'match_confidence' => null, 'data_coverage' => null, 'reasons' => []],
            ],
        ]);
        $this->app->instance(CollectorService::class, $collector);

        $this->actingAs($this->admin());

        $html = Livewire::test(RequestConsole::class)
            ->set('data.company', 'Acmena')
            ->call('runRequest')
            ->assertSee('match not verified')   // the unverified carried-over value
            ->assertSee('no verification record')      // and the one we hold no record for
            ->html();

        // Once each: the two field rows. No source badge can carry the marker here — the only
        // source this run used was the registry, and it is verified.
        $this->assertSame(1, substr_count($html, 'match not verified'));
        $this->assertSame(1, substr_count($html, 'no verification record'));
    }

    /**
     * The operator's way out of a permanent "no verification record" — the console is where someone
     * looking at one firm can ask for its stored values to be re-confirmed. Off unless ticked,
     * because it spends a full ladder on data we already hold.
     */
    public function test_the_verification_pass_checkbox_reaches_the_collector(): void
    {
        $seen = [];
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')
            ->twice()
            ->with(Mockery::on(static function (array $t) use (&$seen): bool {
                $seen[] = $t['verify_fields'] ?? null;

                return true;
            }))
            ->andReturn([
                'run_hash' => 'rh-verify', 'profile' => [], 'field_sources' => [],
                'coverage' => ['overall' => 0.5, 'fields' => [], 'missing' => []],
                'sources_used' => [], 'verdict' => 'partial', 'field_status' => [],
                'field_verification' => [], 'match_verdicts' => [],
            ]);
        $this->app->instance(CollectorService::class, $collector);

        $this->actingAs($this->admin());

        $page = Livewire::test(RequestConsole::class)->set('data.company', 'Acmena');
        $page->set('data.verify_fields', true)->call('runRequest');
        $page->set('data.verify_fields', false)->call('runRequest');

        $this->assertSame([true, false], $seen);
    }

    public function test_a_failing_collector_is_shown_rather_than_swallowed(): void
    {
        // A broken run is exactly what this page exists to look at, so the error has
        // to reach the screen instead of leaving a blank result.
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')->once()->andThrow(new \RuntimeException('registras nepasiekiamas'));
        $this->app->instance(CollectorService::class, $collector);

        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('data.company', 'Acmena')
            ->call('runRequest')
            ->assertSet('error', 'registras nepasiekiamas')
            ->assertSet('result', null);
    }

    public function test_it_refuses_to_run_without_a_company_name(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldNotReceive('collect');
        $this->app->instance(CollectorService::class, $collector);

        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('data.company', '   ')
            ->call('runRequest')
            ->assertSet('result', null);
    }

    public function test_filter_dropdowns_render_non_native(): void
    {
        // The filter Selects use ->native(false): Chrome mis-positioned the native
        // <select> option popup ("jumped to the side"). Non-native renders
        // Filament's own anchored, themed dropdown, so no native <select> element
        // (fi-fo-select-native) is emitted for the filter fields.
        $this->bindRepo([
            ['code' => '111', 'name' => 'Alpha UAB', 'site' => 'alpha.lt', 'address' => 'Vilnius', 'employees' => 5],
        ]);
        $this->actingAs($this->admin());

        $html = Livewire::test(RequestConsole::class)->assertOk()->html();

        $this->assertStringNotContainsString('fi-fo-select-native', $html);
    }

    public function test_page_lists_firms_with_enrichment_status(): void
    {
        $this->bindRepo([
            ['code' => '111', 'name' => 'Alpha UAB', 'site' => 'alpha.lt', 'address' => 'Vilnius', 'employees' => 5],
            ['code' => '222', 'name' => 'Beta UAB', 'site' => null, 'address' => 'Kaunas', 'employees' => 50],
        ]);
        CompanyProfile::create([
            'company_key' => 'code:111', 'company_code' => '111', 'name' => 'Alpha UAB',
            'domain' => 'alpha.lt', 'fields' => [], 'field_coverage' => [], 'field_sources' => [],
            'overall_score' => 0.85,
        ]);

        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->assertOk()
            ->assertSee('Alpha UAB')
            ->assertSee('Beta UAB')
            ->assertSee('0.85')
            ->assertSee('not enriched');
    }

    public function test_page_is_fail_soft_when_registry_unreachable(): void
    {
        $mock = Mockery::mock(CompanyRegistryRepository::class);
        $mock->shouldReceive('searchGapFirms')->andThrow(new \RuntimeException('connection refused'));
        $this->app->instance(CompanyRegistryRepository::class, $mock);

        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->assertOk() // no 500
            ->assertSee('registry database is unreachable');
    }

    public function test_status_filter_not_enriched_excludes_enriched(): void
    {
        $this->bindRepo([
            ['code' => '111', 'name' => 'Alpha UAB', 'site' => 'alpha.lt', 'address' => 'Vilnius', 'employees' => 5],
            ['code' => '222', 'name' => 'Beta UAB', 'site' => null, 'address' => 'Kaunas', 'employees' => 50],
        ]);
        CompanyProfile::create([
            'company_key' => 'code:111', 'company_code' => '111', 'name' => 'Alpha UAB',
            'domain' => 'alpha.lt', 'fields' => [], 'field_coverage' => [], 'field_sources' => [],
            'overall_score' => 0.85,
        ]);

        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('filters.status', 'not_enriched')
            ->call('search')
            ->assertOk()
            ->assertDontSee('Alpha UAB')
            ->assertSee('Beta UAB');
    }

    public function test_status_filter_poor_shows_only_low_scoring_enriched(): void
    {
        $this->bindRepo([
            ['code' => '111', 'name' => 'Alpha UAB', 'site' => 'alpha.lt', 'address' => 'Vilnius', 'employees' => 5],
            ['code' => '222', 'name' => 'Beta UAB', 'site' => null, 'address' => 'Kaunas', 'employees' => 50],
            ['code' => '333', 'name' => 'Gamma UAB', 'site' => null, 'address' => 'Klaipeda', 'employees' => 12],
        ]);
        CompanyProfile::create([
            'company_key' => 'code:111', 'company_code' => '111', 'name' => 'Alpha UAB',
            'domain' => 'alpha.lt', 'fields' => [], 'field_coverage' => [], 'field_sources' => [],
            'overall_score' => 0.4,
        ]);
        CompanyProfile::create([
            'company_key' => 'code:222', 'company_code' => '222', 'name' => 'Beta UAB',
            'domain' => null, 'fields' => [], 'field_coverage' => [], 'field_sources' => [],
            'overall_score' => 0.9,
        ]);

        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('filters.status', 'poor')
            ->call('search')
            ->assertOk()
            ->assertSee('Alpha UAB')
            ->assertDontSee('Beta UAB')
            ->assertDontSee('Gamma UAB');
    }

    public function test_the_search_button_is_rendered(): void
    {
        // The filter fields don't submit themselves; without this button an admin has
        // no way to apply a filter change from the browser (every other test here
        // triggers search() directly through the Livewire test API, which would still
        // pass even if the button were missing from the page).
        $this->bindRepo([
            ['code' => '111', 'name' => 'Alpha UAB', 'site' => 'alpha.lt', 'address' => 'Vilnius', 'employees' => 5],
        ]);
        $this->actingAs($this->admin());

        $html = Livewire::test(RequestConsole::class)->assertOk()->html();

        $this->assertStringContainsString('wire:click="search"', $html);
    }

    public function test_table_paginates_results(): void
    {
        $firms = [];
        for ($i = 1; $i <= 30; $i++) {
            $code = (string) (100 + $i);
            $firms[] = ['code' => $code, 'name' => "Firm {$i}", 'site' => null, 'address' => 'Vilnius', 'employees' => 1];
        }
        $this->bindRepo($firms);
        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->assertSee('Firm 1')
            ->assertSee('Firm 25')
            ->assertDontSee('Firm 26')
            ->call('gotoPage', 2)
            ->assertSee('Firm 26')
            ->assertSee('Firm 30')
            ->assertDontSee('Firm 1');
    }

    public function test_the_gap_firms_page_is_gone(): void
    {
        // One page replaces both; a lingering second entry point would drift.
        $this->actingAs($this->admin());

        $this->get('/admin/gap-firms')->assertNotFound();
    }

    public function test_prefill_fills_identity_people_and_unchecked_suggestions(): void
    {
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldReceive('factsForCode')->once()->with('111')->andReturn([
            'code' => '111',
            'name' => 'Acmena, UAB',
            'site' => 'acmena.lt',
            'address' => 'Vilnius',
            'email' => 'info@acmena.lt',
            'employees' => 31,
        ]);
        $repo->shouldReceive('peopleForCode')->once()->with('111')->andReturn([
            ['name' => 'Vardas Pavardė', 'role' => 'Direktorius'],
        ]);
        $this->app->instance(CompanyRegistryRepository::class, $repo);

        $this->actingAs($this->admin());

        $page = Livewire::test(RequestConsole::class)->call('prefillFromRegistry', '111');

        $page->assertSet('data.company', 'Acmena, UAB')
            ->assertSet('data.code', '111')
            // The registry's site becomes the domain, which skips domain resolution.
            ->assertSet('data.domain', 'acmena.lt')
            ->assertSet('data.people', [['name' => 'Vardas Pavardė', 'role' => 'Direktorius']]);

        $suggestions = $page->get('data')['suggestions'];
        $byKey = array_column($suggestions, null, 'key');

        $this->assertSame('Vilnius', $byKey['address']['value']);
        $this->assertSame('info@acmena.lt', $byKey['emails']['value']);
        // Every suggestion arrives unchecked: prefill suggests, it never decides.
        foreach ($suggestions as $row) {
            $this->assertFalse($row['known'], "{$row['key']} must arrive unchecked");
        }
        // employees has no catalogue field and must not appear.
        $this->assertArrayNotHasKey('employees', $byKey);
    }

    public function test_prefilling_another_firm_replaces_the_previous_suggestions(): void
    {
        // Appending would silently mix two companies' facts into one request.
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldReceive('factsForCode')->with('111')->andReturn(['code' => '111', 'name' => 'Acmena', 'address' => 'Vilnius']);
        $repo->shouldReceive('factsForCode')->with('222')->andReturn(['code' => '222', 'name' => 'Beta', 'activity' => 'gamyba']);
        $repo->shouldReceive('peopleForCode')->andReturn([]);
        $this->app->instance(CompanyRegistryRepository::class, $repo);

        $this->actingAs($this->admin());

        $page = Livewire::test(RequestConsole::class)
            ->call('prefillFromRegistry', '111')
            ->call('prefillFromRegistry', '222');

        $keys = array_column($page->get('data')['suggestions'], 'key');

        $this->assertSame(['activity'], $keys);
        $page->assertSet('data.company', 'Beta');
    }

    public function test_prefilling_another_firm_clears_the_manual_rows_and_says_so(): void
    {
        // The sequence that shipped unguarded: a manual row typed for Alpha survived a
        // prefill of Beta and, because composeKnownFields() merges manual rows on top of the
        // suggestions, beat Beta's own registry value. Alpha's address then shipped as
        // Beta's with `caller` provenance and authoritative: true — stored, and correctable
        // by no later source and no later run. Nothing on screen ties a row to the firm it
        // was typed for, so the clearing has to be announced rather than silent.
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldReceive('factsForCode')->with('111')->andReturn(['code' => '111', 'name' => 'Alpha', 'address' => 'Vilnius']);
        $repo->shouldReceive('factsForCode')->with('222')->andReturn(['code' => '222', 'name' => 'Beta', 'address' => 'Klaipėda']);
        $repo->shouldReceive('peopleForCode')->andReturn([]);
        $this->app->instance(CompanyRegistryRepository::class, $repo);

        $sent = null;
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(function (array $target) use (&$sent): bool {
                $sent = $target;

                return true;
            }))
            ->andReturn($this->emptyCollectorResult());
        $this->app->instance(CollectorService::class, $collector);

        $this->actingAs($this->admin());

        $page = Livewire::test(RequestConsole::class)
            ->call('prefillFromRegistry', '111')
            ->set('data.known_fields', ['row-a' => ['key' => 'address', 'value' => 'A gatvė 1, Vilnius']])
            ->call('prefillFromRegistry', '222')
            ->assertNotified('Manually entered values cleared')
            ->assertSet('data.known_fields', []);

        // Beta's own suggestion, ticked: the value the run must carry.
        $this->assertSame('address', $page->get('data')['suggestions'][0]['key']);

        $page->set('data.suggestions.0.known', true)
            ->call('runRequest')
            ->assertSet('error', null);

        $this->assertSame('Beta', $sent['name'] ?? null);
        $this->assertSame(['address' => 'Klaipėda'], $sent['known_fields'] ?? null);
    }

    public function test_a_registry_failure_leaves_the_form_untouched(): void
    {
        // the registry is not always reachable. A lookup failure must cost a warning, never the form
        // the operator already filled in.
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldReceive('factsForCode')->andThrow(new \RuntimeException('max_statement_time exceeded'));
        $this->app->instance(CompanyRegistryRepository::class, $repo);

        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('data.company', 'Ranka įvesta')
            ->call('prefillFromRegistry', '111')
            ->assertSet('data.company', 'Ranka įvesta')
            ->assertSet('data.suggestions', []);
    }

    public function test_an_unknown_code_warns_and_changes_nothing(): void
    {
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldReceive('factsForCode')->andReturn(null);
        $repo->shouldNotReceive('peopleForCode');
        $this->app->instance(CompanyRegistryRepository::class, $repo);

        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('data.company', 'Ranka įvesta')
            ->call('prefillFromRegistry', '999')
            ->assertSet('data.company', 'Ranka įvesta');
    }

    public function test_a_people_lookup_failure_warns_instead_of_claiming_success(): void
    {
        // Facts succeed (identity + suggestions are good data and must stick), but the
        // people lookup fails: the operator must see a warning, not "Užpildyta iš
        // registro" over a request that only half-succeeded.
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldReceive('factsForCode')->once()->with('111')->andReturn([
            'code' => '111',
            'name' => 'Acmena, UAB',
            'address' => 'Vilnius',
        ]);
        $repo->shouldReceive('peopleForCode')->once()->with('111')->andThrow(new \RuntimeException('connection refused'));
        $this->app->instance(CompanyRegistryRepository::class, $repo);

        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->call('prefillFromRegistry', '111')
            ->assertNotified('Registry unreachable')
            ->assertNotNotified('Filled from the registry')
            ->assertSet('data.company', 'Acmena, UAB')
            ->assertSet('data.people', []);
    }

    public function test_an_unchecked_suggestion_never_reaches_known_fields(): void
    {
        // The single most important test on this page: it proves a gap stays a gap, so the
        // ladder still has something to scrape. Without it, nothing stops a later change
        // from prefilling everything as known — every run would look sufficient while
        // collecting nothing.
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(static fn (array $t): bool => ($t['known_fields'] ?? []) === ['address' => 'Vilnius']))
            ->andReturn($this->emptyCollectorResult());
        $this->app->instance(CollectorService::class, $collector);

        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('data.company', 'Acmena')
            ->set('data.fields', ['address', 'activity'])
            ->set('data.suggestions', [
                ['key' => 'address', 'value' => 'Vilnius', 'known' => true],
                ['key' => 'activity', 'value' => 'gamyba', 'known' => false],
            ])
            ->call('runRequest')
            ->assertSet('error', null);
    }

    public function test_a_multi_value_array_field_reaches_the_payload_comma_separated(): void
    {
        // The one place the mapper's arrays cross into the payload: prefill flattens
        // ['+370...','+370...'] into a single string for the repeater's TextInput, and
        // CollectorService::coerceSupplied() splits it back on commas (pinned from the
        // other side by CollectorServiceTest, which asserts a comma-separated `emails`
        // string arrives as two addresses). Every other console test that reaches
        // known_fields uses a string-typed field, so nothing pinned the separator:
        // changing ', ' to '; ' or to json_encode left the whole suite green and turned
        // two phone numbers into one malformed phone number, reported as `supplied` with
        // `caller` provenance.
        $repo = Mockery::mock(CompanyRegistryRepository::class);
        $repo->shouldReceive('factsForCode')->once()->with('111')->andReturn([
            'code' => '111',
            'name' => 'Acmena, UAB',
            'phone' => '+37060000000',
            'mobile' => '+37069999999',
        ]);
        $repo->shouldReceive('peopleForCode')->once()->with('111')->andReturn([]);
        $this->app->instance(CompanyRegistryRepository::class, $repo);

        $sent = null;
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(function (array $target) use (&$sent): bool {
                $sent = $target;

                return true;
            }))
            ->andReturn($this->emptyCollectorResult());
        $this->app->instance(CollectorService::class, $collector);

        $this->actingAs($this->admin());

        $page = Livewire::test(RequestConsole::class)->call('prefillFromRegistry', '111');

        $this->assertSame('phones', $page->get('data')['suggestions'][0]['key']);

        $page->set('data.suggestions.0.known', true)
            ->call('runRequest')
            ->assertSet('error', null);

        $phones = $sent['known_fields']['phones'] ?? null;

        $this->assertSame('+37060000000, +37069999999', $phones);
        // And it splits back into exactly the array the mapper produced, which is the
        // property that makes the flattening safe rather than merely tidy.
        $this->assertSame(
            ['+37060000000', '+37069999999'],
            array_map('trim', explode(',', (string) $phones)),
        );
    }

    public function test_a_manual_row_wins_over_a_suggestion_for_the_same_field(): void
    {
        // A person typed the manual row deliberately.
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(static fn (array $t): bool => ($t['known_fields'] ?? []) === ['address' => 'Kaunas']))
            ->andReturn($this->emptyCollectorResult());
        $this->app->instance(CollectorService::class, $collector);

        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('data.company', 'Acmena')
            ->set('data.fields', ['address'])
            ->set('data.suggestions', [['key' => 'address', 'value' => 'Vilnius', 'known' => true]])
            ->set('data.known_fields', ['row-a' => ['key' => 'address', 'value' => 'Kaunas']])
            ->call('runRequest')
            ->assertSet('error', null);
    }

    public function test_a_suggestion_for_an_undeclared_field_says_it_will_be_ignored(): void
    {
        // CollectorService drops undeclared supplied values. Saying nothing would make a
        // dropped value look exactly like one the pipeline could not fill.
        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('data.fields', ['description'])
            ->set('data.suggestions', [['key' => 'address', 'value' => 'Vilnius', 'known' => true]])
            ->assertSee('nedeklaruotas');
    }

    public function test_a_suggestion_for_a_declared_field_does_not_warn(): void
    {
        // The mirror of the case above: a mutation that always warns (regardless of the
        // declared list) would leave every previous assertion green, because none of them
        // check the absence of the warning for a field that IS declared.
        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('data.fields', ['address'])
            ->set('data.suggestions', [['key' => 'address', 'value' => 'Vilnius', 'known' => true]])
            ->assertDontSee('nedeklaruotas');
    }

    public function test_an_empty_declared_list_does_not_warn_because_the_pipeline_declares_the_required_set(): void
    {
        // The Select's own helper text advertises the empty list as meaningful ("Tuščias
        // sąrašas = katalogo privalomieji laukai."), and FieldRequirements::resolve([])
        // never returns an empty set: CollectorService declares the catalogue's required
        // fields and records the ticked value as caller-supplied and authoritative. A
        // console that computed the declared set itself warned the exact opposite here, so
        // an operator who believed the warning would untick rows that were working.
        $this->actingAs($this->admin());

        $declared = app(FieldRequirements::class)->resolve([]);
        $this->assertContains('address', $declared, 'the pipeline declares address for an empty fields[] list');

        Livewire::test(RequestConsole::class)
            ->set('data.fields', [])
            ->set('data.suggestions', [['key' => 'address', 'value' => 'Vilnius', 'known' => true]])
            ->assertDontSee('nedeklaruotas');
    }

    public function test_the_consoles_declared_set_agrees_with_field_requirements(): void
    {
        // The root cause of the empty-list defect was two computations of "what is
        // declared" — the console's own and the pipeline's. Pin them to each other for
        // every input shape that resolves differently, so a local reimplementation cannot
        // come back.
        $this->actingAs($this->admin());

        $requirements = app(FieldRequirements::class);

        /** @var array<int, array{0: array<int, string>, 1: string}> $cases */
        $cases = [
            // empty list => the catalogue's required fields, which include address
            [[], 'address'],
            // declared explicitly
            [['address'], 'address'],
            // something else declared, so this row really is undeclared
            [['description'], 'address'],
            // a key outside the enabled catalogue is dropped, falling back to the required set
            [['nera-tokio-lauko'], 'address'],
        ];

        // One page, re-declared between cases: that is what an operator does to the Select,
        // and it keeps this test from mounting the whole page four times over.
        $page = Livewire::test(RequestConsole::class)
            ->set('data.suggestions', [['key' => 'address', 'value' => 'Vilnius', 'known' => true]]);

        foreach ($cases as [$fields, $key]) {
            $declared = $requirements->resolve($fields);

            $html = $page->set('data.fields', $fields)->html();

            $this->assertSame(
                !in_array($key, $declared, true),
                str_contains($html, 'nedeklaruotas'),
                sprintf(
                    'the warning for "%s" must follow FieldRequirements::resolve(%s) = %s',
                    $key,
                    json_encode($fields),
                    json_encode($declared),
                ),
            );
        }
    }

    public function test_the_declared_fields_select_and_known_checkbox_are_live(): void
    {
        // `->set()` in a Livewire test always performs a full synthetic state sync, so a test
        // that drives state and then asserts on the rendered warning text would pass identically
        // whether or not the components are marked live() — it cannot catch a stale warning.
        // The only way to pin "the warning updates without any other action" is to inspect the
        // schema itself: the components the helper text depends on must be live.
        $this->actingAs($this->admin());

        $page = Livewire::test(RequestConsole::class)
            ->set('data.suggestions', [['key' => 'address', 'value' => 'Vilnius', 'known' => false]]);

        $schema = $page->instance()->getSchema('form');

        $fields = $schema->getComponent('fields');
        $this->assertNotNull($fields, 'the "fields" Select must be present in the schema');
        $this->assertTrue($fields->isLive(), 'the "fields" Select must be live so ticking it re-evaluates the "nedeklaruotas" helper text');

        $known = $schema->getComponent('suggestions.0.known');
        $this->assertNotNull($known, 'the suggestion row\'s "known" Checkbox must be present in the schema');
        $this->assertTrue($known->isLive(), 'the "known" Checkbox must be live so ticking it re-evaluates the "nedeklaruotas" helper text');
    }

    public function test_the_suggestions_repeater_label_is_hidden(): void
    {
        // ->label('') looks like it hides the label, but Filament's Field::getLabel()
        // falls back to the humanized field name whenever the label is not `filled()` —
        // and '' is never filled(). The browser caught the leak: a plain "Suggestions"
        // heading rendered above the repeater. hiddenLabel() is the real way to hide it.
        $this->actingAs($this->admin());

        // The Section around the repeater is only visible() once there is a suggestion,
        // and an invisible component never registers itself in the schema.
        $page = Livewire::test(RequestConsole::class)
            ->set('data.suggestions', [['key' => 'address', 'value' => 'Vilnius', 'known' => false]]);

        $schema = $page->instance()->getSchema('form');

        $suggestions = $schema->getComponent('suggestions');
        $this->assertNotNull($suggestions, 'the "suggestions" Repeater must be present in the schema');
        $this->assertTrue($suggestions->isLabelHidden(), 'the "suggestions" Repeater label must be hidden, not merely blank');
    }

    public function test_the_people_repeater_is_not_under_the_nebeieskoma_promise(): void
    {
        // The "supplied and no longer searched for" sentence is true for known_fields and
        // the exact inverse of the truth for people: typing a name is what triggers the
        // news search about them. Structural, not textual: a copy tweak that leaves the
        // repeater inside that section would leave a textual assertion green.
        $this->actingAs($this->admin());

        $schema = Livewire::test(RequestConsole::class)->instance()->getSchema('form');
        $people = $schema->getComponent('people');

        $this->assertNotNull($people, 'the "people" Repeater must be present in the schema');
        $section = $people->getContainer()->getParentComponent();
        $this->assertNotNull($section);
        $this->assertSame('People to research', $section->getHeading());
    }

    public function test_the_people_section_states_that_the_names_are_researched_and_shows_the_limit(): void
    {
        config()->set('react.news.portals_per_query', 7);
        config()->set('react.news.max_queries', 4);
        config()->set('react.news.max_people', 3);

        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->assertSee('whose news is searched for')
            ->assertSee('searched for: 1');
    }

    public function test_the_result_marks_which_person_was_actually_researched(): void
    {
        // The badge comes from THIS run's news action, which is where a run's own report
        // always lands. It used to be readable from $profile['news'] too, and that blob is
        // the accumulated per-company one — so the fixture below is now the only shape that
        // can produce the badge, which is exactly the point.
        $this->newsActionFor('rh', 'empty', [
            'search_state' => NewsSource::SEARCH_ANSWERED,
            'people_searched' => ['Ieškotas Vardenis'],
            'people_dropped' => ['Netilpo Vardenis'],
            'people_dropped_reasons' => [['name' => 'Netilpo Vardenis', 'reason' => 'query_budget']],
        ]);

        $this->bindRepo([]);
        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('result', $this->resultWithPeople('rh', [
                ['name' => 'Ieškotas Vardenis', 'role' => 'Vadovas', 'source' => 'registry_db'],
                ['name' => 'Netilpo Vardenis', 'role' => null, 'source' => 'registry_db'],
            ]))
            ->assertSee('Ieškotas Vardenis')
            ->assertSee('news searched');
    }

    public function test_a_person_without_a_role_does_not_render_a_dangling_dash(): void
    {
        // The registry states no role for most people, and an em dash standing in for
        // nothing reads as data. Nothing is nothing.
        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('result', [
                'run_hash' => 'rh',
                'profile' => ['people' => [['name' => 'Be Pareigu', 'role' => null, 'source' => 'registry_db']]],
                'field_sources' => [],
                'coverage' => ['overall' => 0.0, 'fields' => [], 'missing' => []],
                'sources_used' => [],
                'verdict' => 'none',
                'field_status' => [],
            ])
            ->assertSee('Be Pareigu')
            // Verified against the real render (assertDontSee on an HTML fragment is
            // fragile): today the role span always renders, so a role-less person gets
            // this exact dash span right after their name with no guard around it.
            ->assertDontSee('<span class="text-gray-500 dark:text-gray-400">—</span>', escape: false);
    }

    public function test_an_unanswered_search_is_not_shown_as_a_completed_search_that_found_nothing(): void
    {
        // The defect a live browser QA pass found: the console said "No mentions found"
        // when the search backend never answered a single query — CAPTCHA-blocked,
        // degraded engines — and nobody could tell that apart from a clean search that
        // simply found nothing. mergeNews() only writes profile['news'] when at least
        // one mention survives (CollectorService::collect()), so a fully blind run
        // leaves the profile with no 'news' key at all — the report only ever reaches
        // the news action's own recorded detail. This pins the fallback: the badge next
        // to the unanswered person must say so, and must never be the same "ieškota
        // naujienų" badge a person who was actually asked about gets.
        CollectionAction::create([
            'run_hash' => 'rh-blind',
            'source' => 'news',
            'action' => 'collect',
            'status' => 'error',
            'detail' => json_encode([
                'news' => [
                    'score' => 0.0,
                    'fresh' => false,
                    'mentions' => [],
                    // The status is only half the fact; this is the half that says the
                    // backend answered nothing at all, as NewsSource writes it.
                    'search_state' => NewsSource::SEARCH_UNANSWERED,
                    'people_searched' => [],
                    'people_dropped' => ['Ieškotas Vardenis'],
                    'people_dropped_reasons' => [
                        ['name' => 'Ieškotas Vardenis', 'reason' => 'search_unanswered'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('result', [
                'run_hash' => 'rh-blind',
                // No 'news' key: exactly what a blind run leaves behind.
                'profile' => ['people' => [
                    ['name' => 'Ieškotas Vardenis', 'role' => 'Vadovas', 'source' => 'registry_db'],
                ]],
                'field_sources' => [],
                'coverage' => ['overall' => 0.5, 'fields' => [], 'missing' => []],
                'sources_used' => ['registry_db'],
                'verdict' => 'partial',
                'field_status' => [],
            ])
            ->assertSee('Ieškotas Vardenis')
            ->assertSee('search not answered')
            ->assertDontSee('news searched')
            // The Naujienos section a few headings below reads the exact same profile
            // (no 'news' key) and, before this fix, fell back to its own untouched
            // "$news === []" check — printing "No mentions found" on the very screen
            // that just said the search went unanswered. Two statements that cannot
            // both be true is worse than the original defect: an operator has no way
            // to tell which half of the page to trust.
            ->assertDontSee('No mentions found')
            ->assertSee('unknown whether mentions exist')
            // The heading counts too: "News (0)" above a body saying the count is
            // unknown is the same contradiction one line higher up.
            ->assertSee('News (?)')
            ->assertDontSee('News (0)');
    }

    public function test_a_search_answered_with_nothing_is_not_called_a_search_nobody_answered(): void
    {
        // The `error` status carries TWO facts: no query was answered, and every query was
        // answered and came back with an empty list (rate-limited or CAPTCHA-suspended
        // engines — the live condition this work started from). The second one credits its
        // people in people_searched, so a page keying its wording on the status alone put
        // "The search was not answered" on the same screen as a green "news searched" next
        // to the very person that search had asked about. The backend answered; only
        // whether a mention exists is unknown, and the two sentences must differ.
        $this->newsActionFor('rh-blank', 'error', [
            'search_state' => NewsSource::SEARCH_RETURNED_NOTHING,
            'people_searched' => ['Ieškotas Vardenis'],
            'people_dropped' => [],
            'people_dropped_reasons' => [],
        ]);

        // The registry table above the result is not what is under test, and an unmocked
        // lookup pays a connection timeout when the registry is unreachable.
        $this->bindRepo([]);
        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('result', $this->resultWithPeople('rh-blank', [
                ['name' => 'Ieškotas Vardenis', 'role' => 'Vadovas', 'source' => 'registry_db'],
            ]))
            // Credited, and rightly: a query naming him was answered.
            ->assertSee('news searched')
            // So the page must not, on that same screen, deny the search was answered.
            ->assertDontSee('was not answered')
            ->assertSee('The search returned nothing')
            ->assertSee('unknown whether mentions exist')
            // Nor claim the opposite: zero raw results is not evidence of zero mentions —
            // in the heading's count any more than in the sentence below it.
            ->assertDontSee('No mentions found')
            ->assertSee('News (?)')
            ->assertDontSee('News (0)');
    }

    public function test_a_search_that_answered_and_filtered_to_zero_still_says_no_mentions_were_found(): void
    {
        // The honest half of the same distinction, and the one nothing pinned: an `empty`
        // run DID see results and judged them off-topic, so "No mentions found" is a claim
        // it can support. Widening the uncertainty wording to every non-ok status would
        // silently replace this true sentence with "we do not know".
        $this->newsActionFor('rh-empty', 'empty', [
            'search_state' => NewsSource::SEARCH_ANSWERED,
            'people_searched' => ['Ieškotas Vardenis'],
            'people_dropped' => [],
            'people_dropped_reasons' => [],
        ]);

        $this->bindRepo([]);
        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('result', $this->resultWithPeople('rh-empty', [
                ['name' => 'Ieškotas Vardenis', 'role' => 'Vadovas', 'source' => 'registry_db'],
            ]))
            ->assertSee('No mentions found')
            ->assertSee('news searched')
            ->assertDontSee('unknown whether mentions exist')
            // The other direction of the same rule: this run CAN support a count of zero,
            // so it states it. Hiding every count behind '?' would trade one imprecision
            // for another.
            ->assertSee('News (0)')
            ->assertDontSee('News (?)');
    }

    public function test_each_budget_that_dropped_a_person_is_named_on_that_persons_badge(): void
    {
        // Both ceilings are deliberate and reported, never raised — but they call for
        // different responses (a slow backend versus a query ceiling with no room), so the
        // operator must be able to tell which one cost which person. Nothing asserted these
        // two strings anywhere, so either could have been reworded into the other.
        $this->newsActionFor('rh-budgets', 'empty', [
            'search_state' => NewsSource::SEARCH_ANSWERED,
            'people_searched' => [],
            'people_dropped' => ['Pavelavo Vardenis', 'Netilpo Vardenis'],
            'people_dropped_reasons' => [
                ['name' => 'Pavelavo Vardenis', 'reason' => 'time_budget'],
                ['name' => 'Netilpo Vardenis', 'reason' => 'query_budget'],
            ],
        ]);

        $this->bindRepo([]);
        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('result', $this->resultWithPeople('rh-budgets', [
                ['name' => 'Pavelavo Vardenis', 'role' => null, 'source' => 'registry_db'],
                ['name' => 'Netilpo Vardenis', 'role' => null, 'source' => 'registry_db'],
            ]))
            ->assertSee('time budget spent')
            ->assertSee('query budget spent')
            ->assertDontSee('news searched');
    }

    public function test_a_person_whose_stored_value_is_not_a_name_is_badged_as_unresearched(): void
    {
        // The badge an operator reads off this row decides what they do next, and the two
        // wordings call for opposite actions: a budget badge says "raise a setting", this one
        // says "the stored value is not a name, so no search could have matched it". Green
        // beside such a row is the false-confidence defect the whole person report exists to
        // remove, so this asserts both what IS said and what is not.
        $this->newsActionFor('rh-unusable', 'empty', [
            'search_state' => NewsSource::SEARCH_ANSWERED,
            'people_searched' => ['Ieškotas Vardenis'],
            'people_dropped' => ['Indrė Šilkūnienė, direktorė'],
            'people_dropped_reasons' => [
                ['name' => 'Indrė Šilkūnienė, direktorė', 'reason' => 'unusable_name'],
            ],
        ]);

        $this->bindRepo([]);
        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('result', $this->resultWithPeople('rh-unusable', [
                ['name' => 'Ieškotas Vardenis', 'role' => 'Vadovas', 'source' => 'registry_db'],
                ['name' => 'Indrė Šilkūnienė, direktorė', 'role' => null, 'source' => 'registry_db'],
            ]))
            ->assertSee('name not recognised — not searched')
            ->assertDontSee('query budget spent')
            ->assertDontSee('time budget spent');
    }

    public function test_a_run_with_no_news_action_claims_nothing_about_news(): void
    {
        // Door (a): react.news.enabled=false, so NewsSource::supports() is false and
        // CollectorService records no news action at all. The profile still holds an earlier,
        // mention-producing run's blob — ordinary for a re-run — and the page used to render
        // that older run's mentions AND its "we looked at this person" badge as this run's.
        $this->assertNoNewsIsClaimedFor('rh-no-action');
    }

    public function test_a_skipped_news_action_without_a_report_claims_nothing_about_news(): void
    {
        // Door (b): every portal disabled at /admin/news-portals, or a company name with no
        // searchable token. NewsSource returns `skipped` with meta that names the reason and
        // carries no 'news' key, so this run has a row and no report inside it.
        CollectionAction::create([
            'run_hash' => 'rh-skipped',
            'source' => 'news',
            'action' => 'collect',
            'status' => 'skipped',
            'detail' => json_encode(['detail' => 'no enabled news portals'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->assertNoNewsIsClaimedFor('rh-skipped');
    }

    public function test_a_news_action_whose_detail_could_not_be_encoded_claims_nothing_about_news(): void
    {
        // Door (c): recordAction() stores `json_encode(...) ?: null`, so a meta blob that
        // cannot be encoded leaves a row carrying a status and no detail. Today the bad byte
        // that would cause it also aborts the run one line later — see
        // CollectorServiceTest::test_an_article_title_with_invalid_utf8_fails_the_run_instead_of_finishing_it_quietly
        // — so this shape is not reachable end to end right now. It is still a state the
        // storage code explicitly allows, and the page must not answer it with an older run's
        // report if lenient encoding ever makes it reachable.
        CollectionAction::create([
            'run_hash' => 'rh-no-detail',
            'source' => 'news',
            'action' => 'collect',
            'status' => 'empty',
            'detail' => null,
        ]);

        $this->assertNoNewsIsClaimedFor('rh-no-detail');
    }

    /**
     * Nothing about news may be claimed for a run we hold no news report for: not the earlier
     * run's mentions, not its "news searched" badge, and not "No mentions found" either.
     * The Naujienos section is absent altogether, because a heading reading "News (0)"
     * is itself the claim. The person stays listed — silence about the search is not silence
     * about who this company's directors are.
     */
    private function assertNoNewsIsClaimedFor(string $runHash): void
    {
        $this->bindRepo([]);
        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('result', $this->resultWithStaleProfileNews($runHash))
            ->assertSee('Ieškotas Vardenis')
            ->assertDontSee('news searched')
            ->assertDontSee('No mentions found')
            ->assertDontSee('unknown whether mentions exist')
            // Checked against the real render, not inferred: the stale title and URL both
            // appear in the collapsed raw-response dump at the foot of the page (that is the
            // API answer verbatim, not a claim about the run), so the probe for "the mentions
            // table is not rendered" has to be markup only the table produces.
            ->assertDontSee('href="https://delfi.lt/a/1"', escape: false)
            ->assertDontSee('shows whether a mention is attributed');
    }

    /**
     * A finished run whose PROFILE already carries an earlier, mention-producing run's news
     * blob. That is the precondition behind all three staleness doors, and it is the ordinary
     * state of any company enriched more than once.
     *
     * @return array<string, mixed>
     */
    private function resultWithStaleProfileNews(string $runHash): array
    {
        $result = $this->resultWithPeople($runHash, [
            ['name' => 'Ieškotas Vardenis', 'role' => 'Vadovas', 'source' => 'registry_db'],
        ]);

        $result['profile']['news'] = [
            'score' => 0.6,
            'fresh' => true,
            'mentions' => [[
                'portal' => 'delfi.lt',
                'title' => 'Ankstesnio bandymo paminejimas',
                'url' => 'https://delfi.lt/a/1',
                'date' => '2026-05-01',
                'subject' => 'person',
                'person' => 'Ieškotas Vardenis',
            ]],
            'search_state' => NewsSource::SEARCH_ANSWERED,
            'people_searched' => ['Ieškotas Vardenis'],
            'people_dropped' => [],
            'people_dropped_reasons' => [],
        ];

        return $result;
    }

    public function test_a_mention_whose_article_body_was_not_found_says_so_rather_than_showing_the_menu(): void
    {
        // The excerpt is what an operator judges a mention by, so two different facts must
        // not render identically: an article whose words we hold, and a page we downloaded
        // and converted that turned out to have no article on it (a topic page, a paywall
        // stub). Before this, the console printed the first 600 characters of whatever came
        // back — on most portals the navigation menu — under the headline, which reads as
        // the article's own words. The stored artifact path shows for both, because when
        // the body was not located that file is exactly what needs opening.
        $body = (string) ArticleExcerpt::fromMarkdown(
            (string) file_get_contents(base_path('tests/Fixtures/news/lrytas-logistikos-centras.md')),
            600,
        );

        $this->newsActionFor('rh-excerpt', 'ok', [
            'search_state' => NewsSource::SEARCH_ANSWERED,
            'people_searched' => [],
            'people_dropped' => [],
            'people_dropped_reasons' => [],
            'mentions' => [
                [
                    'portal' => 'lrytas.lt',
                    'title' => 'Baltijos siunta plečiasi kaimyninėje šalyje',
                    'url' => 'https://lrytas.lt/a/1',
                    'date' => '2026-05-01',
                    'subject' => 'company',
                    'person' => null,
                    'excerpt' => $body,
                    'excerpt_state' => NewsSource::EXCERPT_BODY,
                    'artifacts' => ['markdown' => 'runs/s1/lrytas.lt/analyze/news/straipsnis.md'],
                ],
                [
                    'portal' => '15min.lt',
                    'title' => 'Didmena tema',
                    'url' => 'https://15min.lt/tema/didmena-519',
                    'date' => '2026-05-02',
                    'subject' => 'company',
                    'person' => null,
                    'excerpt_state' => NewsSource::EXCERPT_NO_BODY,
                    'artifacts' => ['markdown' => 'runs/s1/15min.lt/analyze/news/tema.md'],
                ],
            ],
        ]);

        $this->bindRepo([]);
        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->set('result', $this->resultWithPeople('rh-excerpt', []))
            ->assertSee('Yšužūvos višoš mėdūsmas avėpoų etošūatymo ūzolėas')
            ->assertSee('No article text was found on this page')
            // Both halves, because the first half was true of the earlier wording too: that
            // one asserted the page opened with navigation, which is false for a paywall
            // stub or a JS-only shell — the other two things no_body_found covers.
            ->assertSee('the page was downloaded, but')
            ->assertSee('no article body was recognised in it')
            ->assertDontSee('yra portalo navigacija')
            ->assertSee('runs/s1/lrytas.lt/analyze/news/straipsnis.md')
            ->assertSee('runs/s1/15min.lt/analyze/news/tema.md');
    }

    /**
     * The news action row the console reads first, keyed to this run. It is the only place
     * a run that produced no surviving mention leaves its report at all: mergeNews() is
     * gated on mentions, while recordAction() runs on every NewsSource path.
     *
     * @param array<string, mixed> $news
     */
    private function newsActionFor(string $runHash, string $status, array $news): void
    {
        CollectionAction::create([
            'run_hash' => $runHash,
            'source' => 'news',
            'action' => 'collect',
            'status' => $status,
            'detail' => json_encode([
                'news' => ['score' => 0.0, 'fresh' => false, 'mentions' => [], ...$news],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * A finished run carrying people and no news key on the profile — what a run without
     * surviving mentions really leaves behind.
     *
     * @param array<int, array<string, mixed>> $people
     *
     * @return array<string, mixed>
     */
    private function resultWithPeople(string $runHash, array $people): array
    {
        return [
            'run_hash' => $runHash,
            'profile' => ['people' => $people],
            'field_sources' => [],
            'coverage' => ['overall' => 0.5, 'fields' => [], 'missing' => []],
            'sources_used' => ['registry_db'],
            'verdict' => 'partial',
            'field_status' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCollectorResult(): array
    {
        return [
            'run_hash' => 'rh', 'profile' => [], 'field_sources' => [],
            'coverage' => ['overall' => 0.0, 'fields' => [], 'missing' => []],
            'sources_used' => [], 'verdict' => 'none', 'field_status' => [],
        ];
    }

    public function test_an_unconfigured_registry_is_not_reported_as_unreachable(): void
    {
        config()->set('company_registry.connection.host', '');
        $mock = Mockery::mock(CompanyRegistryRepository::class);
        $mock->shouldNotReceive('searchGapFirms');
        $this->app->instance(CompanyRegistryRepository::class, $mock);

        $this->actingAs($this->admin());

        Livewire::test(RequestConsole::class)
            ->assertOk()
            ->assertSee('No registry database is configured')
            ->assertDontSee('registry database is unreachable');
    }
}
