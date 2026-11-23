<?php

namespace App\IO\Contracts;

/**
 * Provider-agnostic LLM client contract.
 *
 * Implementations talk to a concrete provider (Ollama, OpenAI/ChatGPT,
 * Anthropic/Claude, ...) but normalize the response to the OpenAI-compatible
 * shape so the rest of the application stays provider-independent:
 *
 *   ['choices' => [['message' => ['content' => string]]], 'model' => string]
 */
interface LlmClient
{
    /**
     * Run a chat completion.
     *
     * @param array{messages: array<int, array{role: string, content: string}>, model?: string, temperature?: float, max_tokens?: int} $params
     * @return array{choices: array<int, array{message: array{content: string}}>, model?: string}
     */
    public function complete(array $params): array;

    /**
     * Run a completion, retrying with the configured fallback model on failure.
     *
     * @param array{messages: array<int, array{role: string, content: string}>, model?: string, temperature?: float, max_tokens?: int} $params
     * @return array{choices: array<int, array{message: array{content: string}}>, model?: string}
     */
    public function withFallback(array $params): array;

    /**
     * Run a completion constrained to a JSON schema, returning the decoded object.
     *
     * The schema is a provider-neutral JSON Schema array (object shape with
     * `properties`, `required`, `additionalProperties: false`).
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $schema
     * @return array<string, mixed> decoded structured output (empty array if none)
     */
    public function structured(array $messages, array $schema): array;

    /**
     * Run a multi-step tool-use ("ReAct") loop.
     *
     * @param array<int, \Prism\Prism\Tool> $tools
     * @return array{text: string, steps: array<int, array{text: string, tool_calls: array<int, array{name: string, args: array}>, tool_results: array<int, array{name: string, result: string}>}>}
     */
    public function agentic(string $system, string $user, array $tools, int $maxSteps = 8): array;
}
