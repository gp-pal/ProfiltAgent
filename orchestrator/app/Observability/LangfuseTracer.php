<?php

namespace App\Observability;

use App\Jobs\IngestLangfuseBatch;
use Illuminate\Support\Str;

/**
 * Request-scoped collector of Langfuse events. Builds a trace tree (spans +
 * generations) using a parent stack, then flushes one batch via LangfuseClient.
 * No-ops entirely when the client is disabled.
 */
class LangfuseTracer
{
    private ?string $traceId = null;
    private ?string $sessionId = null;
    private ?string $traceName = null;
    /** @var array<int, array<string, mixed>> */
    private array $events = [];
    /** @var array<int, string> open span observation ids (stack) */
    private array $stack = [];

    public function __construct(private LangfuseClient $client) {}

    public function startTrace(string $sessionId, array $input, array $metadata = []): void
    {
        if (!$this->client->enabled()) {
            return;
        }
        $this->traceId = (string) Str::uuid();
        $this->sessionId = $sessionId;
        $this->traceName = 'company-run';
        $this->stack = [];
        $this->events = [];
        $this->events[] = $this->event('trace-create', [
            'id' => $this->traceId,
            'name' => $this->traceName,
            'sessionId' => $sessionId,
            'input' => $input,
            'metadata' => $metadata,
            'timestamp' => $this->ts(),
        ]);
    }

    public function span(string $name, array $input = []): TraceHandle
    {
        if ($this->traceId === null) {
            return new NullTraceHandle();
        }
        $id = (string) Str::uuid();
        $this->events[] = $this->event('span-create', [
            'id' => $id,
            'traceId' => $this->traceId,
            'parentObservationId' => end($this->stack) ?: null,
            'name' => $name,
            'startTime' => $this->ts(),
            'input' => $input,
        ]);
        $this->stack[] = $id;
        return new TraceHandle($this, $id, ObservationType::Span);
    }

    public function generation(string $name, ?string $model, mixed $input): TraceHandle
    {
        if ($this->traceId === null) {
            return new NullTraceHandle();
        }
        $id = (string) Str::uuid();
        $this->events[] = $this->event('generation-create', [
            'id' => $id,
            'traceId' => $this->traceId,
            'parentObservationId' => end($this->stack) ?: null,
            'name' => $name,
            'startTime' => $this->ts(),
            'model' => $model,
            'input' => $input,
        ]);
        return new TraceHandle($this, $id, ObservationType::Generation);
    }

    /**
     * Called by TraceHandle::end(). Emits the *-update event and pops the stack for spans.
     *
     * @param ObservationType $type Whether this observation is a span or a generation.
     */
    public function endObservation(string $id, ObservationType $type, mixed $output, ?array $usage, string $level, ?string $statusMessage): void
    {
        if ($this->traceId === null) {
            return;
        }
        $body = [
            'id' => $id,
            'traceId' => $this->traceId,
            'endTime' => $this->ts(),
            'output' => $output,
            'level' => $level,
        ];
        if ($statusMessage !== null) {
            $body['statusMessage'] = $statusMessage;
        }
        if ($type === ObservationType::Generation && $usage !== null) {
            $body['usage'] = [
                'input' => $usage['input'] ?? null,
                'output' => $usage['output'] ?? null,
                'total' => $usage['total'] ?? null,
                'unit' => 'TOKENS',
            ];
        }
        $this->events[] = $this->event($type->value . '-update', $body);

        if ($type === ObservationType::Span) {
            $pos = array_search($id, $this->stack, true);
            if ($pos !== false) {
                array_splice($this->stack, $pos, 1);
            }
        }
    }

    public function setTraceOutput(mixed $output): void
    {
        if ($this->traceId === null) {
            return;
        }
        // Langfuse upserts a trace by id; re-emit trace-create with output.
        $this->events[] = $this->event('trace-create', [
            'id' => $this->traceId,
            'name' => $this->traceName,
            'sessionId' => $this->sessionId,
            'output' => $output,
            'timestamp' => $this->ts(),
        ]);
    }

    public function flush(): void
    {
        if ($this->traceId === null || $this->events === []) {
            $this->events = [];
            return;
        }
        // Auto-close any spans left open (defensive).
        foreach (array_reverse($this->stack) as $openId) {
            $this->events[] = $this->event('span-update', [
                'id' => $openId,
                'traceId' => $this->traceId,
                'endTime' => $this->ts(),
                'level' => 'WARNING',
                'statusMessage' => 'span not explicitly ended',
            ]);
        }
        $this->stack = [];
        $events = $this->events;
        $this->events = [];
        // Default connection is 'sync' so ingestion runs inline (after the
        // response, via the terminable FlushLangfuse middleware) without a queue
        // worker — the app's QUEUE_CONNECTION is 'database' with no worker, so a
        // plain dispatch() would leave events unprocessed. Set
        // LANGFUSE_QUEUE_CONNECTION=database (+ run a worker) to offload if QPS grows.
        IngestLangfuseBatch::dispatch($events)
            ->onConnection((string) config('services.langfuse.queue_connection', 'sync'));
    }

    private function ts(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function event(string $type, array $body): array
    {
        return ['id' => (string) Str::uuid(), 'type' => $type, 'timestamp' => $this->ts(), 'body' => $body];
    }
}
