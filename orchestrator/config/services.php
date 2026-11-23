<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'browser_runner' => [
        'url' => env('RUNNER_BASE', 'http://localhost:8080'),
        'timeout' => env('BROWSER_RUNNER_TIMEOUT', 120),
        'token' => env('RUNNER_TOKEN', ''),
    ],

    'searxng' => [
        'url' => env('SEARXNG_URL', 'http://searxng:8080'),
        // Global ceiling on queries per minute across every process (the counter
        // lives in the shared cache). The engines behind SearXNG rate-limit and
        // CAPTCHA us: once suspended they return nothing for minutes, so our own
        // burst is what empties a run. Exceeding the ceiling refuses the query
        // locally instead of digging the hole deeper. 0 disables the throttle.
        'max_per_minute' => (int) env('SEARXNG_MAX_PER_MINUTE', 20),
    ],

    'langfuse' => [
        'public_key' => env('LANGFUSE_PUBLIC_KEY', ''),
        'secret_key' => env('LANGFUSE_SECRET_KEY', ''),
        'base_url' => env('LANGFUSE_BASE_URL', 'https://cloud.langfuse.com'),
        'project_id' => env('LANGFUSE_PROJECT_ID', null),
        'enabled' => env('LANGFUSE_ENABLED', true),
        // Queue connection for the ingestion job. 'sync' runs it inline after the
        // response (no worker needed). Switch to 'database' (+ run queue:work) to
        // fully offload ingestion when request throughput grows.
        'queue_connection' => env('LANGFUSE_QUEUE_CONNECTION', 'sync'),
    ],

];
