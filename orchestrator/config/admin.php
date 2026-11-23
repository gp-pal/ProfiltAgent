<?php

return [
    // Single dashboard admin. Set ADMIN_EMAIL / ADMIN_PASSWORD (and optional
    // ADMIN_NAME) in .env, then run `php artisan agent:create-admin` — an
    // idempotent upsert that never stores the plaintext password. There is no
    // seeder; creation is only via that command (or --email/--password flags).
    'name' => env('ADMIN_NAME', 'Admin'),
    'email' => env('ADMIN_EMAIL', ''),
    'password' => env('ADMIN_PASSWORD', ''),
];
