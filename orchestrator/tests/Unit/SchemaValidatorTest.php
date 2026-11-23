<?php

namespace Tests\Unit;

use App\Validation\SchemaValidator;
use Tests\TestCase;

class SchemaValidatorTest extends TestCase
{
    public function test_extract_markdown_action_is_valid(): void
    {
        $plan = [
            'version' => '1.0',
            'meta' => ['task' => 'Test', 'company' => 'Acmena'],
            'actions' => [
                ['do' => 'goto', 'url' => 'https://acmena.lt'],
                ['do' => 'extract_markdown', 'save_as' => 'main_md'],
                ['do' => 'extract_markdown', 'selector' => 'main', 'save_as' => 'main_only', 'only_main_content' => false],
                ['do' => 'return', 'data' => ['main_md', 'main_only']],
            ],
        ];

        $this->assertTrue((new SchemaValidator())->validate($plan));
    }

    /**
     * The PHP validator (justinrainbow/json-schema) does not enforce draft-07
     * if/then conditional requirements — those are enforced at the runner
     * boundary (Ajv in runner/server.js validates the same schema). What the
     * PHP side DOES enforce is the action enum, so an unknown/mistyped action
     * must be rejected here.
     */
    public function test_unknown_action_is_rejected(): void
    {
        $plan = [
            'version' => '1.0',
            'meta' => ['task' => 'Test', 'company' => 'Acmena'],
            'actions' => [
                ['do' => 'extract_markdownX', 'save_as' => 'main_md'],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        (new SchemaValidator())->validate($plan);
    }
}