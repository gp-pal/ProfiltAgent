<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders(): void
    {
        $this->get('/admin/login')->assertStatus(200);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_authenticated_admin_can_access_the_panel(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@profiltagent.example',
            'password' => 'password123',
        ]);

        $this->actingAs($admin)->get('/admin')->assertStatus(200);
    }

    public function test_create_admin_command_upserts_the_user(): void
    {
        $this->artisan('agent:create-admin', ['--email' => 'ops@profiltagent.example', '--password' => 'secret123', '--name' => 'Ops'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', ['email' => 'ops@profiltagent.example', 'name' => 'Ops']);

        // Idempotent: re-run updates rather than duplicates.
        $this->artisan('agent:create-admin', ['--email' => 'ops@profiltagent.example', '--password' => 'secret123'])
            ->assertExitCode(0);
        $this->assertSame(1, User::where('email', 'ops@profiltagent.example')->count());
    }

    public function test_create_admin_fails_without_credentials(): void
    {
        config(['admin.email' => '', 'admin.password' => '']);
        $this->artisan('agent:create-admin')->assertExitCode(1);
    }
}
