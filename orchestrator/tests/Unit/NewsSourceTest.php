<?php

namespace Tests\Unit;

use App\Services\NewsPortalRegistry;
use App\Services\NewsQueryBuilder;
use App\IO\RunnerClient;
use App\Services\NewsSource;
use App\Tools\HttpArticleClient;
use App\Tools\SearxngSearchService;
use App\Tools\SessionFs;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class NewsSourceTest extends TestCase
{
    /** @var array<int, string> Every query string the source actually issued. */
    private array $searchedQueries = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * The whitelist now comes from the DB via NewsPortalRegistry; stub it so these
     * stay unit tests (no database, no seeded rows).
     */
    private function portals(): NewsPortalRegistry
    {
        $portals = Mockery::mock(NewsPortalRegistry::class);
        $portals->shouldReceive('enabledDomains')->andReturn([
            'delfi.lt', '15min.lt', 'lrytas.lt', 'lrt.lt', 'tv3.lt', 'bns.lt',
        ])->byDefault();

        return $portals;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @param array<string, ?string> $articleHtml url => html returned by the fetcher
     */
    private function source(array $results, array $articleHtml = []): NewsSource
    {
        config()->set('react.news.enabled', true);
        config()->set('react.news.max_mentions', 8);
        config()->set('react.news.freshness_months', 12);
        config()->set('react.news.strong_portal_count', 3);
        config()->set('react.news.fetch_dates', true);

        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchNews')->andReturnUsing(
            function (string $query) use ($results): array {
                $this->searchedQueries[] = $query;

                return $results;
            }
        );

        $articles = Mockery::mock(HttpArticleClient::class);
        $articles->shouldReceive('fetch')->andReturnUsing(
            static fn (string $url): ?string => $articleHtml[$url] ?? null
        )->byDefault();

        return new NewsSource($search, $articles, $this->portals(), new NewsQueryBuilder(), new SessionFs(), $this->inertRunner());
    }

    /**
     * Article text extraction is off by default in these tests: they cover the search,
     * gate and scoring layers, and a runner that returns nothing leaves every existing
     * assertion measuring exactly what it measured before. The extraction path has its
     * own tests below.
     */
    private function inertRunner(): RunnerClient
    {
        $runner = Mockery::mock(RunnerClient::class);
        $runner->shouldReceive('markdown')->andReturn(null)->byDefault();

        return $runner;
    }

    public function test_keeps_only_on_topic_whitelisted_news_and_scores(): void
    {
        Carbon::setTestNow('2026-07-15');
        $r = $this->source([
            // on-topic, whitelisted, fresh
            ['title' => 'Azerna plečia parką', 'url' => 'https://delfi.lt/a/1', 'domain' => 'delfi.lt', 'snippet' => 'Azerna', 'published_date' => '2026-05-01'],
            // on-topic, whitelisted, second portal
            ['title' => 'Azerna investuoja', 'url' => 'https://15min.lt/b/2', 'domain' => '15min.lt', 'snippet' => '', 'published_date' => '2026-04-01'],
            // whitelisted but OFF-topic (different company)
            ['title' => 'Kita įmonė', 'url' => 'https://lrytas.lt/c/3', 'domain' => 'lrytas.lt', 'snippet' => 'nieko', 'published_date' => '2026-06-01'],
            // on-topic but NOT a news portal (ignored)
            ['title' => 'Azerna', 'url' => 'https://scoris.lt/imone', 'domain' => 'scoris.lt', 'snippet' => 'Azerna', 'published_date' => '2026-06-01'],
            // duplicate URL of #1
            ['title' => 'Azerna plečia parką', 'url' => 'https://delfi.lt/a/1', 'domain' => 'delfi.lt', 'snippet' => 'Azerna', 'published_date' => '2026-05-01'],
        ]);

        $res = $r->collect(['name' => 'Azerna']);

        $this->assertSame('ok', $res->status);
        $this->assertSame([], $res->fields);
        $news = $res->meta['news'];
        $portals = array_column($news['mentions'], 'portal');
        $this->assertEqualsCanonicalizing(['delfi.lt', '15min.lt'], $portals); // off-topic + non-news + dup dropped
        $this->assertTrue($news['fresh']);
        // 2 distinct portals / 3 strong = 0.667 * fresh(1.0)
        $this->assertEqualsWithDelta(0.667, $news['score'], 0.001);
    }

    public function test_results_with_no_portal_hit_are_an_error_not_an_empty_result(): void
    {
        // Every query is `site:`-restricted to the whitelist, so results that are ALL
        // off-portal mean the filter was ignored (the engines that honour it were
        // suspended). No portal was searched: that is "could not see", not "nobody wrote".
        $res = $this->source([
            ['title' => 'Azerna', 'url' => 'https://scoris.lt/x', 'domain' => 'scoris.lt', 'snippet' => 'Azerna', 'published_date' => null],
        ])->collect(['name' => 'Azerna']);

        $this->assertSame('error', $res->status);
        $this->assertSame(NewsSource::SEARCH_OFF_PORTAL, $res->meta['news']['search_state']);
        $this->assertStringContainsString('ignored the portal filter', $res->meta['detail']);
        $this->assertSame([], $res->meta['news']['mentions']);
        $this->assertSame(0.0, $res->meta['news']['score']);
    }

    public function test_one_portal_hit_among_off_portal_results_keeps_the_search_answered(): void
    {
        // A portal page WAS seen and judged off topic: the search worked, so this stays
        // a real "nothing about this company" however much off-portal noise came with it.
        $res = $this->source([
            ['title' => 'Azerna', 'url' => 'https://scoris.lt/x', 'domain' => 'scoris.lt', 'snippet' => 'Azerna', 'published_date' => null],
            ['title' => 'Visai kita įmonė', 'url' => 'https://delfi.lt/x/1', 'domain' => 'delfi.lt', 'snippet' => 'nieko', 'published_date' => '2026-06-01'],
        ])->collect(['name' => 'Azerna']);

        $this->assertSame('empty', $res->status);
        $this->assertSame(NewsSource::SEARCH_ANSWERED, $res->meta['news']['search_state']);
    }

    public function test_skipped_when_disabled(): void
    {
        config()->set('react.news.enabled', false);
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldNotReceive('searchNews');
        $articles = Mockery::mock(HttpArticleClient::class);
        $articles->shouldNotReceive('fetch');

        $res = (new NewsSource($search, $articles, $this->portals(), new NewsQueryBuilder(), new SessionFs(), $this->inertRunner()))->collect(['name' => 'Azerna']);

        $this->assertSame('skipped', $res->status);
    }

    public function test_issues_one_query_per_portal_group_and_tags_company_subject(): void
    {
        Carbon::setTestNow('2026-07-15');
        config()->set('react.news.portals_per_query', 3);
        $res = $this->source([
            ['title' => 'Azerna plečia', 'url' => 'https://delfi.lt/a/1', 'domain' => 'delfi.lt', 'snippet' => 'Azerna', 'published_date' => '2026-05-01'],
        ])->collect(['name' => 'Azerna']);

        // 6 stubbed portals / 3 per query = 2 site-scoped queries
        $this->assertCount(2, $this->searchedQueries);
        $this->assertStringContainsString('site:delfi.lt', $this->searchedQueries[0]);
        $this->assertSame('company', $res->meta['news']['mentions'][0]['subject']);
    }

    public function test_duplicate_urls_across_queries_are_kept_once(): void
    {
        Carbon::setTestNow('2026-07-15');
        config()->set('react.news.portals_per_query', 3);
        // The stub answers every query with the same hit; dedupe must collapse it.
        $res = $this->source([
            ['title' => 'Azerna plečia', 'url' => 'https://delfi.lt/a/1', 'domain' => 'delfi.lt', 'snippet' => 'Azerna', 'published_date' => '2026-05-01'],
        ])->collect(['name' => 'Azerna']);

        $this->assertCount(1, $res->meta['news']['mentions']);
    }

    public function test_skipped_when_the_whitelist_is_empty(): void
    {
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldNotReceive('searchNews');
        $articles = Mockery::mock(HttpArticleClient::class);
        $portals = Mockery::mock(NewsPortalRegistry::class);
        $portals->shouldReceive('enabledDomains')->andReturn([]);

        $res = (new NewsSource($search, $articles, $portals, new NewsQueryBuilder(), new SessionFs(), $this->inertRunner()))->collect(['name' => 'Azerna']);

        // An admin disabling every portal means "don't search news" — never a
        // fallback to an unbounded search.
        $this->assertSame('skipped', $res->status);
        $this->assertSame('no enabled news portals', $res->meta['detail']);
    }

    public function test_skipped_with_a_distinct_reason_when_the_name_has_no_searchable_tokens(): void
    {
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldNotReceive('searchNews');
        $articles = Mockery::mock(HttpArticleClient::class);

        // "UAB" is a legal form only — no distinctive token to search for. The
        // reason must not be misreported as an empty whitelist.
        $res = (new NewsSource($search, $articles, $this->portals(), new NewsQueryBuilder(), new SessionFs(), $this->inertRunner()))->collect(['name' => 'UAB']);

        $this->assertSame('skipped', $res->status);
        $this->assertSame('no searchable company name', $res->meta['detail']);
    }

    /**
     * A source whose stub answers each query differently, so company and person
     * results can be told apart.
     *
     * @param array<string, array<int, array<string, mixed>>> $byQueryNeedle
     */
    private function sourcePerQuery(array $byQueryNeedle): NewsSource
    {
        config()->set('react.news.enabled', true);
        config()->set('react.news.max_mentions', 8);
        config()->set('react.news.freshness_months', 12);
        config()->set('react.news.strong_portal_count', 3);
        config()->set('react.news.fetch_dates', false);
        config()->set('react.news.time_budget_s', 45);

        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchNews')->andReturnUsing(
            function (string $query) use ($byQueryNeedle): array {
                $this->searchedQueries[] = $query;

                foreach ($byQueryNeedle as $needle => $results) {
                    if (str_contains($query, $needle)) {
                        return $results;
                    }
                }

                return [];
            }
        );

        $articles = Mockery::mock(HttpArticleClient::class);
        $articles->shouldReceive('fetch')->andReturn(null)->byDefault();

        return new NewsSource($search, $articles, $this->portals(), new NewsQueryBuilder(), new SessionFs(), $this->inertRunner());
    }

    public function test_person_mention_is_tagged_with_the_person_name(): void
    {
        Carbon::setTestNow('2026-07-15');
        config()->set('react.news.portals_per_query', 6);   // 1 company query

        $res = $this->sourcePerQuery([
            'Vardas Pavardė' => [
                ['title' => 'Vardas Pavardė apie Azerna planus', 'url' => 'https://delfi.lt/p/9', 'domain' => 'delfi.lt', 'snippet' => 'Azerna', 'published_date' => '2026-06-01'],
            ],
            'site:' => [
                ['title' => 'Azerna plečia parką', 'url' => 'https://15min.lt/c/1', 'domain' => '15min.lt', 'snippet' => 'Azerna', 'published_date' => '2026-05-01'],
            ],
        ])->collect([
            'name' => 'Azerna',
            'people' => [['name' => 'Vardas Pavardė', 'role' => 'Direktorius']],
        ]);

        // 1 company group + 2 forms for the one person = 3 queries: paired with the
        // company, and name-only scoped to the portals.
        $this->assertCount(3, $this->searchedQueries);
        $this->assertSame('"Vardas Pavardė" "Azerna"', $this->searchedQueries[1]);
        $this->assertStringStartsWith('"Vardas Pavardė" (site:', $this->searchedQueries[2]);

        $bySubject = [];
        foreach ($res->meta['news']['mentions'] as $mention) {
            $bySubject[$mention['subject']] = $mention;
        }
        $this->assertArrayHasKey('company', $bySubject);
        $this->assertArrayHasKey('person', $bySubject);
        $this->assertSame('Vardas Pavardė', $bySubject['person']['person']);
        $this->assertNull($bySubject['company']['person']);
    }

    public function test_an_article_found_by_both_queries_is_claimed_by_the_person(): void
    {
        Carbon::setTestNow('2026-07-15');
        config()->set('react.news.portals_per_query', 6);

        // The same URL satisfies both queries and is stored once. It is tagged to the
        // person, not to whichever query happened to run first.
        //
        // Company queries are always issued before person ones, so "first wins" meant
        // the person could never claim anything: measured on real runs, all 8 of
        // Didmena's mentions came back tagged `company` and the person layer looked
        // empty even with two directors supplied. The person is also the more specific
        // fact — "the director said this" beats "the company was mentioned".
        $shared = [
            ['title' => 'Vardas Pavardė apie Azerna', 'url' => 'https://delfi.lt/s/1', 'domain' => 'delfi.lt', 'snippet' => 'Azerna', 'published_date' => '2026-06-01'],
        ];
        $res = $this->sourcePerQuery(['Vardas Pavardė' => $shared, 'site:' => $shared])->collect([
            'name' => 'Azerna',
            'people' => [['name' => 'Vardas Pavardė']],
        ]);

        $this->assertCount(1, $res->meta['news']['mentions']);
        $this->assertSame('person', $res->meta['news']['mentions'][0]['subject']);
        $this->assertSame('Vardas Pavardė', $res->meta['news']['mentions'][0]['person']);
    }

    public function test_person_article_not_naming_the_company_is_dropped(): void
    {
        Carbon::setTestNow('2026-07-15');
        config()->set('react.news.portals_per_query', 6);

        // The company query finds nothing; the person query finds an article about
        // the director that never mentions the company ("bėgo maratoną"). It must not
        // be stored — that is the scoring rule, enforced by the on-topic gate.
        $res = $this->sourcePerQuery([
            'Vardas Pavardė' => [
                ['title' => 'Vardas Pavardė bėgo maratoną', 'url' => 'https://delfi.lt/m/1', 'domain' => 'delfi.lt', 'snippet' => 'sportas', 'published_date' => '2026-06-01'],
            ],
        ])->collect(['name' => 'Azerna', 'people' => [['name' => 'Vardas Pavardė']]]);

        $this->assertSame('empty', $res->status);
        $this->assertSame([], $res->meta['news']['mentions']);
        // Proves the gate did the dropping — not that the person query was never
        // issued in the first place.
        $this->assertContains('"Vardas Pavardė" "Azerna"', $this->searchedQueries);
    }

    public function test_no_person_queries_without_people(): void
    {
        config()->set('react.news.portals_per_query', 6);
        $this->source([])->collect(['name' => 'Azerna']);

        $this->assertCount(1, $this->searchedQueries);   // company only, no guessing
    }

    public function test_stops_issuing_queries_once_the_time_budget_is_spent(): void
    {
        config()->set('react.news.enabled', true);
        config()->set('react.news.max_mentions', 8);
        config()->set('react.news.portals_per_query', 1);   // 6 stubbed portals -> 6 queries
        config()->set('react.news.max_queries', 8);
        config()->set('react.news.time_budget_s', 0);       // budget already spent

        $search = Mockery::mock(SearxngSearchService::class);
        // The first query always runs; the exhausted budget must stop the other five.
        $search->shouldReceive('searchNews')->once()->andReturn([]);
        $articles = Mockery::mock(HttpArticleClient::class);
        $articles->shouldNotReceive('fetch');

        $res = (new NewsSource($search, $articles, $this->portals(), new NewsQueryBuilder(), new SessionFs(), $this->inertRunner()))
            ->collect(['name' => 'Azerna']);

        // The one query we managed to issue returned nothing, so we did not see — and a
        // run cut short by our own budget is retryable, never the firm's data ceiling.
        $this->assertSame('error', $res->status);
    }

    public function test_date_extracted_from_url_when_no_published_date(): void
    {
        Carbon::setTestNow('2026-07-15');
        // LRT-style dated URL (/YYYY/MM/DD/); no published_date from SearXNG.
        $res = $this->source([
            ['title' => 'Azerna naujiena', 'url' => 'https://www.lrt.lt/naujienos/verslas/x/2026/05/01/azerna-plecia', 'domain' => 'lrt.lt', 'snippet' => 'Azerna', 'published_date' => null],
        ])->collect(['name' => 'Azerna']);

        $this->assertSame('2026-05-01', $res->meta['news']['mentions'][0]['date']);
        $this->assertTrue($res->meta['news']['fresh']);
    }

    public function test_id_based_urls_get_date_from_fetched_article_page(): void
    {
        Carbon::setTestNow('2026-07-15');
        // tv3/15min URLs are ID-based (no date in path). With no SearXNG date and
        // no URL date, NewsSource fetches the article page and reads its meta date.
        $tv3 = 'https://www.tv3.lt/naujiena/verslas/azerna-n1178242';
        $min = 'https://www.15kak.lt/zašopas/naujiena/bendroves/azerna-663-1584212';
        $res = $this->source(
            [
                ['title' => 'Azerna x', 'url' => $tv3, 'domain' => 'tv3.lt', 'snippet' => 'Azerna', 'published_date' => null],
                ['title' => 'Azerna y', 'url' => $min, 'domain' => '15min.lt', 'snippet' => 'Azerna', 'published_date' => null],
            ],
            [
                $tv3 => '<html><head><meta property="article:published_time" content="2026-06-02T09:00:00+03:00"></head></html>',
                // 15min: no date in page either -> stays null
                $min => '<html><head><title>x</title></head></html>',
            ],
        )->collect(['name' => 'Azerna']);

        $byUrl = [];
        foreach ($res->meta['news']['mentions'] as $m) {
            $byUrl[$m['url']] = $m['date'];
        }
        $this->assertSame('2026-06-02', $byUrl[$tv3]);   // scraped from the article page
        $this->assertNull($byUrl[$min]);                 // no date anywhere
        $this->assertTrue($res->meta['news']['fresh']);  // the scraped date is recent
    }

    public function test_no_article_is_fetched_when_both_dates_and_text_are_disabled(): void
    {
        // fetch_dates alone no longer means "never download an article": text extraction
        // has its own switch and its own reason to fetch. Turning both off is what buys
        // a run with no third-party article requests at all.
        Carbon::setTestNow('2026-07-15');
        config()->set('react.news.fetch_dates', false);
        config()->set('react.news.extract_text', false);

        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchNews')->andReturn([
            ['title' => 'Azerna x', 'url' => 'https://www.tv3.lt/n/azerna-n1178242', 'domain' => 'tv3.lt', 'snippet' => 'Azerna', 'published_date' => null],
        ]);
        $articles = Mockery::mock(HttpArticleClient::class);
        $articles->shouldNotReceive('fetch');

        $res = (new NewsSource($search, $articles, $this->portals(), new NewsQueryBuilder(), new SessionFs(), $this->inertRunner()))->collect(['name' => 'Azerna']);

        $this->assertNull($res->meta['news']['mentions'][0]['date']);
    }

    public function test_old_only_mentions_dampen_score(): void
    {
        Carbon::setTestNow('2026-07-15');
        $res = $this->source([
            ['title' => 'Azerna senai', 'url' => 'https://delfi.lt/a/1', 'domain' => 'delfi.lt', 'snippet' => '', 'published_date' => '2020-01-01'],
        ])->collect(['name' => 'Azerna']);

        $this->assertFalse($res->meta['news']['fresh']);
        // 1 portal / 3 = 0.333 * old(0.6) = 0.2
        $this->assertEqualsWithDelta(0.2, $res->meta['news']['score'], 0.001);
    }

    public function test_article_text_is_extracted_into_an_excerpt_and_stored_as_artifacts(): void
    {
        // The reason this layer exists: a mention carries the article's own words, not
        // just a search snippet. The full text goes to disk beside the captured HTML;
        // the profile keeps a bounded excerpt because it is returned over HTTP on every
        // run.
        //
        // The converted page has a real capture's structure (tests/Fixtures/news/README.md), because
        // the excerpt rule is only worth what it does to pages portals really send.
        // ArticleExcerptTest measures where the excerpt starts on each portal; this pins
        // the budget and the artifacts around it.
        Carbon::setTestNow('2026-07-15');
        Storage::fake('local');
        Storage::disk('local')->put('runs/s9/baltijossiunta.lt/analyze/main.md', 'x');
        config()->set('react.news.excerpt_chars', 120);

        $page = (string) file_get_contents(__DIR__ . '/../Fixtures/news/lrytas-logistikos-centras.md');

        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchNews')->andReturn([
            ['title' => 'Baltijos siunta plečiasi kaimyninėje šalyje', 'url' => 'https://lrytas.lt/a/1', 'domain' => 'lrytas.lt', 'snippet' => 'Baltijos siunta', 'published_date' => '2026-05-01'],
        ]);
        $articles = Mockery::mock(HttpArticleClient::class);
        $articles->shouldReceive('fetch')->andReturn('<article><h1>Baltijos siunta</h1><p>Visas tekstas.</p></article>');

        $runner = Mockery::mock(RunnerClient::class);
        $runner->shouldReceive('markdown')->once()->andReturn($page);

        $res = (new NewsSource($search, $articles, $this->portals(), new NewsQueryBuilder(), new SessionFs(), $runner))
            ->collect(['name' => 'Baltijos siunta', 'session' => 's9']);

        $mention = $res->meta['news']['mentions'][0];

        $this->assertSame(NewsSource::EXCERPT_BODY, $mention['excerpt_state']);
        $this->assertSame(
            'Yšužūvos višoš mėdūsmas avėpoų etošūatymo ūzolėas „Ėnydūnd“ Edypejoje ašėrėrė uzašūk 2 mln. obūl tėlūps ičyzotikos',
            $mention['excerpt'],
        );
        $this->assertLessThanOrEqual(120, mb_strlen($mention['excerpt']), 'at most excerpt_chars characters');
        $this->assertSame('runs/s9/baltijossiunta.lt/analyze/news/baltijos-siunta-ple-iasi-kaimynin-je-alyje.md', $mention['artifacts']['markdown']);
        Storage::disk('local')->assertExists($mention['artifacts']['html']);
        $this->assertSame($page, Storage::disk('local')->get($mention['artifacts']['markdown']));
    }

    public function test_a_mention_survives_when_the_text_cannot_be_extracted(): void
    {
        // Paywalled or JS-only articles convert to nothing. Losing the text must never
        // lose the mention that found it.
        Carbon::setTestNow('2026-07-15');
        Storage::fake('local');

        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchNews')->andReturn([
            ['title' => 'Azerna plečia', 'url' => 'https://delfi.lt/a/1', 'domain' => 'delfi.lt', 'snippet' => 'Azerna', 'published_date' => '2026-05-01'],
        ]);
        $articles = Mockery::mock(HttpArticleClient::class);
        $articles->shouldReceive('fetch')->andReturn('<div id="app"></div>');

        $runner = Mockery::mock(RunnerClient::class);
        $runner->shouldReceive('markdown')->andReturn(null);

        $res = (new NewsSource($search, $articles, $this->portals(), new NewsQueryBuilder(), new SessionFs(), $runner))
            ->collect(['name' => 'Azerna', 'session' => 's9']);

        $this->assertCount(1, $res->meta['news']['mentions']);
        $this->assertArrayNotHasKey('excerpt', $res->meta['news']['mentions'][0]);
    }

    public function test_a_search_that_returns_nothing_at_all_is_an_error_not_an_empty_result(): void
    {
        // SearXNG answers HTTP 200 with an empty list when its upstream engines are
        // rate-limited, which used to look exactly like a company nobody wrote about.
        // Observed live: the same query returned 10 results one minute and 0 the next,
        // and every measurement built on that could not tell the two apart.
        $res = $this->source([])->collect(['name' => 'Azerna']);

        $this->assertSame('error', $res->status);
        $this->assertSame('search returned no results for any query', $res->meta['detail']);
        $this->assertSame([], $res->meta['news']['mentions']);
    }

    public function test_results_that_are_all_off_topic_stay_empty(): void
    {
        // Here the search worked and we judged what it returned. That is a real
        // "nothing about this company", and it must not be reported as a failure.
        $res = $this->source([
            ['title' => 'Visai kita įmonė', 'url' => 'https://delfi.lt/x/1', 'domain' => 'delfi.lt', 'snippet' => 'nieko', 'published_date' => '2026-06-01'],
        ])->collect(['name' => 'Azerna']);

        $this->assertSame('empty', $res->status);
        $this->assertArrayNotHasKey('detail', $res->meta);
    }

    public function test_meta_names_the_people_searched_and_the_people_that_did_not_fit(): void
    {
        config()->set('react.news.portals_per_query', 7);
        config()->set('react.news.max_queries', 3);
        config()->set('react.news.max_people', 3);

        $res = $this->source([
            ['title' => 'Azerna plečia parką', 'url' => 'https://delfi.lt/a/1', 'domain' => 'delfi.lt', 'snippet' => 'Azerna', 'published_date' => '2026-05-01'],
        ])->collect([
            'name' => 'Azerna',
            'people' => [['name' => 'Pirmas Vardenis'], ['name' => 'Antras Vardenis']],
        ]);

        // 6 stubbed portals = 1 company group, leaving 2 slots = both forms of person 1.
        $this->assertSame(['Pirmas Vardenis'], $res->meta['news']['people_searched']);
        $this->assertSame(['Antras Vardenis'], $res->meta['news']['people_dropped']);
    }

    public function test_the_people_report_survives_a_run_that_found_nothing(): void
    {
        // The audit trail matters most exactly when the result is empty: "we searched
        // about this director and found nothing" is a different fact from "we never
        // searched about him", and collection_actions stores this meta either way.
        $res = $this->source([
            ['title' => 'Kita įmonė', 'url' => 'https://delfi.lt/x', 'domain' => 'delfi.lt', 'snippet' => 'nieko', 'published_date' => null],
        ])->collect([
            'name' => 'Azerna',
            'people' => [['name' => 'Pirmas Vardenis']],
        ]);

        $this->assertSame('empty', $res->status);
        // The search answered and brought results back to judge; they were simply about
        // someone else. Nothing is uncertain here, so "no mentions found" is a claim this
        // run can support — the state the console needs to keep saying it.
        $this->assertSame(NewsSource::SEARCH_ANSWERED, $res->meta['news']['search_state']);
        $this->assertSame(['Pirmas Vardenis'], $res->meta['news']['people_searched']);
        $this->assertSame([], $res->meta['news']['people_dropped']);
        $this->assertSame([], $res->meta['news']['people_dropped_reasons']);
    }

    public function test_a_search_answered_with_nothing_at_all_still_credits_the_people_it_asked_about(): void
    {
        // NOT "we never looked": every query here WAS answered, with an empty list each
        // time — the rate-limited/CAPTCHA-suspended signature. So the person really was
        // asked about and belongs in people_searched, while what stays unknown is whether
        // any mention exists. The `error` status alone cannot say which of those two it is,
        // and a console that guessed printed "the search went unanswered" beside this very
        // person's "ieškota naujienų" badge. search_state is what separates them, and the
        // plan decided BEFORE the backend answered must still be named either way.
        config()->set('react.news.portals_per_query', 7);
        config()->set('react.news.max_queries', 3);
        config()->set('react.news.max_people', 3);

        $res = $this->source([])->collect([
            'name' => 'Azerna',
            'people' => [['name' => 'Pirmas Vardenis'], ['name' => 'Antras Vardenis']],
        ]);

        // Same query budget as the ok-branch test above: 1 company group + both
        // forms of person 1 = 3 queries, all stubbed to return nothing at all.
        $this->assertSame('error', $res->status);
        // The two facts inside one status, told apart: answered, and answered with nothing.
        $this->assertSame(NewsSource::SEARCH_RETURNED_NOTHING, $res->meta['news']['search_state']);
        $this->assertSame('search returned no results for any query', $res->meta['detail']);
        $this->assertSame(['Pirmas Vardenis'], $res->meta['news']['people_searched']);
        $this->assertSame(['Antras Vardenis'], $res->meta['news']['people_dropped']);
        $this->assertSame(
            [['name' => 'Antras Vardenis', 'reason' => 'query_budget']],
            $res->meta['news']['people_dropped_reasons'],
        );
    }

    public function test_a_person_the_time_budget_never_reached_is_not_reported_as_searched(): void
    {
        // The defect: `people_searched` was derived from the PLAN, so a person whose two
        // queries the wall clock never let out was still reported as searched. An operator
        // reading "searched, no mentions" would conclude nobody writes about that director
        // — the exact false claim ("we looked") this report exists to remove.
        config()->set('react.news.portals_per_query', 7);   // 6 stubbed portals -> 1 company group
        config()->set('react.news.max_queries', 3);         // company + both forms of person 1
        config()->set('react.news.max_people', 3);
        config()->set('react.news.time_budget_s', 0);        // spent the moment the first query returns

        $res = $this->source([
            ['title' => 'Azerna plečia parką', 'url' => 'https://delfi.lt/a/1', 'domain' => 'delfi.lt', 'snippet' => 'Azerna', 'published_date' => '2026-05-01'],
        ])->collect([
            'name' => 'Azerna',
            'people' => [['name' => 'Pirmas Vardenis'], ['name' => 'Antras Vardenis']],
        ]);

        // Only the company query was ever issued — nobody was asked about by name.
        $this->assertCount(1, $this->searchedQueries);
        $this->assertStringNotContainsString('Pirmas Vardenis', $this->searchedQueries[0]);

        $this->assertSame('ok', $res->status);
        $this->assertSame([], $res->meta['news']['people_searched']);
        $this->assertSame(['Pirmas Vardenis', 'Antras Vardenis'], $res->meta['news']['people_dropped']);
        // Both reasons in one run, because they call for different fixes: a slow backend
        // versus a query ceiling that never had room for the second name.
        $this->assertSame([
            ['name' => 'Pirmas Vardenis', 'reason' => 'time_budget'],
            ['name' => 'Antras Vardenis', 'reason' => 'query_budget'],
        ], $res->meta['news']['people_dropped_reasons']);
    }

    public function test_a_slow_backend_still_credits_the_person_it_managed_to_ask_about(): void
    {
        // A real slow backend, not an already-spent budget: the company query answers at
        // once, the first person query burns past the whole budget, and the loop stops
        // there. This is the condition QA hit live with CAPTCHA-blocked SearXNG engines.
        config()->set('react.news.enabled', true);
        config()->set('react.news.max_mentions', 8);
        config()->set('react.news.fetch_dates', false);
        config()->set('react.news.extract_text', false);
        config()->set('react.news.portals_per_query', 7);   // 6 stubbed portals -> 1 company group
        config()->set('react.news.max_queries', 5);         // company + both forms of 2 people
        config()->set('react.news.max_people', 3);
        config()->set('react.news.time_budget_s', 1);

        $calls = 0;
        $search = Mockery::mock(SearxngSearchService::class);
        $search->shouldReceive('searchNews')->andReturnUsing(function (string $query) use (&$calls): array {
            $this->searchedQueries[] = $query;
            if (++$calls === 2) {
                usleep(1_200_000);   // one query outlives the whole 1s budget
            }

            return [
                ['title' => 'Azerna plečia parką', 'url' => "https://delfi.lt/a/{$calls}", 'domain' => 'delfi.lt', 'snippet' => 'Azerna', 'published_date' => '2026-05-01'],
            ];
        });
        $articles = Mockery::mock(HttpArticleClient::class);
        $articles->shouldReceive('fetch')->andReturn(null)->byDefault();

        $res = (new NewsSource($search, $articles, $this->portals(), new NewsQueryBuilder(), new SessionFs(), $this->inertRunner()))
            ->collect([
                'name' => 'Azerna',
                'people' => [['name' => 'Pirmas Vardenis'], ['name' => 'Antras Vardenis'], ['name' => 'Trecias Vardenis']],
            ]);

        $this->assertCount(2, $this->searchedQueries);   // company + the first form of person 1
        // One issued query is enough to have looked: person 1 keeps the credit even
        // though his second form never went out. Person 2 was planned and never asked
        // about; person 3 never fitted the query ceiling in the first place.
        $this->assertSame(['Pirmas Vardenis'], $res->meta['news']['people_searched']);
        $this->assertSame(['Antras Vardenis', 'Trecias Vardenis'], $res->meta['news']['people_dropped']);
        $this->assertSame([
            ['name' => 'Antras Vardenis', 'reason' => 'time_budget'],
            ['name' => 'Trecias Vardenis', 'reason' => 'query_budget'],
        ], $res->meta['news']['people_dropped_reasons']);
    }

    public function test_a_query_the_client_refused_locally_is_not_reported_as_searched(): void
    {
        // The narrower hole beside the wall clock. SearxngSearchService refuses a query
        // when its own one-minute window is spent — instantly, without touching the
        // network — and that window is global and shared with domain discovery, so it is
        // spent exactly when the engines are already degraded. The wall clock never fires,
        // so nothing else in the report hints that this person went unasked.
        //
        // The real client over a mocked transport, not a stub: the claim is that no HTTP
        // request naming the person was made, and only the transport can prove that.
        Carbon::setTestNow('2026-08-14 10:00:30');           // one throttle window, no rollover
        config()->set('react.news.enabled', true);
        config()->set('react.news.max_mentions', 8);
        config()->set('react.news.fetch_dates', false);
        config()->set('react.news.extract_text', false);
        config()->set('react.news.portals_per_query', 7);    // 6 stubbed portals -> 1 company group
        config()->set('react.news.max_queries', 3);          // company + both forms of person 1
        config()->set('react.news.max_people', 3);
        config()->set('react.news.time_budget_s', 45);       // the wall clock must NOT be the cause
        config()->set('services.searxng.url', 'http://searxng:8080');
        config()->set('services.searxng.max_per_minute', 1); // only the company query gets through

        // Three responses staged on purpose: a client that did not refuse would consume
        // all three, so this fails unless the refusal is real.
        $body = json_encode(['results' => [
            ['title' => 'Azerna plečia parką', 'url' => 'https://delfi.lt/a/1', 'content' => 'Azerna', 'publishedDate' => '2026-05-01T00:00:00'],
        ]], JSON_THROW_ON_ERROR);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], $body),
            new Response(200, [], $body),
            new Response(200, [], $body),
        ]));
        $stack->push(Middleware::history($history));

        $articles = Mockery::mock(HttpArticleClient::class);
        $articles->shouldReceive('fetch')->andReturn(null)->byDefault();

        $res = (new NewsSource(
            new SearxngSearchService(new Client(['handler' => $stack])),
            $articles,
            $this->portals(),
            new NewsQueryBuilder(),
            new SessionFs(),
            $this->inertRunner(),
        ))->collect([
            'name' => 'Azerna',
            'people' => [['name' => 'Pirmas Vardenis'], ['name' => 'Antras Vardenis']],
        ]);

        // Exactly one request left the process, and it was the company query.
        $this->assertCount(1, $history);
        $this->assertStringNotContainsString('Pirmas', urldecode($history[0]['request']->getUri()->getQuery()));

        // The company query answered, so this is a perfectly healthy-looking `ok` run —
        // the status cannot rescue the claim here, only the report can.
        $this->assertSame('ok', $res->status);
        $this->assertSame([], $res->meta['news']['people_searched']);
        $this->assertSame(['Pirmas Vardenis', 'Antras Vardenis'], $res->meta['news']['people_dropped']);
        $this->assertSame([
            ['name' => 'Pirmas Vardenis', 'reason' => 'search_unanswered'],
            ['name' => 'Antras Vardenis', 'reason' => 'query_budget'],
        ], $res->meta['news']['people_dropped_reasons']);
    }

    public function test_a_run_nothing_answered_says_so_instead_of_blaming_the_search(): void
    {
        // "search returned no results" would describe a search that happened. None did:
        // the caller retries either way, but only this one is worth waiting a rate-limit
        // window out for, and only this one means our own budget cost us the run.
        Carbon::setTestNow('2026-08-14 11:00:30');
        config()->set('react.news.enabled', true);
        config()->set('react.news.max_mentions', 8);
        config()->set('react.news.portals_per_query', 7);
        config()->set('react.news.max_queries', 3);
        config()->set('react.news.time_budget_s', 45);
        config()->set('services.searxng.url', '');           // no endpoint: every query refused

        $articles = Mockery::mock(HttpArticleClient::class);
        $articles->shouldNotReceive('fetch');

        $res = (new NewsSource(
            new SearxngSearchService(),
            $articles,
            $this->portals(),
            new NewsQueryBuilder(),
            new SessionFs(),
            $this->inertRunner(),
        ))->collect(['name' => 'Azerna', 'people' => [['name' => 'Pirmas Vardenis']]]);

        $this->assertSame('error', $res->status);
        $this->assertSame('no query was answered by the search backend', $res->meta['detail']);
        // The other half of the `error` status: nothing answered, so no mention claim of
        // any kind can be made, and the report says which of the two happened.
        $this->assertSame(NewsSource::SEARCH_UNANSWERED, $res->meta['news']['search_state']);
        $this->assertSame([], $res->meta['news']['people_searched']);
        $this->assertSame([['name' => 'Pirmas Vardenis', 'reason' => 'search_unanswered']], $res->meta['news']['people_dropped_reasons']);
    }

    public function test_a_backend_that_errors_on_the_person_query_does_not_credit_that_person(): void
    {
        // The third door: the query left the process and SearXNG answered 502, so nothing
        // was searched about this person either. The company query answered normally, so
        // the run is `ok` and again only the report can carry the truth.
        Carbon::setTestNow('2026-08-14 12:00:30');
        config()->set('react.news.enabled', true);
        config()->set('react.news.max_mentions', 8);
        config()->set('react.news.fetch_dates', false);
        config()->set('react.news.extract_text', false);
        config()->set('react.news.portals_per_query', 7);   // 6 stubbed portals -> 1 company group
        config()->set('react.news.max_queries', 3);         // company + both forms of the person
        config()->set('react.news.time_budget_s', 45);
        config()->set('services.searxng.url', 'http://searxng:8080');
        config()->set('services.searxng.max_per_minute', 0);   // the throttle is NOT the cause

        $body = json_encode(['results' => [
            ['title' => 'Azerna plečia parką', 'url' => 'https://delfi.lt/a/1', 'content' => 'Azerna', 'publishedDate' => '2026-05-01T00:00:00'],
        ]], JSON_THROW_ON_ERROR);
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], $body),            // company query
            new Response(502, [], 'bad gateway'),    // person form 1
            new Response(502, [], 'bad gateway'),    // person form 2
        ]));

        $articles = Mockery::mock(HttpArticleClient::class);
        $articles->shouldReceive('fetch')->andReturn(null)->byDefault();

        $res = (new NewsSource(
            new SearxngSearchService(new Client(['handler' => $stack])),
            $articles,
            $this->portals(),
            new NewsQueryBuilder(),
            new SessionFs(),
            $this->inertRunner(),
        ))->collect(['name' => 'Azerna', 'people' => [['name' => 'Pirmas Vardenis']]]);

        $this->assertSame('ok', $res->status);
        $this->assertSame([], $res->meta['news']['people_searched']);
        $this->assertSame([['name' => 'Pirmas Vardenis', 'reason' => 'search_unanswered']], $res->meta['news']['people_dropped_reasons']);
    }

    public function test_an_unanswered_query_still_spends_the_wall_clock_budget(): void
    {
        // The wall clock measures OUR patience, so it counts queries we took up, not
        // queries that answered. Counting answers instead would let the worst case through
        // untouched: four unanswered queries that each time out after 15s answer nothing,
        // so the deadline would never be consulted and the run would cost the full minute.
        Carbon::setTestNow('2026-08-14 13:00:30');
        config()->set('react.news.enabled', true);
        config()->set('react.news.max_mentions', 8);
        config()->set('react.news.portals_per_query', 2);   // 6 stubbed portals -> 3 company groups
        config()->set('react.news.max_queries', 3);         // so the plan really holds 3 queries
        config()->set('react.news.time_budget_s', 0);       // spent once the first query returns
        config()->set('services.searxng.url', 'http://searxng:8080');
        config()->set('services.searxng.max_per_minute', 0);

        // Three staged: a loop that stopped counting unanswered queries would consume them.
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(502, [], 'bad gateway'),
            new Response(502, [], 'bad gateway'),
            new Response(502, [], 'bad gateway'),
        ]));
        $stack->push(Middleware::history($history));

        $articles = Mockery::mock(HttpArticleClient::class);
        $articles->shouldNotReceive('fetch');

        $res = (new NewsSource(
            new SearxngSearchService(new Client(['handler' => $stack])),
            $articles,
            $this->portals(),
            new NewsQueryBuilder(),
            new SessionFs(),
            $this->inertRunner(),
        ))->collect(['name' => 'Azerna']);

        $this->assertCount(1, $history);
        $this->assertSame('error', $res->status);
        $this->assertSame('no query was answered by the search backend', $res->meta['detail']);
    }

    public function test_a_person_named_zero_is_refused_by_name_and_never_queried(): void
    {
        // "0" is not a name, so no query is issued for it and it is NOT credited as
        // searched — but it is still named in the report, which is the property this test
        // has always been about: the report's truth must not depend on how a name is
        // spelled. (It used to also guard array_filter's default falsy test dropping "0"
        // out of the planned list after its queries had been built; that door is now shut
        // one layer earlier, because a value this shape never reaches the planner.)
        config()->set('react.news.portals_per_query', 7);   // 6 stubbed portals -> 1 company group
        config()->set('react.news.max_queries', 3);         // room for the company and both forms
        config()->set('react.news.max_people', 3);

        $res = $this->source([
            ['title' => 'Azerna plečia parką', 'url' => 'https://delfi.lt/a/1', 'domain' => 'delfi.lt', 'snippet' => 'Azerna', 'published_date' => '2026-05-01'],
        ])->collect(['name' => 'Azerna', 'people' => [['name' => '0']]]);

        $this->assertNotContains('"0" "Azerna"', $this->searchedQueries);
        $this->assertSame([], $res->meta['news']['people_searched']);
        $this->assertSame(['0'], $res->meta['news']['people_dropped']);
        $this->assertSame(
            [['name' => '0', 'reason' => 'unusable_name']],
            $res->meta['news']['people_dropped_reasons'],
        );
    }

    public function test_a_registry_name_with_the_role_glued_on_is_reported_unusable_not_searched(): void
    {
        // Observed live: the registry stores "Indrė Šilkūnienė, direktorė" in one column,
        // so that whole phrase was researched as a person name and the console badged her
        // green. No article contains it. The query must not go out, and the person must be
        // named as unresearched with a reason that says the value — not a budget — is why.
        config()->set('react.news.portals_per_query', 7);
        config()->set('react.news.max_queries', 4);
        config()->set('react.news.max_people', 3);

        $res = $this->source([
            ['title' => 'Azerna plečia parką', 'url' => 'https://delfi.lt/a/1', 'domain' => 'delfi.lt', 'snippet' => 'Azerna', 'published_date' => '2026-05-01'],
        ])->collect(['name' => 'Azerna', 'people' => [
            ['name' => 'Indrė Šilkūnienė, direktorė'],
            ['name' => 'Kristupas Daubys'],
        ]]);

        foreach ($this->searchedQueries as $query) {
            $this->assertStringNotContainsString('direktorė', $query);
        }

        // The unusable value did not spend the clean person's slot either.
        $this->assertSame(['Kristupas Daubys'], $res->meta['news']['people_searched']);
        $this->assertSame(
            [['name' => 'Indrė Šilkūnienė, direktorė', 'reason' => 'unusable_name']],
            $res->meta['news']['people_dropped_reasons'],
        );
    }

    public function test_the_dropped_reasons_keep_the_name_whatever_it_looks_like(): void
    {
        // A name-keyed map cannot carry this: PHP casts the key "0" to integer 0, and an
        // array holding only integer keys 0..n encodes as a JSON ARRAY, so a console
        // reading reason[name] loses the name and shows a bare reason. The records keep
        // their shape whatever the names are — which is what makes the map's one
        // documented shape safe to render.
        config()->set('react.news.portals_per_query', 7);
        config()->set('react.news.max_queries', 1);          // no room for anyone
        config()->set('react.news.max_people', 3);

        $res = $this->source([
            ['title' => 'Azerna plečia parką', 'url' => 'https://delfi.lt/a/1', 'domain' => 'delfi.lt', 'snippet' => 'Azerna', 'published_date' => '2026-05-01'],
        ])->collect(['name' => 'Azerna', 'people' => [['name' => '0']]]);

        $this->assertSame(['0'], $res->meta['news']['people_dropped']);
        // Encoded exactly as CollectorService stores it in collection_actions.meta.
        // The reason is 'unusable_name' rather than the budget: "0" is refused for what it
        // is, before any ceiling gets a say. The shape is what this test pins.
        $this->assertSame(
            '[{"name":"0","reason":"unusable_name"}]',
            json_encode($res->meta['news']['people_dropped_reasons'], JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_a_topic_listing_is_not_kept_as_a_mention(): void
    {
        $res = $this->source([
            ['title' => 'Azerna - Delfi', 'url' => 'https://delfi.lt/temos/azerna/', 'domain' => 'delfi.lt', 'snippet' => 'Azerna', 'published_date' => null],
            ['title' => 'Azerna plečia parką', 'url' => 'https://delfi.lt/a/1', 'domain' => 'delfi.lt', 'snippet' => 'Azerna', 'published_date' => '2026-05-01'],
        ])->collect(['name' => 'Azerna']);

        $this->assertSame(['https://delfi.lt/a/1'], array_column($res->meta['news']['mentions'], 'url'));
    }

    public function test_only_topic_listings_is_an_answered_search_with_no_mentions(): void
    {
        // The portal was reached, so this is "nothing written", not "could not see".
        $res = $this->source([
            ['title' => 'Azerna | Tema', 'url' => 'https://15min.lt/tema/azerna-519', 'domain' => '15min.lt', 'snippet' => 'Azerna', 'published_date' => null],
        ])->collect(['name' => 'Azerna']);

        $this->assertSame('empty', $res->status);
        $this->assertSame(NewsSource::SEARCH_ANSWERED, $res->meta['news']['search_state']);
    }
}
