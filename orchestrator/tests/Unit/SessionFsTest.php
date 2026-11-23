<?php

namespace Tests\Unit;

use App\Tools\SessionFs;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SessionFsTest extends TestCase
{
    private const BASE = 'runs/test-session/example_com/analyze';

    public function test_step1_not_satisfied_without_main_html(): void
    {
        Storage::fake('local');

        $fs = new SessionFs();

        $this->assertFalse($fs->isStep1Satisfied(self::BASE));
    }

    public function test_step1_not_satisfied_with_empty_main_html(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(self::BASE . '/main.html', '');

        $fs = new SessionFs();

        $this->assertFalse($fs->isStep1Satisfied(self::BASE));
    }

    public function test_step1_not_satisfied_without_screenshot(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(self::BASE . '/main.html', '<html><body>Acmena</body></html>');

        $fs = new SessionFs();

        // main.html alone is not enough — a screenshot is required as visual proof.
        $this->assertFalse($fs->isStep1Satisfied(self::BASE));
    }

    public function test_step1_satisfied_with_main_html_and_screenshot(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(self::BASE . '/main.html', '<html><body>Acmena</body></html>');
        Storage::disk('local')->put(self::BASE . '/screenshots/home.png', 'PNGDATA');

        $fs = new SessionFs();

        $this->assertTrue($fs->isStep1Satisfied(self::BASE));
    }

    public function test_write_main_markdown_persists_file(): void
    {
        Storage::fake('local');

        (new SessionFs())->writeMainMarkdown(self::BASE, "# Pavadinimas\n\nTekstas");

        Storage::disk('local')->assertExists(self::BASE . '/main.md');
    }

    public function test_write_react_trace_persists_jsonl(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');

        (new \App\Tools\SessionFs())->writeReactTrace('runs/test-rt/pending/analyze', [
            ['step' => 0, 'text' => 'thinking'],
        ]);

        \Illuminate\Support\Facades\Storage::disk('local')->assertExists('runs/test-rt/pending/analyze/logs/react_trace.jsonl');
    }

    public function test_read_profile_returns_null_when_absent(): void
    {
        Storage::fake('local');

        $this->assertNull((new SessionFs())->readProfile(self::BASE));
    }

    public function test_read_profile_roundtrips_written_profile(): void
    {
        Storage::fake('local');

        $fs = new SessionFs();
        $profile = ['name' => 'Acmena UAB', 'emails' => ['info@acmena.lt'], 'social_links' => []];
        $fs->writeProfile(self::BASE, $profile);

        $this->assertSame($profile, $fs->readProfile(self::BASE));
    }
}
