<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Create or update the single dashboard admin user. Reads config/admin.php
 * (ADMIN_EMAIL / ADMIN_PASSWORD) by default; options override for one-off use.
 * Idempotent (upsert by email) — safe to re-run; never logs the password.
 */
class SeedAdmin extends Command
{
    protected $signature = 'agent:create-admin {--email=} {--password=} {--name=}';

    protected $description = 'Create/update the Filament dashboard admin user';

    public function handle(): int
    {
        $email = (string) ($this->option('email') ?: config('admin.email'));
        $password = (string) ($this->option('password') ?: config('admin.password'));
        $name = (string) ($this->option('name') ?: config('admin.name', 'Admin'));

        if ($email === '' || $password === '') {
            $this->error('Set ADMIN_EMAIL and ADMIN_PASSWORD in .env (or pass --email/--password).');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        $this->info("Dashboard admin ready: {$user->email}");

        return self::SUCCESS;
    }
}
