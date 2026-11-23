<?php

namespace Tests\Unit;

use App\IO\PrismLlmClient;
use App\Jobs\IngestLangfuseBatch;
use App\Observability\LangfuseClient;
use App\Observability\LangfuseTracer;
use Illuminate\Support\Facades\Queue;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class PrismLlmClientLangfuseTest extends TestCase
{
    private function tracer(): LangfuseTracer
    {
        Queue::fake();

        config()->set('services.langfuse.public_key', 'pk');
        config()->set('services.langfuse.secret_key', 'sk');
        config()->set('services.langfuse.base_url', 'https://cloud.langfuse.com');
        config()->set('services.langfuse.enabled', true);

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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function eventsOfType(array $batch, string $type): array
    {
        return array_values(array_filter($batch, fn ($e) => $e['type'] === $type));
    }

    public function test_complete_emits_generation_with_model_and_usage(): void
    {
        config()->set('llm.provider', 'anthropic');
        config()->set('llm.model', 'claude-haiku-4-5');

        $tracer = $this->tracer();
        $tracer->startTrace('sess-1', []);

        Prism::fake([
            TextResponseFake::make()->withText('hello there')->withUsage(new Usage(10, 5)),
        ]);

        $client = new PrismLlmClient($tracer);
        $result = $client->complete(['messages' => [['role' => 'user', 'content' => 'hi']]]);

        $tracer->flush();

        // Normalized return shape is unchanged by instrumentation.
        $this->assertSame('hello there', $result['choices'][0]['message']['content']);
        $this->assertSame('claude-haiku-4-5', $result['model']);

        $batch = $this->flushedBatch();
        $creates = $this->eventsOfType($batch, 'generation-create');
        $updates = $this->eventsOfType($batch, 'generation-update');

        $this->assertNotEmpty($creates, 'expected a generation-create event');
        $this->assertSame('text', $creates[0]['body']['name']);
        $this->assertSame('claude-haiku-4-5', $creates[0]['body']['model']);
        $this->assertSame(
            ['system' => '', 'messages' => [['role' => 'user', 'content' => 'hi']]],
            $creates[0]['body']['input'],
            'text generation input uses the normalized {system, messages} shape'
        );

        $this->assertNotEmpty($updates, 'expected a generation-update event');
        $this->assertSame('hello there', $updates[0]['body']['output']);
        $this->assertSame(10, $updates[0]['body']['usage']['input']);
        $this->assertSame(5, $updates[0]['body']['usage']['output']);
        $this->assertSame(15, $updates[0]['body']['usage']['total']);
    }

    public function test_structured_emits_generation_with_model_and_usage(): void
    {
        config()->set('llm.provider', 'anthropic');
        config()->set('llm.model', 'claude-haiku-4-5');

        $tracer = $this->tracer();
        $tracer->startTrace('sess-2', []);

        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['domain' => 'acmena.lt'])
                ->withUsage(new Usage(20, 8)),
        ]);

        $client = new PrismLlmClient($tracer);
        $result = $client->structured([['role' => 'user', 'content' => 'hi']], ['type' => 'object']);

        $tracer->flush();

        $this->assertSame(['domain' => 'acmena.lt'], $result);

        $batch = $this->flushedBatch();
        $creates = $this->eventsOfType($batch, 'generation-create');
        $updates = $this->eventsOfType($batch, 'generation-update');

        $this->assertNotEmpty($creates, 'expected a generation-create event');
        $this->assertSame('structured', $creates[0]['body']['name']);
        $this->assertSame('claude-haiku-4-5', $creates[0]['body']['model']);
        $this->assertSame(
            ['system' => '', 'messages' => [['role' => 'user', 'content' => 'hi']]],
            $creates[0]['body']['input'],
            'structured generation input uses the same normalized {system, messages} shape as text/agentic'
        );

        $this->assertNotEmpty($updates, 'expected a generation-update event');
        $this->assertSame(['domain' => 'acmena.lt'], $updates[0]['body']['output']);
        $this->assertSame(28, $updates[0]['body']['usage']['total']);
    }

    public function test_agentic_emits_generation_with_model_and_usage(): void
    {
        config()->set('llm.provider', 'anthropic');
        config()->set('llm.model', 'claude-haiku-4-5');

        $tracer = $this->tracer();
        $tracer->startTrace('sess-3', []);

        Prism::fake([
            TextResponseFake::make()->withText('done')->withUsage(new Usage(12, 3)),
        ]);

        $client = new PrismLlmClient($tracer);
        $result = $client->agentic('system', 'user', [], 8);

        $tracer->flush();

        $this->assertSame('done', $result['text']);

        $batch = $this->flushedBatch();
        $creates = $this->eventsOfType($batch, 'generation-create');
        $updates = $this->eventsOfType($batch, 'generation-update');

        $this->assertNotEmpty($creates, 'expected a generation-create event');
        $this->assertSame('agentic', $creates[0]['body']['name']);
        $this->assertSame('claude-haiku-4-5', $creates[0]['body']['model']);
        $this->assertSame(
            ['system' => 'system', 'messages' => [['role' => 'user', 'content' => 'user']]],
            $creates[0]['body']['input'],
            'agentic generation input uses the normalized {system, messages} shape'
        );

        $this->assertNotEmpty($updates, 'expected a generation-update event');
        $this->assertSame('done', $updates[0]['body']['output']);
        $this->assertSame(15, $updates[0]['body']['usage']['total']);
    }

    public function test_run_error_ends_generation_with_error_level_and_rethrows(): void
    {
        config()->set('llm.provider', 'anthropic');
        config()->set('llm.model', 'claude-haiku-4-5');

        $tracer = $this->tracer();
        $tracer->startTrace('sess-4', []);

        // Only one response is queued. PrismFake's own sequence tracking
        // throws Exception('Could not find a response for the request') once
        // it is exhausted (see vendor/prism-php/prism/src/Testing/PrismFake.php
        // nextTextResponse()) — a real vendor-thrown error, not an invented
        // fake-exception API, giving us a genuine Throwable to verify the
        // error path with.
        Prism::fake([
            TextResponseFake::make()->withText('first'),
        ]);

        $client = new PrismLlmClient($tracer);
        $client->complete(['messages' => [['role' => 'user', 'content' => 'hi']]]);

        try {
            $client->complete(['messages' => [['role' => 'user', 'content' => 'hi']]]);
            $this->fail('expected exception to propagate');
        } catch (\Exception $e) {
            $this->assertSame('Could not find a response for the request', $e->getMessage());
        }

        $tracer->flush();

        $batch = $this->flushedBatch();
        $updates = $this->eventsOfType($batch, 'generation-update');

        $this->assertCount(2, $updates, 'expected one generation-update per call, including the errored one');
        $this->assertSame('ERROR', $updates[1]['body']['level']);
        $this->assertSame('Could not find a response for the request', $updates[1]['body']['statusMessage']);
    }

    public function test_default_tracer_is_disabled_noop_in_test_env(): void
    {
        config()->set('llm.provider', 'anthropic');
        config()->set('llm.model', 'claude-haiku-4-5');

        Prism::fake([
            TextResponseFake::make()->withText('ok')->withUsage(new Usage(1, 1)),
        ]);

        // No tracer injected -> resolves the container singleton, which is
        // disabled by default in the test environment (LANGFUSE_ENABLED=false).
        $client = new PrismLlmClient();
        $result = $client->complete(['messages' => [['role' => 'user', 'content' => 'hi']]]);

        $this->assertSame('ok', $result['choices'][0]['message']['content']);
    }
}
