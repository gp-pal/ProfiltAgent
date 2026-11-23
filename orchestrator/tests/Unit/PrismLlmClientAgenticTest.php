<?php

namespace Tests\Unit;

use App\IO\PrismLlmClient;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\TestCase;

class PrismLlmClientAgenticTest extends TestCase
{
    public function test_agentic_returns_text_and_steps_shape(): void
    {
        config()->set('llm.provider', 'anthropic');
        config()->set('llm.model', 'claude-haiku-4-5');

        Prism::fake([
            TextResponseFake::make()->withText('done'),
        ]);

        $result = (new PrismLlmClient())->agentic('system', 'user', [], 8);

        $this->assertArrayHasKey('text', $result);
        $this->assertArrayHasKey('steps', $result);
        $this->assertSame('done', $result['text']);
        $this->assertIsArray($result['steps']);
    }

    public function test_stringify_tool_result_json_encodes_arrays(): void
    {
        $client = new PrismLlmClient();
        $m = new \ReflectionMethod($client, 'stringifyToolResult');
        $m->setAccessible(true);

        $this->assertSame('{"domain":"acmena.lt"}', $m->invoke($client, ['domain' => 'acmena.lt']));
        $this->assertSame('hello', $m->invoke($client, 'hello'));
        $this->assertSame('42', $m->invoke($client, 42));
        $this->assertSame('', $m->invoke($client, null));
    }
}
