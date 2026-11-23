<?php

namespace App\Jobs;

use App\Observability\LangfuseClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends a collected Langfuse event batch to the ingestion API off the request's
 * critical path. With the sync queue driver it runs inline (after the response,
 * via the terminable FlushLangfuse middleware); with a real queue + worker it is
 * fully offloaded so high request rates never block on Langfuse I/O.
 */
class IngestLangfuseBatch implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use InteractsWithQueue;

    /**
     * @param array<int, array<string, mixed>> $events
     */
    public function __construct(private array $events)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function events(): array
    {
        return $this->events;
    }

    public function handle(LangfuseClient $client): void
    {
        $client->ingest($this->events);
    }
}
