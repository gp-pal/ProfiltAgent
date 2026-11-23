<?php

namespace Tests\Unit;

use App\Services\CollectorService;
use App\Services\ReActLoopService;
use Mockery;
use Tests\TestCase;

class RunControllerCollectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Focus on routing, not auth — disable the API-key gate for these tests.
        config(['agent.api_key' => '']);
    }

    public function test_defaults_to_the_collector(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(static fn (array $t): bool => $t['name'] === 'Acmena' && $t['country'] === 'LT' && $t['domain'] === 'acmena.lt'))
            ->andReturn([
                'run_hash' => 'run-abc',
                'profile' => ['description' => 'Statybos įmonė', 'address' => 'Vilnius'],
                'field_sources' => ['description' => 'open_web', 'address' => 'registry_db'],
                'coverage' => ['overall' => 0.42, 'fields' => [], 'missing' => ['emails', 'phones']],
                'sources_used' => ['registry_db', 'open_web'],
                'verdict' => 'partial',
            ]);
        $this->app->instance(CollectorService::class, $collector);

        // No 'collect' flag — the collector is the default.
        $response = $this->postJson('/api/run', ['company' => 'Acmena', 'domain' => 'acmena.lt']);

        $response->assertStatus(200);
        $response->assertJson([
            'run_hash' => 'run-abc',
            'verdict' => 'partial',
            'sources_used' => ['registry_db', 'open_web'],
        ]);
    }

    public function test_collect_false_opts_out_to_step1_pipeline(): void
    {
        $react = Mockery::mock(ReActLoopService::class);
        $react->shouldReceive('runStep1Bootstrap')->once()->andReturn([
            'ok' => true,
            'done' => true,
            'domain' => 'acmena.lt',
            'resolved_via' => null,
            'error' => null,
            'profile' => ['name' => 'Acmena'],
        ]);
        $this->app->instance(ReActLoopService::class, $react);

        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldNotReceive('collect');
        $this->app->instance(CollectorService::class, $collector);

        // Explicit opt-out returns the raw Step-1 response.
        $response = $this->postJson('/api/run', ['company' => 'Acmena', 'domain' => 'acmena.lt', 'collect' => false]);

        $response->assertStatus(200);
        $response->assertJson(['ok' => true, 'done' => true, 'domain' => 'acmena.lt']);
    }

    /**
     * The verification pass has to be reachable by the consumer, not only by the admin console:
     * a firm enriched before verification was stored will never re-collect anything, so without
     * a way to ask, its fields read `unknown` forever. Default OFF — it spends a full ladder on
     * data we already hold — which is the half of the contract worth pinning too.
     */
    public function test_verify_fields_reaches_the_collector_and_defaults_off(): void
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
                'run_hash' => 'run-verify',
                'profile' => [],
                'field_sources' => [],
                'coverage' => ['overall' => 0.5, 'fields' => [], 'missing' => []],
                'sources_used' => [],
                'verdict' => 'partial',
            ]);
        $this->app->instance(CollectorService::class, $collector);

        $this->postJson('/api/run', ['company' => 'Acmena', 'verify_fields' => true])->assertStatus(200);
        $this->postJson('/api/run', ['company' => 'Acmena'])->assertStatus(200);

        $this->assertSame([true, false], $seen);
    }

    public function test_validation_failure_is_422_json_even_without_accept_header(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldNotReceive('collect');
        $this->app->instance(CollectorService::class, $collector);

        // A non-JSON-negotiating client (no Accept: application/json), like the
        // an API consumer, must still get 422 JSON — never a 302 redirect
        // (which points at an unreachable https host behind the proxy).
        $response = $this->post('/api/run', ['collect' => true], ['Accept' => 'text/html']);

        $response->assertStatus(422);
        $response->assertJson(['ok' => false, 'error' => 'validation_failed']);
        $response->assertJsonStructure(['errors' => ['company']]);
    }

    public function test_missing_company_is_a_422_validation_error(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldNotReceive('collect');
        $this->app->instance(CollectorService::class, $collector);

        // RunRequest validates before the controller runs, so a missing company is
        // a proper 422 (not swallowed into a 500 by the action's try/catch).
        $response = $this->postJson('/api/run', ['domain' => 'acmena.lt']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('company');
    }

    public function test_registry_code_reaches_the_collector(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(static fn (array $t): bool => ($t['code'] ?? null) === '300000123' && $t['name'] === 'Didmena LT, UAB'))
            ->andReturn([
                'run_hash' => 'run-code',
                'profile' => ['description' => 'Prekybos tinklas'],
                'field_sources' => ['description' => 'registry_db'],
                'coverage' => ['overall' => 1.0, 'fields' => [], 'missing' => []],
                'sources_used' => ['registry_db'],
                'verdict' => 'sufficient',
            ]);
        $this->app->instance(CollectorService::class, $collector);

        // A caller-supplied registry code lets CompanyRegistrySource hit the indexed
        // factsForCode() lookup instead of resolveCode()'s leading-wildcard LIKE
        // scans, which measured ~356s against a live registry DB.
        $response = $this->postJson('/api/run', ['company' => 'Didmena LT, UAB', 'code' => '300000123']);

        $response->assertStatus(200);
        $response->assertJson(['sources_used' => ['registry_db']]);
    }

    public function test_people_reach_the_collector(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(static fn (array $t): bool => ($t['people'] ?? []) === [
                ['name' => 'Vardas Pavardė', 'role' => 'Direktorius'],
            ]))
            ->andReturn([
                'run_hash' => 'run-people',
                'profile' => [],
                'field_sources' => [],
                'coverage' => ['overall' => 0.5, 'fields' => [], 'missing' => []],
                'sources_used' => ['news'],
                'verdict' => 'partial',
            ]);
        $this->app->instance(CollectorService::class, $collector);

        $response = $this->postJson('/api/run', [
            'company' => 'Acmena',
            'people' => [['name' => 'Vardas Pavardė', 'role' => 'Direktorius']],
        ]);

        $response->assertStatus(200);
    }

    public function test_lithuanian_diacritics_are_accepted_in_person_names(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')->once()->andReturn([
            'run_hash' => 'run-lt',
            'profile' => [],
            'field_sources' => [],
            'coverage' => ['overall' => 0.5, 'fields' => [], 'missing' => []],
            'sources_used' => [],
            'verdict' => 'partial',
        ]);
        $this->app->instance(CollectorService::class, $collector);

        $response = $this->postJson('/api/run', [
            'company' => 'Acmena',
            'people' => [['name' => 'Žygimantas Šiaulys-Ąžuolas']],
        ]);

        $response->assertStatus(200);
    }

    public function test_person_name_with_invalid_characters_is_a_422(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldNotReceive('collect');
        $this->app->instance(CollectorService::class, $collector);

        $response = $this->postJson('/api/run', [
            'company' => 'Acmena',
            'people' => [['name' => 'Robert<script>']],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('people.0.name');
    }

    public function test_person_without_a_name_is_a_422(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldNotReceive('collect');
        $this->app->instance(CollectorService::class, $collector);

        $response = $this->postJson('/api/run', ['company' => 'Acmena', 'people' => [['role' => 'Direktorius']]]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('people.0.name');
    }

    public function test_non_alphanumeric_code_is_a_422_validation_error(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldNotReceive('collect');
        $this->app->instance(CollectorService::class, $collector);

        $response = $this->postJson('/api/run', ['company' => 'Acmena', 'code' => '123; DROP TABLE']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('code');
    }

    public function test_requested_fields_reach_the_collector(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(static fn (array $t): bool => ($t['fields'] ?? []) === ['description', 'brands']))
            ->andReturn([
                'run_hash' => 'run-fields', 'profile' => [], 'field_sources' => [],
                'coverage' => ['overall' => 0.5, 'fields' => [], 'missing' => []],
                'sources_used' => [], 'verdict' => 'partial',
            ]);
        $this->app->instance(CollectorService::class, $collector);

        $response = $this->postJson('/api/run', ['company' => 'Acmena', 'fields' => ['description', 'brands']]);

        $response->assertStatus(200);
    }

    public function test_a_field_key_with_illegal_characters_is_a_422(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldNotReceive('collect');
        $this->app->instance(CollectorService::class, $collector);

        $response = $this->postJson('/api/run', ['company' => 'Acmena', 'fields' => ['DROP TABLE']]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('fields.0');
    }

    public function test_known_field_values_reach_the_collector(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(static fn (array $t): bool => ($t['known_fields'] ?? []) === ['description' => 'Jau turim aprašymą']))
            ->andReturn([
                'run_hash' => 'run-known', 'profile' => [], 'field_sources' => [],
                'coverage' => ['overall' => 0.5, 'fields' => [], 'missing' => []],
                'sources_used' => [], 'verdict' => 'partial', 'field_status' => [],
            ]);
        $this->app->instance(CollectorService::class, $collector);

        $response = $this->postJson('/api/run', [
            'company' => 'Acmena',
            'known_fields' => ['description' => 'Jau turim aprašymą', 'BAD KEY' => 'x', 'empty' => '   '],
        ]);

        $response->assertStatus(200);
    }

    public function test_an_over_long_known_field_scalar_is_dropped_not_truncated(): void
    {
        // A supplied value is authoritative and persisted/returned exactly as sent —
        // truncating it would misrepresent what the caller told us it holds, so an
        // over-long value must be dropped whole, not clipped to the cap.
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(static fn (array $t): bool => ($t['known_fields'] ?? []) === ['address' => 'Trumpas adresas']))
            ->andReturn([
                'run_hash' => 'run-cap', 'profile' => [], 'field_sources' => [],
                'coverage' => ['overall' => 0.5, 'fields' => [], 'missing' => []],
                'sources_used' => [], 'verdict' => 'partial', 'field_status' => [],
            ]);
        $this->app->instance(CollectorService::class, $collector);

        $response = $this->postJson('/api/run', [
            'company' => 'Acmena',
            'known_fields' => [
                'description' => str_repeat('a', 2001),
                'address' => 'Trumpas adresas',
            ],
        ]);

        $response->assertStatus(200);
    }

    public function test_an_over_sized_known_field_array_is_capped_at_fifty_and_over_long_elements_are_dropped(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(static function (array $t): bool {
                $emails = $t['known_fields']['emails'] ?? null;

                // The over-long element ('bad@' + 2000 chars) never made it in, and
                // the surviving 60 short elements were capped to 50 — dropped, not
                // truncated to 50 by clipping the list some other way.
                return is_array($emails)
                    && count($emails) === 50
                    && !in_array('bad@' . str_repeat('x', 2000) . '.lt', $emails, true)
                    && $emails[0] === 'a0@acmena.lt';
            }))
            ->andReturn([
                'run_hash' => 'run-cap2', 'profile' => [], 'field_sources' => [],
                'coverage' => ['overall' => 0.5, 'fields' => [], 'missing' => []],
                'sources_used' => [], 'verdict' => 'partial', 'field_status' => [],
            ]);
        $this->app->instance(CollectorService::class, $collector);

        $emails = array_map(static fn (int $i): string => "a{$i}@acmena.lt", range(0, 59));
        $emails[] = 'bad@' . str_repeat('x', 2000) . '.lt';

        $response = $this->postJson('/api/run', [
            'company' => 'Acmena',
            'known_fields' => ['emails' => $emails],
        ]);

        $response->assertStatus(200);
    }
}
