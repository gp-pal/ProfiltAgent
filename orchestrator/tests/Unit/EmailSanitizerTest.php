<?php

namespace Tests\Unit;

use App\Services\EmailSanitizer;
use Tests\TestCase;

class EmailSanitizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function filenameLikeProvider(): array
    {
        return [
            'pgp key ref' => ['soc@energitra.lt.asc'],
            'png asset' => ['logo@company.lt.png'],
            'pdf' => ['brochure@company.com.pdf'],
            'uppercase ext' => ['soc@energitra.lt.ASC'],
        ];
    }

    /**
     * @dataProvider filenameLikeProvider
     */
    public function test_rejects_filename_like_addresses(string $email): void
    {
        $this->assertTrue(EmailSanitizer::looksLikeFilename($email));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function realAddressProvider(): array
    {
        return [
            'lt' => ['soc@energitra.lt'],
            'com' => ['info@example.com'],
            'eu' => ['grupe@company.eu'],
            'cc slds' => ['name@company.co.uk'],
            'subdomain' => ['hello@mail.company.lt'],
        ];
    }

    /**
     * @dataProvider realAddressProvider
     */
    public function test_keeps_real_addresses(string $email): void
    {
        $this->assertFalse(EmailSanitizer::looksLikeFilename($email));
    }
}
