<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared-secret authentication for the orchestrator's action endpoints.
 *
 * Accepts the secret via the `X-Api-Key` header or a Bearer token and compares
 * it in constant time. When no key is configured, auth is disabled in
 * local/testing only (dev convenience); any other environment fails closed and
 * refuses the request, so a networked deployment is never left open by an unset
 * secret.
 */
class EnsureApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('agent.api_key', '');

        if ($configured === '') {
            if (app()->environment('local', 'testing')) {
                Log::warning('AGENT_API_KEY is not set — endpoint authentication is disabled (local/testing only).');

                return $next($request);
            }

            Log::error('AGENT_API_KEY is not set — refusing requests; configure it for this environment.');

            return response()->json(['error' => 'server_misconfigured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $provided = (string) ($request->header('X-Api-Key') ?? $request->bearerToken() ?? '');

        if ($provided === '' || !hash_equals($configured, $provided)) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
