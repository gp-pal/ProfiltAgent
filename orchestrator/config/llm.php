<?php

return [
    /*
    |--------------------------------------------------------------------------
    | LLM Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Large Language Model integration
    |
    */

    /*
    | Which client implementation backs the App\IO\Contracts\LlmClient binding.
    |   prism         - unified multi-provider client (Ollama | OpenAI | Anthropic)
    |   legacy_ollama - the original Guzzle OpenAI-compatible client (escape hatch)
    */
    'driver' => env('LLM_DRIVER', 'prism'),

    /*
    | Active provider for the Prism driver: ollama | openai | anthropic.
    | Provider credentials/URLs live in config/prism.php.
    */
    'provider' => env('LLM_PROVIDER', 'anthropic'),

    'model' => env('LLM_MODEL', env('MODEL', 'claude-haiku-4-5')),
    'fallback_model' => env('LLM_FALLBACK_MODEL', env('MODEL_FALLBACK', 'claude-haiku-4-5')),
    'temperature' => env('LLM_TEMPERATURE', 0.1),
    'max_tokens' => env('LLM_MAX_TOKENS', 2000),

    // Legacy OpenAI-compatible transport (used only by the legacy_ollama driver)
    'api_base' => env('OPENAI_API_BASE', 'http://localhost:11434/v1'),
    'api_key' => env('OPENAI_API_KEY', 'ollama'),
    'timeout' => env('LLM_TIMEOUT', 120),
    'num_ctx' => env('NUM_CTX', 8192),
];
