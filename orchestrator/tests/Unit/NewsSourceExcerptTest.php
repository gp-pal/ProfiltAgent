<?php

namespace Tests\Unit;

use App\IO\RunnerClient;
use App\Services\NewsPortalRegistry;
use App\Services\NewsQueryBuilder;
use App\Services\NewsSource;
use App\Tools\HttpArticleClient;
use App\Tools\SearxngSearchService;
use App\Tools\SessionFs;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * What a mention ends up carrying once its article has been downloaded and converted.
 * The runner returns captured page structures here (tests/Fixtures/news), so these assertions
 * are about the pages that caused the defect rather than about markdown written to pass.
 *
 * Three outcomes must stay apart on the mention itself: the article's words were found,
 * the page converted but holds no article, and the text was never fetched at all.
 */
class NewsSourceExcerptTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_mention_carries_the_article_body_and_the_artifact_keeps_the_whole_page(): void
    {
        $page = $this->fixture('lrytas-logistikos-centras');
        $mention = $this->mentionFor($page);

        $this->assertSame(NewsSource::EXCERPT_BODY, $mention['excerpt_state']);
        $this->assertStringStartsWith(
            'Yšužūvos višoš mėdūsmas avėpoų etošūatymo ūzolėas „Ėnydūnd“ Edypejoje ašėrėrė',
            $mention['excerpt'],
        );

        // The defect, stated as an assertion: the excerpt used to be exactly this.
        $this->assertStringStartsNotWith(mb_substr($page, 0, 40), $mention['excerpt']);
        $this->assertStringNotContainsString('Sužėtkopai', $mention['excerpt']);

        // Nothing is lost by being selective: the verbatim conversion, chrome and all,
        // is still on disk for a human to open.
        $this->assertSame($page, Storage::disk('local')->get($mention['artifacts']['markdown']));
    }

    public function test_a_page_with_no_article_body_stores_no_excerpt_and_says_why(): void
    {
        // A topic page is a real search result and a real mention. Its first 600
        // characters are "Ėneku čėtuv", an RSS link and teaser thumbnails — furniture
        // that reads as evidence, which is why nothing is the honest answer here.
        $mention = $this->mentionFor($this->fixture('15min-topic-page'));

        $this->assertArrayNotHasKey('excerpt', $mention);
        $this->assertSame(NewsSource::EXCERPT_NO_BODY, $mention['excerpt_state']);

        // The mention itself survives untouched, and the page is still on disk.
        $this->assertSame('lrytas.lt', $mention['portal']);
        $this->assertSame('2026-05-01', $mention['date']);
        $this->assertArrayHasKey('markdown', $mention['artifacts']);
    }

    public function test_a_page_that_never_converted_carries_neither_the_excerpt_nor_a_state(): void
    {
        // The third outcome, and it must not look like either of the other two: no text
        // was fetched or converted, so the source knows nothing about a body either way.
        $mention = $this->mentionFor(null);

        $this->assertArrayNotHasKey('excerpt', $mention);
        $this->assertArrayNotHasKey('excerpt_state', $mention);
    }

    public function test_a_zero_character_budget_claims_neither_a_body_nor_a_missing_one(): void
    {
        // react.news.excerpt_chars = 0 leaves no room to carry evidence. That is a third
        // thing again: the article's text was found and there was nowhere to put it. Storing
        // '' as EXCERPT_BODY asserted located evidence and then rendered an empty cell — the
        // "blank reads as no coverage" outcome this whole state field exists to remove — and
        // EXCERPT_NO_BODY would blame the page for a configured zero.
        $mention = $this->mentionFor($this->fixture('lrytas-logistikos-centras'), 0);

        $this->assertArrayNotHasKey('excerpt', $mention);
        $this->assertArrayNotHasKey('excerpt_state', $mention);

        // The text itself is still fetched, converted and stored: turning off the excerpt
        // does not turn off the artifact a human opens.
        $this->assertArrayHasKey('markdown', $mention['artifacts']);
    }

    /**
     * One on-topic mention, fetched and converted to $markdown by a stubbed runner.
     *
     * @return array<string, mixed>
     */
    private function mentionFor(?string $markdown, int $excerptChars = 600): array
    {
        Carbon::setTestNow('2026-07-15');
        Storage::fake('local');
        Storage::disk('local')->put('runs/s9/baltijossiunta.lt/analyze/main.md', 'x');

        config()->set('react.news.enabled', true);
        config()->set('react.news.max_mentions', 8);
        config()->set('react.news.extract_text', true);
        config()->set('react.news.extract_max', 5);
        config()->set('react.news.excerpt_chars', $excerptChars);

        $portals = Mockery::mock(NewsPortalRegistry::class);
        $portals->shouldReceive('enabledDomains')->andReturn(['lrytas.lt', '15min.lt', 'delfi.lt']);

        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchNews')->andReturn([[
            'title' => 'Baltijos siunta plečiasi kaimyninėje šalyje',
            'url' => 'https://lrytas.lt/verslas/a/1',
            'domain' => 'lrytas.lt',
            'snippet' => 'Baltijos siunta',
            'published_date' => '2026-05-01',
        ]]);

        $articles = Mockery::mock(HttpArticleClient::class);
        $articles->shouldReceive('fetch')->andReturn('<article><p>Baltijos siunta</p></article>');

        $runner = Mockery::mock(RunnerClient::class);
        $runner->shouldReceive('markdown')->andReturn($markdown);

        $result = (new NewsSource($search, $articles, $portals, new NewsQueryBuilder(), new SessionFs(), $runner))
            ->collect(['name' => 'Baltijos siunta', 'session' => 's9']);

        return $result->meta['news']['mentions'][0];
    }

    /** A captured page slice, its words replaced by invented ones of the same length. */
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . "/../Fixtures/news/{$name}.md");
    }
}
