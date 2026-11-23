<?php

namespace App\IO;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class RunnerClient
{
    private Client $client;
    private string $baseUrl;
    private string $token;

    public function __construct(?string $baseUrl = null, ?Client $client = null)
    {
        $this->baseUrl = $baseUrl ?: env('RUNNER_BASE', 'http://browser_runner:8080');
        $this->token = (string) config('services.browser_runner.token', '');
        $this->client = $client ?: new Client([
            'timeout' => 120,
            'connect_timeout' => 10,
        ]);
    }

    public function run(array $plan): array
    {
        try {
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];

            if ($this->token !== '') {
                $headers['Authorization'] = 'Bearer ' . $this->token;
            }

            $response = $this->client->post($this->baseUrl . '/run', [
                'json' => $plan,
                'headers' => $headers,
            ]);

            $body = $response->getBody()->getContents();
            $result = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'ok' => false,
                    'error' => 'Invalid JSON response from runner',
                    'raw_response' => $body
                ];
            }

            // Ensure consistent response format
            return [
                'ok' => $result['ok'] ?? true,
                'url' => $result['url'] ?? null,
                'data' => $result['data'] ?? [],
                'artifacts' => $result['artifacts'] ?? [],
                'logs' => $result['logs'] ?? [],
                'error' => $result['error'] ?? null
            ];

        } catch (RequestException $e) {
            return [
                'ok' => false,
                'error' => 'Runner request failed: ' . $e->getMessage(),
                'url' => null,
                'data' => [],
                'artifacts' => [],
                'logs' => []
            ];
        } catch (\Exception $e) {
            return [
                'ok' => false,
                'error' => 'Unexpected error: ' . $e->getMessage(),
                'url' => null,
                'data' => [],
                'artifacts' => [],
                'logs' => []
            ];
        }
    }

    /**
     * Turn already-fetched HTML into clean markdown, reusing the runner's own
     * conversion rather than adding a second one on this side.
     *
     * No browser is involved, so this cannot fail on a bot wall — but it also cannot
     * recover an article whose body arrives only via JavaScript. Returns null on any
     * failure: losing the text of one article must never break the run that found it.
     */
    public function markdown(string $html): ?string
    {
        if (trim($html) === '') {
            return null;
        }

        try {
            $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
            if ($this->token !== '') {
                $headers['Authorization'] = 'Bearer ' . $this->token;
            }

            $response = $this->client->post($this->baseUrl . '/markdown', [
                'json' => ['html' => $html],
                'headers' => $headers,
            ]);
            $decoded = json_decode($response->getBody()->getContents(), true);

            if (($decoded['ok'] ?? false) !== true) {
                Log::warning('Runner markdown conversion failed', ['error' => $decoded['error'] ?? 'unknown']);

                return null;
            }

            $markdown = trim((string) ($decoded['markdown'] ?? ''));

            return $markdown === '' ? null : $markdown;
        } catch (\Throwable $e) {
            Log::warning('Runner markdown request failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}