<?php

namespace Tests\Unit;

use App\Tools\CompanyNameTokens;
use Tests\TestCase;

class CompanyNameTokensTest extends TestCase
{
    public function test_normalize_strips_diacritics_and_punctuation(): void
    {
        $this->assertSame('azerna grupe', CompanyNameTokens::normalize('Ąžerna Grupė, '));
    }

    public function test_tokens_drop_legal_forms_and_short_words(): void
    {
        $this->assertSame(['azerna'], CompanyNameTokens::tokens('Azerna, UAB'));
        $this->assertSame(['didmena'], CompanyNameTokens::tokens('Didmena LT, UAB'));
    }

    public function test_contains_all_requires_every_token(): void
    {
        $tokens = CompanyNameTokens::tokens('Azerna Grupė');

        $this->assertTrue(CompanyNameTokens::containsAll($tokens, 'AZERNA grupe plečia parką'));
        $this->assertFalse(CompanyNameTokens::containsAll($tokens, 'Azerna vien tik'));
    }

    public function test_contains_all_is_false_for_no_tokens(): void
    {
        $this->assertFalse(CompanyNameTokens::containsAll([], 'bet kas'));
    }
}
