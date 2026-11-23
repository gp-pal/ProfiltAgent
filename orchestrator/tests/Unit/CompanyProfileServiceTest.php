<?php

namespace Tests\Unit;

use App\IO\Contracts\LlmClient;
use App\Services\CompanyProfileService;
use App\Services\FieldRequirements;
use App\Validation\Sanitizers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CompanyProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_build_merges_llm_output_with_deterministic_contacts(): void
    {
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')->once()->andReturn([
            'name' => 'Acmena UAB',
            'description' => 'Statybos įmonė',
            'activity' => 'Statyba',
            'address' => 'Vilnius',
            'emails' => ['sales@acmena.lt'],
            'phones' => [],
            'social_links' => ['https://facebook.com/acmena'],
        ]);

        $structure = ['company_indicators' => [
            'contact_info' => ['emails' => ['info@acmena.lt'], 'phones' => ['+37060000000']],
            'social_links' => ['https://linkedin.com/company/acmena'],
        ]];

        $profile = (new CompanyProfileService($llm, new Sanitizers(), new FieldRequirements()))
            ->build('Acmena', 'acmena.lt', '<html><body>Acmena statyba</body></html>', $structure);

        $this->assertSame('Acmena UAB', $profile['name']);
        $this->assertSame('acmena.lt', $profile['domain']);

        // Deterministic + LLM contacts are unioned (deterministic preserved)
        $this->assertContains('info@acmena.lt', $profile['emails']);
        $this->assertContains('sales@acmena.lt', $profile['emails']);
        $this->assertContains('+37060000000', $profile['phones']);
        $this->assertContains('https://linkedin.com/company/acmena', $profile['social_links']);
        $this->assertContains('https://facebook.com/acmena', $profile['social_links']);
    }

    public function test_build_defaults_name_and_survives_llm_failure(): void
    {
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')->andThrow(new \RuntimeException('rate limited'));

        $profile = (new CompanyProfileService($llm, new Sanitizers(), new FieldRequirements()))
            ->build('Acmena', 'acmena.lt', '<html></html>', []);

        // LLM failure must not crash the run; falls back to the company name + empties
        $this->assertSame('Acmena', $profile['name']);
        $this->assertSame([], $profile['emails']);
        $this->assertSame([], $profile['social_links']);
    }

    public function test_render_summary_contains_key_fields(): void
    {
        $md = (new CompanyProfileService(Mockery::mock(LlmClient::class), new Sanitizers(), new FieldRequirements()))->renderSummary([
            'name' => 'Acmena UAB',
            'domain' => 'acmena.lt',
            'activity' => 'Statyba',
            'description' => 'Statybos įmonė',
            'address' => 'Vilnius',
            'emails' => ['info@acmena.lt'],
            'phones' => [],
            'social_links' => ['https://facebook.com/acmena'],
        ]);

        $this->assertStringContainsString('# Acmena UAB', $md);
        $this->assertStringContainsString('acmena.lt', $md);
        $this->assertStringContainsString('info@acmena.lt', $md);
        $this->assertStringContainsString('https://facebook.com/acmena', $md);
    }

    public function test_build_prefers_runner_markdown_over_raw_html(): void
    {
        $captured = null;
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')
            ->once()
            ->with(Mockery::on(function ($messages) use (&$captured) {
                $captured = $messages[1]['content'];
                return true;
            }), Mockery::type('array'))
            ->andReturn([]);

        (new CompanyProfileService($llm, new Sanitizers(), new FieldRequirements()))->build(
            'Acmena',
            'acmena.lt',
            '<html><body><p>RAW-HTML-TEKSTAS</p></body></html>',
            [],
            [],
            "# Acmena\n\nMARKDOWN-TEKSTAS"
        );

        $this->assertStringContainsString('MARKDOWN-TEKSTAS', $captured);
        $this->assertStringNotContainsString('RAW-HTML-TEKSTAS', $captured);
    }

    public function test_build_falls_back_to_html_when_markdown_missing(): void
    {
        $captured = null;
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')
            ->once()
            ->with(Mockery::on(function ($messages) use (&$captured) {
                $captured = $messages[1]['content'];
                return true;
            }), Mockery::type('array'))
            ->andReturn([]);

        (new CompanyProfileService($llm, new Sanitizers(), new FieldRequirements()))->build(
            'Acmena',
            'acmena.lt',
            '<html><body><p>RAW-HTML-TEKSTAS</p></body></html>'
        );

        $this->assertStringContainsString('RAW-HTML-TEKSTAS', $captured);
    }

    public function test_markdown_structure_reaches_llm_with_newlines(): void
    {
        $captured = null;
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')
            ->once()
            ->with(Mockery::on(function ($messages) use (&$captured) {
                $captured = $messages[1]['content'];
                return true;
            }), Mockery::type('array'))
            ->andReturn([]);

        (new CompanyProfileService($llm, new Sanitizers(), new FieldRequirements()))->build(
            'Acmena',
            'acmena.lt',
            '<html></html>',
            [],
            [],
            "# Acmena\n\n- PIRMAS\n- ANTRAS"
        );

        $this->assertStringContainsString("- PIRMAS\n- ANTRAS", $captured);
    }

    public function test_the_schema_contains_the_declared_fields_with_catalogue_types(): void
    {
        $captured = null;
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')->andReturnUsing(function (array $messages, array $schema) use (&$captured): array {
            $captured = ['messages' => $messages, 'schema' => $schema];

            return [];
        });

        (new CompanyProfileService($llm, new Sanitizers(), new FieldRequirements()))
            ->build('Acmena', 'acmena.lt', '<html></html>', [], [], null, ['positioning', 'brands']);

        $props = $captured['schema']['properties'];
        $this->assertArrayHasKey('positioning', $props);
        $this->assertSame('string', $props['positioning']['type']);   // `text` maps to a JSON string
        $this->assertSame('array', $props['brands']['type']);
        $this->assertArrayNotHasKey('locations', $props, 'undeclared fields must not cost prompt tokens');
    }

    public function test_extraction_hints_reach_the_prompt(): void
    {
        $captured = null;
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')->andReturnUsing(function (array $messages, array $schema) use (&$captured): array {
            $captured = $schema;

            return [];
        });

        (new CompanyProfileService($llm, new Sanitizers(), new FieldRequirements()))
            ->build('Acmena', 'acmena.lt', '<html></html>', [], [], null, ['positioning']);

        // The admin-authored hint is what teaches the model what the field means.
        $this->assertStringContainsString('išskiria', $captured['properties']['positioning']['description']);
    }

    public function test_without_a_declared_list_the_legacy_schema_is_used(): void
    {
        $captured = null;
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')->andReturnUsing(function (array $messages, array $schema) use (&$captured): array {
            $captured = $schema;

            return [];
        });

        (new CompanyProfileService($llm, new Sanitizers(), new FieldRequirements()))
            ->build('Acmena', 'acmena.lt', '<html></html>');

        $this->assertEqualsCanonicalizing(
            ['name', 'description', 'activity', 'address', 'emails', 'phones', 'social_links'],
            array_keys($captured['properties']),
        );
    }

    public function test_a_declared_catalogue_field_the_llm_answers_survives_into_the_returned_profile(): void
    {
        // Asking for the field in the schema is pointless if the LLM's answer
        // never makes it out of build() — normalize() must carry declared
        // catalogue fields through, not just the legacy six.
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')->andReturn([
            'name' => 'Acmena',
            'positioning' => 'Greičiausias pristatymas Baltijos šalyse',
            'brands' => ['Acmena Pro', 'Acmena Lite'],
        ]);

        $profile = (new CompanyProfileService($llm, new Sanitizers(), new FieldRequirements()))
            ->build('Acmena', 'acmena.lt', '<html></html>', [], [], null, ['positioning', 'brands']);

        $this->assertSame('Greičiausias pristatymas Baltijos šalyse', $profile['positioning']);
        $this->assertSame(['Acmena Pro', 'Acmena Lite'], $profile['brands']);
    }

    public function test_an_undeclared_or_unanswered_catalogue_field_normalizes_to_its_empty_shape(): void
    {
        // A field the LLM left out must come back as its declared empty shape
        // (array or string), not be missing entirely -- a later phase reports
        // "not found" from this, so the shape must be predictable.
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('structured')->andReturn(['name' => 'Acmena']);

        $profile = (new CompanyProfileService($llm, new Sanitizers(), new FieldRequirements()))
            ->build('Acmena', 'acmena.lt', '<html></html>', [], [], null, ['positioning', 'brands']);

        $this->assertSame('', $profile['positioning']);
        $this->assertSame([], $profile['brands']);
    }
}

