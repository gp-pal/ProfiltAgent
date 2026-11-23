<?php

namespace App\Observability;

class NullTraceHandle extends TraceHandle
{
    /**
     * Intentionally overrides {@see TraceHandle}'s required-argument constructor
     * with an empty one: this is the null-object implementation returned by
     * {@see LangfuseTracer::span()} and {@see LangfuseTracer::generation()} when
     * tracing is disabled or no trace is active, so it must be trivially
     * constructable via `new NullTraceHandle()` without a tracer, observation id,
     * or {@see ObservationType}. Its {@see self::end()} is a deliberate no-op.
     */
    public function __construct() {}

    public function end(mixed $output = null, ?array $usage = null, string $level = 'DEFAULT', ?string $statusMessage = null): void
    {
        // no-op
    }
}
