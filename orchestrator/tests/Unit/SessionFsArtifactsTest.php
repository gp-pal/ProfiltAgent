<?php

namespace Tests\Unit;

use App\Tools\SessionFs;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SessionFsArtifactsTest extends TestCase
{
    private const BASE = 'runs/sess-1/example.lt/analyze';

    private function seedArtifacts(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(self::BASE . '/summary.md', "# Acmena UAB\n\nGreat company.");
        (new SessionFs())->writeProfile(self::BASE, ['name' => 'Acmena UAB', 'emails' => ['info@acmena.lt']]);
        Storage::disk('local')->put(self::BASE . '/screenshots/home.png', 'PNGDATA');
        Storage::disk('local')->put(self::BASE . '/screenshots/about.jpg', 'JPGDATA');
        Storage::disk('local')->put(self::BASE . '/screenshots/notes.txt', 'ignore me');
    }

    public function test_find_analysis_base_discovers_domain_dir_under_session(): void
    {
        $this->seedArtifacts();

        $this->assertSame(self::BASE, (new SessionFs())->findAnalysisBase('sess-1'));
    }

    public function test_find_analysis_base_returns_null_for_blank_session(): void
    {
        Storage::fake('local');

        $this->assertNull((new SessionFs())->findAnalysisBase(''));
    }

    public function test_find_analysis_base_returns_null_for_unknown_session(): void
    {
        $this->seedArtifacts();

        $this->assertNull((new SessionFs())->findAnalysisBase('sess-missing'));
    }

    public function test_read_summary_returns_contents(): void
    {
        $this->seedArtifacts();

        $this->assertSame("# Acmena UAB\n\nGreat company.", (new SessionFs())->readSummary(self::BASE));
    }

    public function test_read_summary_returns_null_when_absent_or_empty(): void
    {
        Storage::fake('local');
        $fs = new SessionFs();

        $this->assertNull($fs->readSummary(self::BASE));

        Storage::disk('local')->put(self::BASE . '/summary.md', '');
        $this->assertNull($fs->readSummary(self::BASE));
    }

    public function test_read_profile_returns_written_profile(): void
    {
        $this->seedArtifacts();

        $this->assertSame(
            ['name' => 'Acmena UAB', 'emails' => ['info@acmena.lt']],
            (new SessionFs())->readProfile(self::BASE),
        );
    }

    public function test_list_screenshots_returns_sorted_image_basenames_only(): void
    {
        $this->seedArtifacts();

        // Sorted, image extensions only — the .txt is excluded.
        $this->assertSame(['about.jpg', 'home.png'], (new SessionFs())->listScreenshots(self::BASE));
    }

    public function test_list_screenshots_returns_empty_when_dir_missing(): void
    {
        Storage::fake('local');

        $this->assertSame([], (new SessionFs())->listScreenshots(self::BASE));
    }

    public function test_a_news_article_is_stored_as_both_html_and_extracted_text(): void
    {
        // Same reasoning as main.html beside main.md: the text is what the pipeline and
        // the dashboard read, the HTML is what a human opens when extraction looks wrong.
        Storage::fake('local');
        $fs = new SessionFs();

        $paths = $fs->writeNewsArticle(
            'runs/s1/acmena.lt/analyze',
            'Didmena uždaro parduotuvę Vilniuje!',
            '<h1>Didmena</h1>',
            '# Didmena',
        );

        $this->assertSame('runs/s1/acmena.lt/analyze/news/didmena-u-daro-parduotuv-vilniuje.html', $paths['html']);
        Storage::disk('local')->assertExists($paths['html']);
        Storage::disk('local')->assertExists($paths['markdown']);
        $this->assertSame('# Didmena', Storage::disk('local')->get($paths['markdown']));
    }

    public function test_a_news_slug_that_reduces_to_nothing_still_gets_a_filename(): void
    {
        Storage::fake('local');

        $paths = (new SessionFs())->writeNewsArticle('runs/s1/acmena.lt/analyze', '???', '<p>x</p>', 'x');

        $this->assertSame('runs/s1/acmena.lt/analyze/news/article.html', $paths['html']);
        Storage::disk('local')->assertExists($paths['markdown']);
    }
}
