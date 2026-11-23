<?php

namespace Tests\Unit;

use App\Jobs\CollectCompanyJob;
use App\Observability\LangfuseTracer;
use App\Services\CollectorService;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CollectCompanyJobTest extends TestCase
{
    public function test_handle_collects_once_with_session_and_country(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(static fn (array $t): bool => $t['name'] === 'Acmena'
                && $t['session'] === 'job-111'
                && $t['country'] === 'LT'
                && $t['domain'] === 'acmena.lt'))
            ->andReturn([
                'run_hash' => 'run-abc',
                'profile' => [],
                'field_sources' => [],
                'coverage' => ['overall' => 0.5, 'fields' => [], 'missing' => []],
                'sources_used' => ['registry_db'],
                'verdict' => 'partial',
            ]);

        $tracer = Mockery::mock(LangfuseTracer::class);
        $tracer->shouldReceive('startTrace')
            ->once()
            ->with('job-111', ['company' => 'Acmena'], ['source' => 'CollectCompanyJob']);
        $tracer->shouldReceive('flush')->once();

        $job = new CollectCompanyJob(['name' => 'Acmena', 'code' => '111', 'domain' => 'acmena.lt']);
        $job->handle($collector, $tracer);
    }

    public function test_handle_flushes_even_when_collect_fails(): void
    {
        $collector = Mockery::mock(CollectorService::class);
        $collector->shouldReceive('collect')->once()->andThrow(new \RuntimeException('boom'));

        $tracer = Mockery::mock(LangfuseTracer::class);
        $tracer->shouldReceive('startTrace')->once();
        $tracer->shouldReceive('flush')->once();

        $job = new CollectCompanyJob(['name' => 'Acmena', 'code' => '111']);

        $this->expectException(\RuntimeException::class);
        $job->handle($collector, $tracer);
    }

    public function test_dispatch_pushes_the_job_onto_the_queue(): void
    {
        Queue::fake();

        CollectCompanyJob::dispatch(['name' => 'Acmena', 'code' => '111', 'domain' => 'acmena.lt']);

        Queue::assertPushed(CollectCompanyJob::class);
    }
}
