<?php

namespace Tests\Unit;

use App\Console\Commands\EnrichFirms;
use App\Observability\LangfuseTracer;
use App\Services\CollectorService;
use App\Tools\CompanyRegistryRepository;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class EnrichFirmsTest extends TestCase
{
    private $repo;
    private $collector;
    private $tracer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = Mockery::mock(CompanyRegistryRepository::class);
        $this->collector = Mockery::mock(CollectorService::class);
        $this->tracer = Mockery::mock(LangfuseTracer::class);

        $this->app->instance(CompanyRegistryRepository::class, $this->repo);
        $this->app->instance(CollectorService::class, $this->collector);
        $this->app->instance(LangfuseTracer::class, $this->tracer);
    }

    public function test_collects_gap_firms_and_writes_manifest(): void
    {
        Storage::fake('local');

        $this->repo->shouldReceive('assertReachable')->once();
        $this->repo->shouldReceive('gapFirms')->once()->with(1, 1, false)->andReturn([
            ['code' => '111', 'name' => 'Acmena', 'site' => 'http://acmena.lt', 'address' => 'Gedimino pr. 1, Vilnius'],
            ['code' => '222', 'name' => 'Beta', 'site' => null, 'address' => null],
        ]);

        // Proves the collector target carries code/normalized-domain/registry facts.
        $this->collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(static fn (array $t): bool => $t['code'] === '111'
                && $t['domain'] === 'acmena.lt'
                && $t['address'] === 'Gedimino pr. 1, Vilnius'
                && $t['country'] === 'LT'
                && $t['session'] === 'firm-111'))
            ->andReturn([
                'run_hash' => 'hash-111',
                'profile' => ['description' => 'Statybos įmonė', 'address' => 'Gedimino pr. 1, Vilnius', 'emails' => ['info@acmena.lt']],
                'field_sources' => ['description' => 'official_site', 'address' => 'registry_db'],
                'coverage' => ['overall' => 0.83, 'fields' => [], 'missing' => []],
                'sources_used' => ['registry_db', 'official_site'],
                'verdict' => 'sufficient',
            ]);

        // A per-firm exception must not abort the batch — it becomes verdict=error.
        $this->collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(static fn (array $t): bool => $t['code'] === '222' && $t['domain'] === null))
            ->andThrow(new \RuntimeException('boom'));

        $this->tracer->shouldReceive('startTrace')->twice();
        $this->tracer->shouldReceive('setTraceOutput')->twice();
        $this->tracer->shouldReceive('flush')->twice();
        $this->tracer->shouldReceive('span')->andReturnUsing(function () {
            $h = Mockery::mock(\App\Observability\TraceHandle::class);
            $h->shouldReceive('end');

            return $h;
        });

        $this->artisan('agent:enrich-firms', ['--with-site' => 1, '--without-site' => 1])->assertExitCode(0);

        $manifestFile = collect(Storage::disk('local')->allFiles('runs/enrichment'))
            ->first(static fn (string $path): bool => str_ends_with($path, 'manifest.json'));
        $this->assertNotNull($manifestFile);
        $manifest = json_decode(Storage::disk('local')->get($manifestFile), true);

        $this->assertSame(1, $manifest['counts']['collected']);
        $this->assertSame(2, $manifest['counts']['total']);
        $this->assertSame(1, $manifest['verdicts']['sufficient']);
        $this->assertSame(1, $manifest['verdicts']['error']);

        $acmena = $manifest['firms'][0];
        $this->assertSame('hash-111', $acmena['run_hash']);
        $this->assertSame('sufficient', $acmena['verdict']);
        $this->assertSame(0.83, $acmena['coverage']);
        $this->assertSame(['registry_db', 'official_site'], $acmena['sources_used']);
        $this->assertSame('Statybos įmonė', $acmena['profile']['description']);
        $this->assertSame('registry_db', $acmena['field_sources']['address']);

        $beta = $manifest['firms'][1];
        $this->assertSame('error', $beta['verdict']);
        $this->assertNull($beta['run_hash']);
    }

    public function test_a_data_limited_firm_counts_as_collected(): void
    {
        // data_limited means the ladder ran cleanly to completion with a declared
        // field still missing — CollectorService::collect() can only reach that
        // branch past `sources_used === [] -> 'none'`, so it guarantees a source
        // actually contributed data. It must count as "collected", the same as a
        // partial run, or the before/after measurement undercounts real progress.
        Storage::fake('local');

        $this->repo->shouldReceive('assertReachable')->once();
        $this->repo->shouldReceive('gapFirms')->once()->with(1, 0, false)->andReturn([
            ['code' => '333', 'name' => 'Azerna', 'site' => null, 'address' => null],
        ]);

        $this->collector->shouldReceive('collect')
            ->once()
            ->andReturn([
                'run_hash' => 'hash-333',
                'profile' => ['address' => 'Kaunas'],
                'field_sources' => ['address' => 'registry_db'],
                'coverage' => ['overall' => 0.167, 'fields' => [], 'missing' => ['description']],
                'sources_used' => ['registry_db'],
                'verdict' => 'data_limited',
            ]);

        $this->tracer->shouldReceive('startTrace')->once();
        $this->tracer->shouldReceive('setTraceOutput')->once();
        $this->tracer->shouldReceive('flush')->once();

        $this->artisan('agent:enrich-firms', ['--with-site' => 1, '--without-site' => 0])->assertExitCode(0);

        $manifestFile = collect(Storage::disk('local')->allFiles('runs/enrichment'))
            ->first(static fn (string $path): bool => str_ends_with($path, 'manifest.json'));
        $manifest = json_decode(Storage::disk('local')->get($manifestFile), true);

        $this->assertSame(1, $manifest['counts']['collected']);
        $this->assertSame(1, $manifest['verdicts']['data_limited']);
    }

    public function test_dry_run_lists_firms_without_collecting(): void
    {
        Storage::fake('local');

        $this->repo->shouldReceive('assertReachable')->once();
        $this->repo->shouldReceive('gapFirms')->once()->with(1, 1, false)->andReturn([
            ['code' => '111', 'name' => 'Acmena', 'site' => 'http://acmena.lt', 'address' => null],
        ]);
        $this->collector->shouldNotReceive('collect');
        $this->tracer->shouldNotReceive('startTrace');

        $this->artisan('agent:enrich-firms', ['--with-site' => 1, '--without-site' => 1, '--dry-run' => true])->assertExitCode(0);

        $this->assertSame([], Storage::disk('local')->allFiles('runs/enrichment'));
    }

    public function test_hard_flag_requests_smallest_gap_firms(): void
    {
        Storage::fake('local');

        $this->repo->shouldReceive('assertReachable')->once();
        $this->repo->shouldReceive('gapFirms')->once()->with(2, 2, true)->andReturn([]);
        $this->collector->shouldNotReceive('collect');

        $this->artisan('agent:enrich-firms', ['--with-site' => 2, '--without-site' => 2, '--hard' => true, '--dry-run' => true])->assertExitCode(0);
    }

    public function test_fails_fast_when_registry_db_is_unreachable(): void
    {
        $this->repo->shouldReceive('assertReachable')->once()->andThrow(new \RuntimeException('connection refused'));
        $this->repo->shouldNotReceive('gapFirms');
        $this->collector->shouldNotReceive('collect');

        $this->artisan('agent:enrich-firms')->assertExitCode(1);
    }

    public function test_fails_fast_when_gap_query_throws(): void
    {
        $this->repo->shouldReceive('assertReachable')->once();
        $this->repo->shouldReceive('gapFirms')->once()->andThrow(new \RuntimeException('SQL error'));
        $this->collector->shouldNotReceive('collect');

        $this->artisan('agent:enrich-firms')->assertExitCode(1);
    }

    /**
     * @dataProvider normalizeSiteProvider
     */
    public function test_normalize_site(?string $input, ?string $expected): void
    {
        $command = new EnrichFirms(
            Mockery::mock(CompanyRegistryRepository::class),
            Mockery::mock(CollectorService::class),
            Mockery::mock(LangfuseTracer::class),
        );

        $method = new \ReflectionMethod($command, 'normalizeSite');
        $method->setAccessible(true);

        $result = $method->invoke($command, $input);
        $this->assertSame($expected, $result);
    }

    public static function normalizeSiteProvider(): array
    {
        return [
            'scheme with www' => ['http://www.vmi.lt', 'www.vmi.lt'],
            'scheme with trailing slash' => ['https://urspt.lt/', 'urspt.lt'],
            'bare domain' => ['xyz.lt', 'xyz.lt'],
            'null' => [null, null],
            'empty string' => ['', null],
        ];
    }
}
