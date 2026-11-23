<?php

namespace Tests\Unit;

use App\Services\LinkExtractor;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LinkExtractorTest extends TestCase
{
    private string $basePath = 'app/private/runs/test-le/example.com/analyze';

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/runs/test-le'));
        parent::tearDown();
    }

    public function test_extract_and_process_reads_links_raw_from_disk(): void
    {
        $dir = storage_path($this->basePath);
        File::makeDirectory($dir, 0755, true, true);
        file_put_contents($dir . '/links_raw.json', json_encode([
            ['text' => 'Apie mus', 'href' => 'https://example.com/apie', 'title' => ''],
            ['text' => 'Kontaktai', 'href' => 'https://example.com/kontaktai', 'title' => ''],
        ]));

        $result = (new LinkExtractor())->extractAndProcess($this->basePath, 'example.com');

        // Prieš pataisą readRawLinks ieško storage/app/app/private/... ir grąžina [] → total_links = 0
        $this->assertSame(2, $result['summary']['total_links']);
        $this->assertFileExists($dir . '/links.json');
    }

    public function test_extract_and_process_walks_links_from_main_html(): void
    {
        $dir = storage_path($this->basePath);
        File::makeDirectory($dir, 0755, true, true);
        file_put_contents($dir . '/main.html', implode('', [
            '<html><body><nav><ul>',
            '<li><a href="https://example.com/apie">Apie mus</a></li>',
            '</ul></nav>',
            '<footer><a href="https://example.com/kontaktai">Kontaktai</a></footer>',
            '</body></html>',
        ]));

        $result = (new LinkExtractor())->extractAndProcess($this->basePath, 'example.com');

        $this->assertGreaterThanOrEqual(2, $result['summary']['total_links']);
    }
}