<?php

namespace Tests\Unit;

use App\Services\HtmlStructureAnalyzer;
use Tests\TestCase;

class HtmlStructureAnalyzerTest extends TestCase
{
    public function test_social_links_skip_policy_urls_and_deduplicate(): void
    {
        $html = '<html><body>'
            . '<a href="https://www.facebook.com/acmenalt">FB</a>'
            . '<a href="https://www.facebook.com/acmenalt/">FB dublikatas</a>'
            . '<a href="https://www.facebook.com/policy.php/">FB policy</a>'
            . '<a href="https://www.facebook.com/about/privacy/update">FB privacy</a>'
            . '<a href="https://twitter.com/en/privacy">TW privacy</a>'
            . '<a href="https://www.linkedin.com/legal/privacy-policy">LI legal</a>'
            . '<a href="https://www.linkedin.com/company/acmenalt/">LI</a>'
            . '<a href="https://www.instagram.com/acmenalt/">IG</a>'
            . '</body></html>';

        $structure = (new HtmlStructureAnalyzer())->analyzeStructure($html, 'https://acmena.lt');
        $social = $structure['company_indicators']['social_links'];

        $this->assertContains('https://www.facebook.com/acmenalt', $social);
        $this->assertContains('https://www.linkedin.com/company/acmenalt', $social);
        $this->assertContains('https://www.instagram.com/acmenalt', $social);
        $this->assertCount(3, $social);
    }

    public function test_phones_extracted_from_tel_links_and_lt_formats_only(): void
    {
        $html = '<html><body>'
            . '<a href="tel:+37052498888">Skambinkite</a>'
            . '<p>Mob. +370 686 12345, sena forma 8 686 54321.</p>'
            . '<p>Timestamp 1783278762, kodas 2020000328, ID 8003020821.</p>'
            . '</body></html>';

        $structure = (new HtmlStructureAnalyzer())->analyzeStructure($html);
        $phones = $structure['company_indicators']['contact_info']['phones'];

        $this->assertContains('+37052498888', $phones);
        $this->assertContains('+370 686 12345', $phones);
        $this->assertContains('8 686 54321', $phones);
        $this->assertNotContains('1783278762', $phones);
        $this->assertNotContains('2020000328', $phones);
        $this->assertNotContains('8003020821', $phones);
        $this->assertCount(3, $phones);
    }

    public function test_emails_ignore_script_content_and_return_sequential_array(): void
    {
        $html = '<html><body>'
            . '<script>var cfg = {dsn: "abc123def456@sentry-next.wixpress.com"};</script>'
            . '<style>.x{content:"css@sentry.io"}</style>'
            . '<p>Rašykite info@telvona.lt arba pardavimai@telvona.lt</p>'
            . '</body></html>';

        $structure = (new HtmlStructureAnalyzer())->analyzeStructure($html);
        $emails = $structure['company_indicators']['contact_info']['emails'];

        $this->assertSame(['info@telvona.lt', 'pardavimai@telvona.lt'], $emails);
    }
}