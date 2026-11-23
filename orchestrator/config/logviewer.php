<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Log file tailed by the admin Log viewer page
    |--------------------------------------------------------------------------
    |
    | Absolute path to the log file the read-only /admin/log-viewer page tails.
    | Defaults to the app's single-channel log. Overridable via env (and by
    | tests, which point it at a throwaway temp file so they never touch the
    | real log).
    |
    */

    'path' => env('LOGVIEWER_PATH', storage_path('logs/laravel.log')),
];
