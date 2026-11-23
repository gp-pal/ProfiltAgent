<?php

namespace Tests\Unit;

use App\IO\RunnerClient;
use App\Services\SecondaryCrawlService;
use App\Validation\PolicyChecks;
use App\Validation\SchemaValidator;
use Mockery;
use Tests\TestCase;

class SecondaryCrawlServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_crawl_fetches_same_domain_about_and_contact_pages(): void
    {
        $schema = Mockery::mock(SchemaValidator::class);
        $policy = Mockery::mock(PolicyChecks::class);
        $runner = Mockery::mock(RunnerClient::class);

        $schema->shouldReceive('validate')->once()->with(Mockery::on(function (array $plan) {
            $extracts = array_values(array_filter(
                $plan['actions'],
                static fn (array $a): bool => str_starts_with($a['do'], 'extract_')
            ));
            return $extracts !== []
                && array_reduce($extracts, static fn (bool $c, array $a): bool => $c && $a['do'] === 'extract_markdown', true);
        }));
        $policy->shouldReceive('check')->once();
        $runner->shouldReceive('run')->once()->andReturn([
            'ok' => true,
            'data' => [
                'page_0' => "# Apie mus\n\nApie mus tekstas",
                'page_1' => "# Kontaktai\n\nKontaktai tekstas",
            ],
        ]);

        $structure = ['important_links' => [
            'about' => [['url' => 'https://acmena.lt/apie', 'text' => 'apie']],
            'contact' => [['url' => 'https://acmena.lt/kontaktai', 'text' => 'kontaktai']],
            'products' => [['url' => 'https://other.com/x', 'text' => 'x']], // external → excluded
        ]];

        $pages = (new SecondaryCrawlService($schema, $policy, $runner))
            ->crawl('Acmena', 'acmena.lt', $structure);

        $this->assertCount(2, $pages);
        $this->assertSame('https://acmena.lt/apie', $pages[0]['url']);
        $this->assertStringContainsString('Apie mus', $pages[0]['text']);
        $this->assertStringContainsString('Kontaktai', $pages[1]['text']);
    }

    public function test_crawl_returns_empty_without_candidate_urls(): void
    {
        $runner = Mockery::mock(RunnerClient::class);
        $runner->shouldNotReceive('run');

        $pages = (new SecondaryCrawlService(
            Mockery::mock(SchemaValidator::class),
            Mockery::mock(PolicyChecks::class),
            $runner
        ))->crawl('Acmena', 'acmena.lt', ['important_links' => []]);

        $this->assertSame([], $pages);
    }

    public function test_crawl_returns_empty_when_runner_fails(): void
    {
        $schema = Mockery::mock(SchemaValidator::class);
        $policy = Mockery::mock(PolicyChecks::class);
        $runner = Mockery::mock(RunnerClient::class);

        $schema->shouldReceive('validate');
        $policy->shouldReceive('check');
        $runner->shouldReceive('run')->andReturn(['ok' => false, 'error' => 'challenge_detected']);

        $structure = ['important_links' => [
            'about' => [['url' => 'https://acmena.lt/apie', 'text' => 'apie']],
        ]];

        $pages = (new SecondaryCrawlService($schema, $policy, $runner))
            ->crawl('Acmena', 'acmena.lt', $structure);

        $this->assertSame([], $pages);
    }
}
