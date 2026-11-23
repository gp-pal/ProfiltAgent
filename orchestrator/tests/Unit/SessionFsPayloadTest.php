<?php

namespace Tests\Unit;

use App\Tools\SessionFs;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SessionFsPayloadTest extends TestCase
{
    private const BASE = 'runs/sess-1/example.lt/analyze';

    public function test_read_search_results_returns_decoded_array(): void
    {
        Storage::fake('local');
        $log = [
            [
                'query' => 'Acmena Lithuania',
                'source' => 'searxng',
                'results' => [
                    ['title' => 'Acmena', 'url' => 'https://acmena.lt', 'domain' => 'acmena.lt', 'accepted' => true, 'filtered' => null],
                ],
            ],
        ];
        Storage::disk('local')->put(self::BASE . '/logs/search_results.json', json_encode($log, JSON_THROW_ON_ERROR));

        $this->assertSame($log, (new SessionFs())->readSearchResults(self::BASE));
    }

    public function test_read_search_results_returns_null_when_absent(): void
    {
        Storage::fake('local');

        $this->assertNull((new SessionFs())->readSearchResults(self::BASE));
    }

    public function test_read_search_results_returns_null_when_invalid_json(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(self::BASE . '/logs/search_results.json', '{not json');

        $this->assertNull((new SessionFs())->readSearchResults(self::BASE));
    }

    public function test_read_main_markdown_returns_contents(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(self::BASE . '/main.md', "# Acmena UAB\n\nWe make widgets.");

        $this->assertSame(
            "# Acmena UAB\n\nWe make widgets.",
            (new SessionFs())->readMainMarkdown(self::BASE),
        );
    }

    public function test_read_main_markdown_returns_null_when_absent_or_empty(): void
    {
        Storage::fake('local');
        $fs = new SessionFs();

        $this->assertNull($fs->readMainMarkdown(self::BASE));

        Storage::disk('local')->put(self::BASE . '/main.md', '');
        $this->assertNull($fs->readMainMarkdown(self::BASE));
    }

    public function test_read_structure_returns_decoded_array(): void
    {
        Storage::fake('local');
        $structure = [
            'language' => 'en',
            'page_type' => 'homepage',
            'company_indicators' => [
                'contact_info' => ['emails' => ['info@acmena.lt'], 'phones' => []],
                'social_links' => [],
            ],
            'structure_confidence' => 0.7,
        ];
        Storage::disk('local')->put(self::BASE . '/structure.json', json_encode($structure, JSON_THROW_ON_ERROR));

        $this->assertSame($structure, (new SessionFs())->readStructure(self::BASE));
    }

    public function test_read_structure_returns_null_when_absent_or_invalid(): void
    {
        Storage::fake('local');
        $fs = new SessionFs();

        $this->assertNull($fs->readStructure(self::BASE));

        Storage::disk('local')->put(self::BASE . '/structure.json', 'not json');
        $this->assertNull($fs->readStructure(self::BASE));
    }

    public function test_read_react_trace_parses_jsonl_skipping_blank_and_invalid_lines(): void
    {
        Storage::fake('local');
        $step1 = ['text' => 'Searching...', 'tool_calls' => [['name' => 'web_search', 'args' => ['query' => 'Acmena']]]];
        $step2 = ['text' => 'Visiting page...', 'tool_calls' => [['name' => 'visit_page', 'args' => ['url' => 'https://acmena.lt']]]];
        $contents = json_encode($step1, JSON_THROW_ON_ERROR) . "\n\n" . 'not json' . "\n" . json_encode($step2, JSON_THROW_ON_ERROR) . "\n";
        Storage::disk('local')->put(self::BASE . '/logs/react_trace.jsonl', $contents);

        $this->assertSame([$step1, $step2], (new SessionFs())->readReactTrace(self::BASE));
    }

    public function test_read_react_trace_returns_null_when_absent(): void
    {
        Storage::fake('local');

        $this->assertNull((new SessionFs())->readReactTrace(self::BASE));
    }

    public function test_read_react_trace_returns_null_when_only_invalid_lines(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(self::BASE . '/logs/react_trace.jsonl', "not json\n\n   \n");

        $this->assertNull((new SessionFs())->readReactTrace(self::BASE));
    }
}
