<?php

namespace Tests\Feature;

use App\Models\CollectionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RunArtifactControllerTest extends TestCase
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

    private function makeRun(string $session): CollectionRun
    {
        return CollectionRun::create([
            'run_hash' => 'h-' . $session,
            'company_key' => 'code:' . $session,
            'company_code' => $session,
            'name' => 'Firm ' . $session,
            'session' => $session,
            'sources_used' => ['registry_db'],
            'overall_score' => 0.9,
            'verdict' => 'pass',
            'status' => 'done',
        ]);
    }

    public function test_valid_screenshot_request_streams_the_image(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('runs/sess-shots/example.lt/analyze/screenshots/home.png', 'PNGDATA');

        $run = $this->makeRun('sess-shots');

        $this->actingAs($this->admin())
            ->get("/admin/runs/{$run->getKey()}/screenshot/home.png")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_garbage_name_is_rejected_with_404(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('runs/sess-shots/example.lt/analyze/screenshots/home.png', 'PNGDATA');

        $run = $this->makeRun('sess-shots');

        // Wrong extension — never matches the strict basename regex.
        $this->actingAs($this->admin())
            ->get("/admin/runs/{$run->getKey()}/screenshot/foo.txt")
            ->assertNotFound();
    }

    public function test_path_traversal_name_is_rejected_with_404(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('runs/sess-shots/example.lt/analyze/screenshots/home.png', 'PNGDATA');

        $run = $this->makeRun('sess-shots');

        $this->actingAs($this->admin())
            ->get("/admin/runs/{$run->getKey()}/screenshot/..%2f..%2fetc%2fpasswd")
            ->assertNotFound();
    }

    public function test_run_without_artifacts_returns_404(): void
    {
        Storage::fake('local');

        $run = $this->makeRun('sess-empty');

        $this->actingAs($this->admin())
            ->get("/admin/runs/{$run->getKey()}/screenshot/home.png")
            ->assertNotFound();
    }

    public function test_unauthenticated_request_redirects_to_panel_login(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('runs/sess-shots/example.lt/analyze/screenshots/home.png', 'PNGDATA');

        $run = $this->makeRun('sess-shots');

        $this->get("/admin/runs/{$run->getKey()}/screenshot/home.png")
            ->assertRedirect('/admin/login');
    }
}
