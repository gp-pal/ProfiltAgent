<?php

namespace App\Jobs;

use App\Observability\LangfuseTracer;
use App\Services\CollectorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the universal collector (registry -> official site -> open web -> contacts)
 * for one target off the request's critical path. The collector is expensive
 * (~30-90s), so the job never blind-retries. Like the CLI EnrichFirms command it
 * opens and flushes one Langfuse trace itself, since the queue path bypasses
 * RunController + the terminable FlushLangfuse middleware and would otherwise emit
 * no spans.
 */
class CollectCompanyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * @param array{name?: ?string, code?: ?string, domain?: ?string, country?: ?string, session?: ?string} $target
     */
    public function __construct(public array $target)
    {
    }

    public function handle(CollectorService $collector, LangfuseTracer $tracer): void
    {
        $name = (string) ($this->target['name'] ?? '');
        $code = $this->target['code'] ?? null;
        $session = $this->target['session'] ?? 'job-' . ($code ?: md5($name));

        $target = $this->target;
        $target['session'] = $session;
        $target['country'] = $target['country'] ?? 'LT';

        $tracer->startTrace($session, ['company' => $name], ['source' => 'CollectCompanyJob']);

        try {
            $collector->collect($target);
        } finally {
            $tracer->flush();
        }
    }
}
