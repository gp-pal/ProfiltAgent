<?php

namespace App\IO;

use App\IO\Contracts\LlmClient;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Unified, multi-provider LLM client backed by Prism.
 *
 * Routes completions to Ollama, OpenAI (ChatGPT) or Anthropic (Claude) based on
 * config('llm.provider'). Prism handles provider-specific wire formats (Anthropic's
 * separate `system` field, `/v1/messages` shape, Ollama base URL, etc.); this class
 * adapts the project's OpenAI-style message array in, and the OpenAI-style
 * response shape out, so callers stay provider-independent.
 */
class PrismLlmClient implements LlmClient
{
    public function __construct(private ?\App\Observability\LangfuseTracer $tracer = null)
    {
        $this->tracer = $tracer ?? app(\App\Observability\LangfuseTracer::class);
    }

    public function complete(array $params): array
    {
        $model = (string) ($params['model'] ?? config('llm.model'));

        return $this->run($model, $params);
    }

    public function withFallback(array $params): array
    {
        try {
            return $this->complete($params);
        } catch (\Throwable $e) {
            Log::warning('Primary model failed, trying fallback: ' . $e->getMessage());

            $params['model'] = (string) config('llm.fallback_model');

            return $this->complete($params);
        }
    }

    public function structured(array $messages, array $schema): array
    {
        $model = (string) config('llm.model');
        [$systemPrompt, $promptMessages] = $this->splitMessages($messages);

        $pending = Prism::structured()
            ->using($this->resolveProvider(), $model)
            ->withSchema(new RawSchema('structured_output', $schema))
            ->withMessages($promptMessages);

        if ($systemPrompt !== '') {
            $pending->withSystemPrompt($systemPrompt);
        }

        $maxTokens = (int) config('llm.max_tokens');
        if ($maxTokens > 0) {
            $pending->withMaxTokens($maxTokens);
        }

        $gen = $this->tracer->generation('structured', $model, $this->promptPayload($messages));

        try {
            $response = $pending->asStructured();
            $output = $response->structured ?? [];
            $gen->end($output, $this->usageFrom($response));

            return $output;
        } catch (\Throwable $e) {
            $gen->end(null, null, 'ERROR', $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array{choices: array<int, array{message: array{content: string}}>, model: string}
     */
    private function run(string $model, array $params): array
    {
        [$systemPrompt, $messages] = $this->splitMessages($params['messages'] ?? []);

        $pending = Prism::text()
            ->using($this->resolveProvider(), $model)
            ->withMessages($messages);

        if ($systemPrompt !== '') {
            $pending->withSystemPrompt($systemPrompt);
        }

        $maxTokens = (int) ($params['max_tokens'] ?? config('llm.max_tokens'));
        if ($maxTokens > 0) {
            $pending->withMaxTokens($maxTokens);
        }

        $temperature = $params['temperature'] ?? config('llm.temperature');
        if ($temperature !== null && $this->modelAcceptsTemperature($model)) {
            $pending->usingTemperature((float) $temperature);
        }

        $gen = $this->tracer->generation('text', $model, $this->promptPayload($params['messages'] ?? []));

        try {
            $response = $pending->asText();
            $gen->end($response->text, $this->usageFrom($response));

            return [
                'choices' => [['message' => ['content' => $response->text]]],
                'model' => $model,
            ];
        } catch (\Throwable $e) {
            $gen->end(null, null, 'ERROR', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Build a consistent generation "input" payload across all LLM call types
     * (text / structured / agentic): an explicit system string plus the
     * conversation as plain role/content pairs. Keeps Langfuse generation
     * inputs uniform and JSON-friendly instead of leaking Prism value objects
     * or a differently-shaped array per call type.
     *
     * @param array<int, array{role?: string, content?: string}> $rawMessages
     * @return array{system: string, messages: array<int, array{role: string, content: string}>}
     */
    private function promptPayload(array $rawMessages): array
    {
        $systemParts = [];
        $messages = [];

        foreach ($rawMessages as $message) {
            $role = $message['role'] ?? 'user';
            $content = (string) ($message['content'] ?? '');

            if ($role === 'system') {
                $systemParts[] = $content;
            } else {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }

        return ['system' => trim(implode("\n\n", $systemParts)), 'messages' => $messages];
    }

    /**
     * @return array{input: int|null, output: int|null, total: int}|null
     */
    private function usageFrom(object $response): ?array
    {
        $u = $response->usage ?? null;
        if ($u === null) {
            return null;
        }

        return [
            'input' => $u->promptTokens ?? null,
            'output' => $u->completionTokens ?? null,
            'total' => (int) (($u->promptTokens ?? 0) + ($u->completionTokens ?? 0)),
        ];
    }

    private function resolveProvider(): Provider
    {
        $provider = (string) config('llm.provider', 'ollama');

        return Provider::tryFrom($provider) ?? Provider::Ollama;
    }

    /**
     * Split an OpenAI-style message array into a Prism system prompt + message list.
     *
     * @param array<int, array{role?: string, content?: string}> $raw
     * @return array{0: string, 1: array<int, UserMessage|AssistantMessage>}
     */
    private function splitMessages(array $raw): array
    {
        $systemParts = [];
        $messages = [];

        foreach ($raw as $message) {
            $role = $message['role'] ?? 'user';
            $content = (string) ($message['content'] ?? '');

            if ($role === 'system') {
                $systemParts[] = $content;
            } elseif ($role === 'assistant') {
                $messages[] = new AssistantMessage($content);
            } else {
                $messages[] = new UserMessage($content);
            }
        }

        if ($messages === []) {
            $messages[] = new UserMessage('');
        }

        return [trim(implode("\n\n", $systemParts)), $messages];
    }

    /**
     * The most capable Anthropic models (Opus 4.7+/Fable) reject the `temperature`
     * parameter. Omit it for those; pass it for everything else.
     */
    private function modelAcceptsTemperature(string $model): bool
    {
        foreach (['opus-4-7', 'opus-4-8', 'fable', 'mythos'] as $needle) {
            if (str_contains($model, $needle)) {
                return false;
            }
        }

        return true;
    }

    public function agentic(string $system, string $user, array $tools, int $maxSteps = 8): array
    {
        $model = (string) config('llm.model');

        $pending = Prism::text()
            ->using($this->resolveProvider(), $model)
            ->withMessages([new UserMessage($user)])
            ->withTools($tools)
            ->withMaxSteps($maxSteps);

        if (trim($system) !== '') {
            $pending->withSystemPrompt($system);
        }

        $maxTokens = (int) config('llm.max_tokens');
        if ($maxTokens > 0) {
            $pending->withMaxTokens($maxTokens);
        }

        $gen = $this->tracer->generation('agentic', $model, [
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
        ]);

        try {
            $response = $pending->asText();
            $gen->end($response->text, $this->usageFrom($response));
        } catch (\Throwable $e) {
            $gen->end(null, null, 'ERROR', $e->getMessage());
            throw $e;
        }

        $steps = [];
        foreach ($response->steps as $step) {
            $toolCalls = [];
            foreach ($step->toolCalls as $call) {
                $toolCalls[] = ['name' => $call->name, 'args' => $call->arguments()];
            }
            $toolResults = [];
            foreach ($step->toolResults as $tr) {
                $toolResults[] = ['name' => $tr->toolName, 'result' => $this->stringifyToolResult($tr->result)];
            }
            $steps[] = [
                'text' => (string) $step->text,
                'tool_calls' => $toolCalls,
                'tool_results' => $toolResults,
            ];
        }

        return ['text' => (string) $response->text, 'steps' => $steps];
    }

    /**
     * Normalize a tool result to a string for the ReAct trace. Scalars cast
     * directly; arrays/objects are JSON-encoded so structured output is not
     * collapsed to the literal "Array".
     */
    private function stringifyToolResult(int|float|string|array|null $result): string
    {
        if (is_array($result)) {
            return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) $result;
    }
}
