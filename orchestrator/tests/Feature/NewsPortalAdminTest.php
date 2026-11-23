<?php

namespace Tests\Feature;

use App\Models\NewsPortal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsPortalAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@profiltagent.example',
            'password' => 'password123',
        ]);
    }

    public function test_list_page_requires_authentication(): void
    {
        $this->get('/admin/news-portals')->assertRedirect('/admin/login');
    }

    public function test_admin_can_list_the_seeded_portals(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/news-portals')
            ->assertStatus(200)
            ->assertSee('delfi.lt');
    }

    public function test_admin_can_reach_the_create_page(): void
    {
        $this->actingAs($this->admin())->get('/admin/news-portals/create')->assertStatus(200);
    }

    public function test_admin_can_reach_the_edit_page(): void
    {
        $portal = NewsPortal::query()->where('domain', 'delfi.lt')->firstOrFail();

        $this->actingAs($this->admin())
            ->get("/admin/news-portals/{$portal->getKey()}/edit")
            ->assertStatus(200);
    }
}
