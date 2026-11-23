<?php

namespace App\Http\Middleware;

use App\Observability\LangfuseTracer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FlushLangfuse
{
    public function __construct(private LangfuseTracer $tracer) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->tracer->flush();
        } catch (\Throwable $e) {
            // never let observability break the request lifecycle
        }
    }
}
