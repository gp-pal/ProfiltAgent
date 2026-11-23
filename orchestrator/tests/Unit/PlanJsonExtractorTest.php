<?php

namespace Tests\Unit;

use App\Services\PlanJsonExtractor;
use Tests\TestCase;

class PlanJsonExtractorTest extends TestCase
{
    public function test_returns_a_clean_json_object_unchanged(): void
    {
        $json = '{"version":"2.0","actions":[]}';

        $this->assertSame($json, PlanJsonExtractor::extract($json));
    }

    public function test_strips_surrounding_prose(): void
    {
        $response = "Here is the plan:\n{\"version\":\"2.0\",\"actions\":[]}\nHope that helps.";

        $this->assertSame('{"version":"2.0","actions":[]}', PlanJsonExtractor::extract($response));
    }

    public function test_extracts_from_fenced_code_block_when_object_is_invalid_inline(): void
    {
        // A brace-run exists but is invalid JSON, forcing the fenced-block branch.
        $response = "```json\n{\"version\":\"2.0\",\"actions\":[{\"do\":\"goto\"}]}\n```";

        $decoded = json_decode(PlanJsonExtractor::extract($response), true);

        $this->assertSame('2.0', $decoded['version']);
    }

    public function test_completes_truncated_json_by_closing_braces(): void
    {
        // Object cut off before its closing braces/brackets -> completion closes them.
        $truncated = '{"version":"2.0","actions":[{"do":"goto","url":"https://x.lt"}';

        $decoded = json_decode(PlanJsonExtractor::extract($truncated), true);

        $this->assertIsArray($decoded);
        $this->assertSame('goto', $decoded['actions'][0]['do']);
    }

    public function test_returns_input_untouched_when_not_json(): void
    {
        $this->assertSame('no json here', PlanJsonExtractor::extract('no json here'));
    }
}
