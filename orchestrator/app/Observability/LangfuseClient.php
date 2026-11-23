<?php

namespace App\Observability;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Thin REST client for the Langfuse ingestion API. Fire-and-forget: all failures
 * are swallowed so tracing can never break or slow the main request flow.
 */
class LangfuseClient
{
    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(['timeout' => 5, 'connect_timeout' => 3]);
    }

    /**
     * Best-effort warning log that itself never throws — a failing log channel
     * must not break the "ingest never throws" guarantee this component provides.
     */
    private function safeWarn(string $message, string $error): void
    {
        try {
            Log::warning($message, ['error' => $error]);
        } catch (\Throwable $e) {
            // swallow: observability must never break the request flow
        }
    }

    public function enabled(): bool
    {
        return (bool) config('services.langfuse.enabled', true)
            && (string) config('services.langfuse.public_key', '') !== ''
            && (string) config('services.langfuse.secret_key', '') !== ''
            && (string) config('services.langfuse.base_url', '') !== '';
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    public function ingest(array $events): void
    {
        if (!$this->enabled() || $events === []) {
            return;
        }

        $base = rtrim((string) config('services.langfuse.base_url'), '/');
        $auth = base64_encode(config('services.langfuse.public_key') . ':' . config('services.langfuse.secret_key'));

        try {
            $this->client->post("{$base}/api/public/ingestion", [
                'headers' => [
                    'Authorization' => "Basic {$auth}",
                    'Content-Type' => 'application/json',
                ],
                'json' => ['batch' => $events],
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            $this->safeWarn('Langfuse ingestion failed', $e->getMessage());
        }
    }
}
