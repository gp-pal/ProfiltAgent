<?php

namespace Tests\Feature;

use App\Filament\Pages\LogViewer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LogViewerPageTest extends TestCase
{
    use RefreshDatabase;

    private ?string $logPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Point the viewer at a throwaway temp log so tests never read from or
        // mutate the real storage/logs/laravel.log (which the running app writes
        // to concurrently). tempnam() creates the file empty; individual tests
        // fill or remove it as needed.
        $this->logPath = tempnam(sys_get_temp_dir(), 'logviewer-test-') ?: null;
        config()->set('logviewer.path', $this->logPath);
    }

    protected function tearDown(): void
    {
        if ($this->logPath !== null && is_file($this->logPath)) {
            @unlink($this->logPath);
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@profiltagent.example',
            'password' => 'password123',
        ]);
    }

    public function test_page_renders_for_authenticated_admin(): void
    {
        $this->actingAs($this->admin());

        $this->get('/admin/log-viewer')->assertStatus(200);

        Livewire::test(LogViewer::class)
            ->assertOk()
            ->assertSee('Logs');
    }

    public function test_log_tail_reads_from_the_configured_path(): void
    {
        $this->actingAs($this->admin());

        $marker = 'LOGVIEWER_TEST_MARKER_' . uniqid();
        file_put_contents($this->logPath, $marker . "\n");

        Livewire::test(LogViewer::class)
            ->assertOk()
            ->assertSee($marker);

        $this->assertStringContainsString($marker, (new LogViewer())->logTail());
    }

    public function test_log_tail_is_empty_message_when_the_file_has_no_content(): void
    {
        $this->actingAs($this->admin());

        // tempnam() created the file empty.
        $this->assertSame('Log file is empty.', (new LogViewer())->logTail());
    }

    public function test_log_tail_is_fail_soft_when_the_file_is_absent(): void
    {
        $this->actingAs($this->admin());

        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }

        $this->assertSame('No log file yet.', (new LogViewer())->logTail());

        Livewire::test(LogViewer::class)
            ->assertOk()
            ->assertSee('No log file yet.');
    }
}
