<?php

namespace Tests\Unit;

use App\Jobs\IngestLangfuseBatch;
use App\Observability\LangfuseClient;
use App\Observability\LangfuseTracer;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LangfuseTracerTest extends TestCase
{
    private function tracer(bool $enabled = true): LangfuseTracer
    {
        Queue::fake();

        config()->set('services.langfuse.public_key', 'pk');
        config()->set('services.langfuse.secret_key', 'sk');
        config()->set('services.langfuse.base_url', 'https://cloud.langfuse.com');
        config()->set('services.langfuse.enabled', $enabled);

        return new LangfuseTracer(new LangfuseClient());
    }

    private function flushedBatch(): array
    {
        $batch = [];
        Queue::assertPushed(IngestLangfuseBatch::class, function ($job) use (&$batch) {
            $batch = $job->events();

            return true;
        });

        return $batch;
    }

    public function test_builds_tree_with_parent_linkage(): void
    {
        $t = $this->tracer();
        $t->startTrace('sess-1', ['company' => 'Acmena']);
        $span = $t->span('resolve-domain', ['x' => 1]);
        $gen = $t->generation('agentic', 'claude-haiku-4-5', ['messages' => []]);
        $gen->end(['text' => 'ok'], ['input' => 10, 'output' => 5, 'total' => 15]);
        $span->end(['done' => true]);
        $t->setTraceOutput(['domain' => 'acmena.lt']);
        $t->flush();

        $batch = $this->flushedBatch();
        $byType = [];
        foreach ($batch as $e) { $byType[$e['type']][] = $e; }

        $this->assertNotEmpty($byType['trace-create']);
        $traceId = $byType['trace-create'][0]['body']['id'];
        $this->assertSame('sess-1', $byType['trace-create'][0]['body']['sessionId']);

        $spanId = $byType['span-create'][0]['body']['id'];
        $this->assertSame($traceId, $byType['span-create'][0]['body']['traceId']);
        // generation parented to the span
        $this->assertSame($spanId, $byType['generation-create'][0]['body']['parentObservationId']);
        $this->assertSame('claude-haiku-4-5', $byType['generation-create'][0]['body']['model']);
        // generation-update carries usage
        $this->assertSame(15, $byType['generation-update'][0]['body']['usage']['total']);
        // span-update present
        $this->assertNotEmpty($byType['span-update']);
    }

    public function test_flush_dispatches_on_sync_connection_by_default(): void
    {
        $t = $this->tracer();
        $t->startTrace('sess-conn', []);
        $t->span('resolve-domain')->end();
        $t->flush();

        // The app's QUEUE_CONNECTION is 'database' with no worker; the ingestion
        // job MUST default to the 'sync' connection so it runs inline and events
        // are never left unprocessed. Locks in the regression fix.
        Queue::assertPushed(IngestLangfuseBatch::class, function ($job) {
            return $job->connection === 'sync';
        });
    }

    public function test_disabled_tracer_emits_nothing(): void
    {
        $t = $this->tracer(enabled: false);
        $t->startTrace('sess', []);
        $t->span('x')->end();
        $t->flush();
        Queue::assertNothingPushed();
    }

    public function test_double_end_is_idempotent(): void
    {
        $t = $this->tracer();
        $t->startTrace('s', []);
        $span = $t->span('a');
        $span->end();
        $span->end(); // must not emit a second span-update or throw
        $t->flush();
        $updates = array_filter($this->flushedBatch(), fn ($e) => $e['type'] === 'span-update');
        $this->assertCount(1, $updates);
    }

    public function test_nested_spans_and_sibling_after_pop_parent_correctly(): void
    {
        $t = $this->tracer();
        $t->startTrace('sess-nested', []);

        $outer = $t->span('resolve-domain');
        $inner = $t->span('tool:web_search');           // parent = outer
        $gen = $t->generation('agentic', 'm', []);        // parent = inner
        $gen->end();
        $inner->end();                                    // pops inner; stack back to [outer]
        $sibling = $t->span('tool:confirm_domain');       // parent must be outer, NOT inner
        $sibling->end();
        $outer->end();
        $t->flush();

        $create = [];
        foreach ($this->flushedBatch() as $e) {
            if (str_ends_with($e['type'], '-create')) {
                $create[$e['body']['name'] ?? $e['type']] = $e['body'];
            }
        }

        $outerId = $create['resolve-domain']['id'];
        $innerId = $create['tool:web_search']['id'];

        $this->assertNull($create['resolve-domain']['parentObservationId'], 'outer span is root-level');
        $this->assertSame($outerId, $create['tool:web_search']['parentObservationId'], 'inner span parents to outer');
        $this->assertSame($innerId, $create['agentic']['parentObservationId'], 'generation parents to inner span');
        $this->assertSame($outerId, $create['tool:confirm_domain']['parentObservationId'], 'sibling after inner pop parents to outer, not the popped inner');
    }
}
