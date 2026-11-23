<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunRequest;
use App\Observability\LangfuseTracer;
use App\Services\CollectorService;
use App\Services\ReActLoopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

readonly class RunController
{
    public function __construct(
        private ReActLoopService $reactLoopService,
        private CollectorService $collectorService,
        private LangfuseTracer $tracer
    ) {}

    public function run(RunRequest $request): JsonResponse
    {
        $requestId = Uuid::uuid4()->toString();
        Log::info('RunController: Starting new run request', [
            'request_id' => $requestId,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        try {
            // Validation ran in RunRequest; here we only normalise the validated input.
            $input = $this->normalizeInput($request->validated());

            $company = $input['company'];
            $domain = $input['domain'] ?? null;
            $code = ($input['code'] ?? null) ?: null;
            $people = $this->normalizePeople($input['people'] ?? []);
            $fields = array_values(array_filter(array_map('strval', $input['fields'] ?? [])));
            $knownFields = $this->normalizeKnownFields($input['known_fields'] ?? []);
            $session = $input['session'] ?? Uuid::uuid4()->toString();
            $thinking = $input['thinking'] ?? false;
            $dryRun = $input['dry_run'] ?? false;
            // Universal collector is the default; opt out with collect:false to get
            // the raw Step-1 pipeline response instead.
            $collect = $input['collect'] ?? true;
            // Re-run the ladder for verification only: see CollectorService::collect().
            $verifyFields = (bool) ($input['verify_fields'] ?? false);

            Log::info('RunController: Input validated successfully', [
                'request_id' => $requestId,
                'company' => $company,
                'domain' => $domain,
                'session' => $session,
                'thinking' => $thinking,
                'dry_run' => $dryRun
            ]);

            $this->tracer->startTrace($session, [
                'company' => $company,
                'domain' => $domain,
            ], ['request_id' => $requestId]);

            // Default path: universal collector — gather from the full legal-first
            // source ladder (registry DB, official site, open web). collect:false
            // falls through to the raw Step-1 pipeline below.
            if ($collect) {
                $collectResult = $this->collectorService->collect([
                    'name' => $company,
                    'code' => $code,
                    'people' => $people,
                    'fields' => $fields,
                    'known_fields' => $knownFields,
                    'domain' => $domain,
                    'session' => $session,
                    'country' => 'LT',
                    'verify_fields' => $verifyFields,
                ]);

                $this->tracer->setTraceOutput([
                    'verdict' => $collectResult['verdict'] ?? null,
                    'coverage' => $collectResult['coverage']['overall'] ?? null,
                    'sources_used' => $collectResult['sources_used'] ?? [],
                    'run_hash' => $collectResult['run_hash'] ?? null,
                ]);

                Log::info('RunController: collector run completed', [
                    'request_id' => $requestId,
                    'session' => $session,
                    'verdict' => $collectResult['verdict'] ?? null,
                ]);

                return response()->json($collectResult, 200);
            }

            // 2. Delegate to ReActLoopService for Step-1 execution
            Log::info('RunController: Starting ReAct loop service execution', [
                'request_id' => $requestId,
                'session' => $session
            ]);

            $result = $this->reactLoopService->runStep1Bootstrap($company, $domain, $session, [
                'thinking' => $thinking,
                'dryRun' => $dryRun
            ]);

            Log::info('RunController: ReAct loop service completed', [
                'request_id' => $requestId,
                'session' => $session,
                'success' => $result['ok'] ?? false,
                'error' => $result['error'] ?? null
            ]);

            $this->tracer->setTraceOutput([
                'ok' => $result['ok'] ?? null,
                'done' => $result['done'] ?? null,
                'domain' => $result['domain'] ?? null,
                'resolved_via' => $result['resolved_via'] ?? null,
                'error' => $result['error'] ?? null,
            ]);

            // 3. Map result to HTTP response
            $statusCode = $this->mapErrorToStatusCode($result['error'] ?? null);

            Log::info('RunController: Request completed', [
                'request_id' => $requestId,
                'status_code' => $statusCode,
                'execution_time' => microtime(true) - (defined('LARAVEL_START') ? LARAVEL_START : microtime(true))
            ]);

            return response()->json($result, $statusCode);

        } catch (\Exception $e) {
            Log::error('RunController: Exception occurred', [
                'request_id' => $requestId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'internal',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeInput(array $validated): array
    {
        // Normalize domain
        if (!empty($validated['domain'])) {
            $originalDomain = $validated['domain'];
            $domain = strtolower(trim($validated['domain']));
            // Strip scheme if present
            $domain = preg_replace('/^https?:\/\//', '', $domain);
            // Strip trailing slash
            $domain = rtrim($domain, '/');

            Log::debug('RunController: Normalizing domain', [
                'original' => $originalDomain,
                'normalized' => $domain
            ]);

            // Validate domain format
            if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
                Log::warning('RunController: Invalid domain format', [
                    'domain' => $domain,
                    'original' => $originalDomain
                ]);
                throw new \InvalidArgumentException('Invalid domain format');
            }
            $validated['domain'] = $domain;
        }

        Log::debug('RunController: Input validation completed successfully');
        return $validated;
    }

    /**
     * Normalise the caller's people list to one predictable shape: trimmed name,
     * `role` always present (null when absent), entries without a name dropped —
     * so downstream code never has to re-check any of that.
     *
     * @param  array<int, array{name?: ?string, role?: ?string}>  $people
     * @return array<int, array{name: string, role: ?string}>
     */
    private function normalizePeople(array $people): array
    {
        $out = [];

        foreach ($people as $person) {
            $name = trim((string) ($person['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $role = trim((string) ($person['role'] ?? ''));
            $out[] = ['name' => $name, 'role' => $role === '' ? null : $role];
        }

        return $out;
    }

    /** Longest a single known_fields value (or array element) may be. */
    private const KNOWN_FIELD_VALUE_MAX_CHARS = 2000;

    /** Most elements a known_fields array value may keep. */
    private const KNOWN_FIELD_ARRAY_MAX_ITEMS = 50;

    /**
     * Normalise caller-supplied field values: keep only well-formed field keys with a
     * usable value, coerce arrays to lists of non-empty strings, and drop the rest.
     * A malformed entry is dropped rather than 422'd — one bad value must not cost the
     * caller the whole enrichment, the same rule `fields[]` follows. The same principle
     * bounds size here: an over-long scalar, or an over-long/over-count array element,
     * is DROPPED rather than truncated — a supplied value is authoritative and
     * persisted/returned exactly as sent, so silently shortening it would misrepresent
     * what the caller told us it holds.
     *
     * @param  array<string, mixed>  $known
     * @return array<string, string|array<int, string>>
     */
    private function normalizeKnownFields(array $known): array
    {
        $out = [];

        foreach ($known as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1 || mb_strlen($key) > 64) {
                continue;
            }

            if (is_array($value)) {
                $items = array_values(array_filter(array_map(
                    static fn ($v): string => is_scalar($v) ? trim((string) $v) : '',
                    $value,
                ), static fn (string $v): bool => $v !== ''));

                $withinLength = array_values(array_filter(
                    $items,
                    static fn (string $v): bool => mb_strlen($v) <= self::KNOWN_FIELD_VALUE_MAX_CHARS,
                ));
                if (count($withinLength) < count($items)) {
                    Log::warning('Dropping over-long known_fields array element(s)', [
                        'key' => $key,
                        'dropped' => count($items) - count($withinLength),
                    ]);
                }
                $items = $withinLength;

                if (count($items) > self::KNOWN_FIELD_ARRAY_MAX_ITEMS) {
                    Log::warning('Dropping excess known_fields array element(s)', [
                        'key' => $key,
                        'from' => count($items),
                        'to' => self::KNOWN_FIELD_ARRAY_MAX_ITEMS,
                    ]);
                    $items = array_slice($items, 0, self::KNOWN_FIELD_ARRAY_MAX_ITEMS);
                }

                if ($items !== []) {
                    $out[$key] = $items;
                }

                continue;
            }

            if (is_scalar($value) && trim((string) $value) !== '') {
                $trimmed = trim((string) $value);

                if (mb_strlen($trimmed) > self::KNOWN_FIELD_VALUE_MAX_CHARS) {
                    Log::warning('Dropping over-long known_fields value', ['key' => $key, 'length' => mb_strlen($trimmed)]);

                    continue;
                }

                $out[$key] = $trimmed;
            }
        }

        return $out;
    }

    private function mapErrorToStatusCode(?string $error): int
    {
        if (!$error) {
            return 200;
        }

        $statusCode = match ($error) {
            'bad_request' => 400,
            'no_site_found' => 404,
            'validation_failed' => 422,
            'runner_failed', 'challenge_detected' => 424,
            'internal' => 500,
            default => 500
        };

        Log::debug('RunController: Mapped error to status code', [
            'error' => $error,
            'status_code' => $statusCode
        ]);

        return $statusCode;
    }
}