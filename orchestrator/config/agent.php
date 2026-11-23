<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Agent API key
    |--------------------------------------------------------------------------
    |
    | Shared secret required (via the X-Api-Key header or a Bearer token) to call
    | the orchestrator's action endpoints (/api/run, /api/scrape, /ws/*).
    | When empty, authentication is disabled only in local/testing (dev
    | convenience); any other environment refuses requests (fail closed), so a
    | networked/production deployment must set it.
    |
    */
    'api_key' => env('AGENT_API_KEY', ''),
];
