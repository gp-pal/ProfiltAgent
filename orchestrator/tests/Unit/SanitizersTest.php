<?php

namespace Tests\Unit;

use App\Validation\Sanitizers;
use Tests\TestCase;

class SanitizersTest extends TestCase
{
    public function test_sanitize_text_preserves_newlines_when_requested(): void
    {
        $input = "# Antraštė\n\n\n\n- punktas   vienas\n- punktas\tdu";

        $default = (new Sanitizers())->sanitizeText($input);
        $preserved = (new Sanitizers())->sanitizeText($input, true);

        $this->assertStringNotContainsString("\n", $default);
        $this->assertSame("# Antraštė\n\n- punktas vienas\n- punktas du", $preserved);
    }
}
