<?php

namespace App\Observability;

class TraceHandle
{
    private bool $ended = false;

    public function __construct(
        private LangfuseTracer $tracer,
        private string $observationId,
        private ObservationType $type,
    ) {}

    public function end(mixed $output = null, ?array $usage = null, string $level = 'DEFAULT', ?string $statusMessage = null): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->tracer->endObservation($this->observationId, $this->type, $output, $usage, $level, $statusMessage);
    }
}
