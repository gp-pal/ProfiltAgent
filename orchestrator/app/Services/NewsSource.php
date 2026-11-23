<?php

namespace App\Services;

use App\Tools\CompanyNameTokens;
use App\Tools\HttpArticleClient;
use App\IO\RunnerClient;
use App\Tools\SearxngSearchService;
use App\Tools\SessionFs;
use App\Tools\WebSearchFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * News dimension: third-party LT news-portal mentions of the target company.
 * A SEPARATE signal — its result carries no profile `fields`, so the 6-field
 * coverage scorer and verdict never see it. Search-only (no runner, no LLM).
 *
 * The whitelisted portals are the query, not just a post-filter: each search is
 * `site:`-scoped to a group of them ({@see NewsQueryBuilder}), so the trusted
 * sources are targeted directly instead of hoping they surface in a generic
 * result list. The domain is still re-checked against the whitelist afterwards,
 * and the on-topic name gate still applies — for a caller-supplied person that
 * gate is what keeps an article about the director's private life out of the
 * company's record. news_score stays deterministic: distinct portals x freshness.
 *
 * Every result names the people the run asked about and the people it did not
 * ({@see self::peopleReport()}), and states what the backend itself did
 * ({@see self::searchState()}), so "we looked and found nothing" is never confused
 * with "we never looked".
 *
 * Kept articles are downloaded once each (they were already, for the publish date) and
 * their text extracted through the runner's POST /markdown — no browser, so nothing
 * here meets a bot wall. The text is stored as an artifact beside the captured HTML and
 * the mention carries only an excerpt — the start of the article's own body, located by
 * {@see ArticleExcerpt}, or none at all when the page has no body to locate.
 */
class NewsSource implements CollectionSource
{
    /** At least one query was answered and brought back something to judge. */
    public const SEARCH_ANSWERED = 'answered';

    /** Not one query got an answer, so nobody was asked anything. */
    public const SEARCH_UNANSWERED = 'unanswered';

    /**
     * Every query was answered and every answer was an empty list — the signature of
     * rate-limited or CAPTCHA-suspended upstream engines. The people were asked about
     * and are credited in `people_searched`; what is unknown is whether any mention
     * exists, which is a different fact from SEARCH_UNANSWERED.
     */
    public const SEARCH_RETURNED_NOTHING = 'returned_nothing';

    /**
     * Results came back, and not one of them was from a whitelisted portal. Every query
     * is `site:`-restricted to those portals, so an engine that honoured the filter could
     * not have produced this: the engines that do were suspended and the answer came from
     * one that ignores `site:`. Nothing a portal published was looked at, so — like
     * SEARCH_RETURNED_NOTHING — whether any mention exists is unknown.
     */
    public const SEARCH_OFF_PORTAL = 'off_portal';

    /** The article's own text was located, and `excerpt` holds the start of it. */
    public const EXCERPT_BODY = 'body';

    /**
     * The page was downloaded and converted, but no block in it reads as an article
     * body — a topic/tag page, a paywall stub, a JS-only shell. There is NO `excerpt`
     * key in this case, and that absence is deliberate: the alternative was storing the
     * portal's navigation as the mention's evidence. Absent `excerpt_state` altogether
     * means no excerpt was stored and none is claimed either way — the text was never
     * fetched or converted, or react.news.excerpt_chars left no budget to carry one.
     */
    public const EXCERPT_NO_BODY = 'no_body_found';

    public function __construct(
        private SearxngSearchService $search,
        private HttpArticleClient $articles,
        private NewsPortalRegistry $portals,
        private NewsQueryBuilder $queries,
        private SessionFs $fs,
        private RunnerClient $runner,
    ) {
    }

    public function name(): string
    {
        return 'news';
    }

    public function supports(array $target): bool
    {
        return trim((string) ($target['name'] ?? '')) !== '' && (bool) config('react.news.enabled', true);
    }

    public function collect(array $target): CollectionResult
    {
        if (! $this->supports($target)) {
            return CollectionResult::none($this->name(), 'skipped');
        }

        $max = max(1, (int) config('react.news.max_mentions', 8));
        $portals = $this->portals->enabledDomains();
        /** @var array<int, array{name: string, role?: ?string}> $people */
        $people = is_array($target['people'] ?? null) ? $target['people'] : [];
        $plan = $this->queries->build((string) $target['name'], $portals, $people);

        if ($plan['queries'] === []) {
            // Two different causes, reported apart so the audit trail is honest:
            // an admin disabled every portal, or the name carries no searchable
            // token (e.g. a bare legal form).
            return CollectionResult::none($this->name(), 'skipped', [
                'detail' => $portals === [] ? 'no enabled news portals' : 'no searchable company name',
            ]);
        }

        if ($plan['dropped'] > 0) {
            Log::info('News query plan truncated', ['dropped' => $plan['dropped']]);
        }

        $tokens = CompanyNameTokens::tokens((string) $target['name']);
        $mentions = [];
        $seen = [];
        // No lower floor: 0 legitimately means "one query only" (the first is always
        // issued), so the configured value never means something other than it says.
        $deadline = microtime(true) + max(0, (int) config('react.news.time_budget_s', 45));
        // Attempted vs issued: the wall clock is about how long WE have spent, so it
        // counts every query we took up (the first is always attempted). Only `issued`
        // means a query the search client really sent, and only that may credit a person.
        $attempted = 0;
        $issued = 0;
        $unanswered = 0;
        // Raw results across every issued query, before any filtering. Zero of these
        // means the search itself gave us nothing to judge, which is a different fact
        // from "nothing about this company was published" — see below.
        $returned = 0;
        // Raw results that sat on a whitelisted portal, before the on-topic gate. Zero of
        // these beside a non-zero $returned means the `site:` filter was ignored.
        $onPortal = 0;
        /** @var array<string, true> $askedAbout People a query the backend answered named. */
        $askedAbout = [];
        /** @var array<string, true> $unansweredFor People whose query got no answer at all. */
        $unansweredFor = [];

        foreach ($plan['queries'] as $query) {
            if ($attempted > 0 && microtime(true) >= $deadline) {
                Log::info('News time budget spent', [
                    'attempted' => $attempted,
                    'skipped' => count($plan['queries']) - $attempted,
                ]);
                break;
            }
            $attempted++;

            $results = $this->search->searchNews($query['q'], $max * 3);

            // null, not []: SearXNG never answered this query — refused by our own
            // rate-limit window before it left the process, unreachable, or non-200 — so
            // nobody was asked anything. Crediting the person here would restore the false
            // "we looked" claim through the doors beside the wall-clock one, and the
            // rate-limit door opens exactly when the engines are already degraded.
            if ($results === null) {
                $unanswered++;
                if ($query['person'] !== null) {
                    $unansweredFor[$query['person']] = true;
                }

                continue;
            }

            $issued++;
            // Recorded only here: past the wall-clock exit above and past the unanswered
            // case, with the query demonstrably answered. Nothing between this line and
            // the report can undo that, and the inner `continue`s below only judge results.
            if ($query['person'] !== null) {
                $askedAbout[$query['person']] = true;
            }

            $returned += count($results);

            foreach ($results as $r) {
                $url = (string) ($r['url'] ?? '');
                $domain = (string) ($r['domain'] ?? '');

                if ($url === '') {
                    continue;
                }
                if (! WebSearchFilters::isNewsPortal($domain, $portals)) {
                    continue;
                }
                $onPortal++;

                // A topic/tag listing names the company in its title, but it is an index,
                // not an article. Counted as on-portal above: the portal WAS reached.
                if (WebSearchFilters::isNewsListingPage($url)) {
                    continue;
                }

                // Still gated on the COMPANY's tokens, including for person queries.
                // Loosening it to the person's own name here would store the namesake:
                // a title+snippet is too short to tell "our director" from "someone with
                // the same name who ran a marathon". The name-only query's extra hits
                // therefore stay dropped until the article text itself can be read and
                // checked for the company — which is what the scraping step is for.
                if (! CompanyNameTokens::containsAll($tokens, (string) ($r['title'] ?? '') . ' ' . (string) ($r['snippet'] ?? ''))) {
                    continue;
                }

                // Already collected by an earlier query: keep the one entry, but let a
                // person's query claim it. Company queries run first, so without this a
                // piece genuinely about the director is filed under the company and the
                // person layer looks empty — which is exactly what it did.
                if (isset($seen[$url])) {
                    $existing = $seen[$url];
                    if ($query['person'] !== null && $mentions[$existing]['person'] === null) {
                        $mentions[$existing]['person'] = $query['person'];
                        $mentions[$existing]['subject'] = $query['subject'];
                    }

                    continue;
                }

                $seen[$url] = count($mentions);
                $mentions[] = [
                    'portal' => $domain,
                    'title' => (string) ($r['title'] ?? ''),
                    'url' => $url,
                    'date' => $this->normalizeDate($r['published_date'] ?? null) ?? $this->dateFromUrl($url),
                    'subject' => $query['subject'],
                    'person' => $query['person'],
                ];
            }
        }

        // One blob, spread into all three return paths: what the backend did, then who
        // was asked about because of it. The state travels WITH the people report because
        // the two are read together — a consumer that knows a person was credited as
        // searched must be able to see, without re-deriving it from a status, whether the
        // search that credited them could see anything at all.
        $report = [
            'search_state' => $this->searchState($issued, $returned, $onPortal),
            ...$this->peopleReport($plan, $askedAbout, $unansweredFor),
        ];

        // A degraded state, unlike the two configured ceilings: queries we were willing to
        // pay for never got an answer, so this run saw less than its budget allowed. Which
        // flavour it was (local throttle, unreachable, non-200) is in the client's own log.
        if ($unanswered > 0) {
            Log::warning('News queries went unanswered by the search backend', [
                'unanswered' => $unanswered,
                'issued' => $issued,
            ]);
        }

        // Named, not counted: a count tells nobody which director went unresearched, and
        // the console lists people by name. Logged after the loop, because before it we
        // would only know the plan. The ceilings are deliberate, so this is not a
        // warning — it is the receipt.
        if ($report['people_dropped'] !== []) {
            Log::info('News run left people unsearched', $report);
        }

        usort($mentions, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));
        $mentions = array_slice($mentions, 0, $max);

        if ($mentions === []) {
            // Two very different outcomes, and conflating them made every measurement
            // unreliable: SearXNG returns HTTP 200 with an empty list when its upstream
            // engines are rate-limited or CAPTCHA-suspended, and that looked identical
            // to a company nobody has written about. Observed live: the same query
            // returned 10 results one minute and 0 the next.
            //
            // `empty` now means we searched and found nothing on topic. Zero raw
            // results across every query means we could not see, so it is reported as
            // an error the caller can retry.
            if ($returned === 0) {
                Log::warning('News search returned nothing at all', [
                    'queries_issued' => $issued,
                    'queries_unanswered' => $unanswered,
                ]);

                return CollectionResult::none($this->name(), 'error', [
                    // A run nothing ever answered must not be described as a search that
                    // came back empty: the caller retries either way, but only one of these
                    // two is worth waiting out a rate-limit window for. Read off the state
                    // decided above rather than re-tested here, so the audit sentence and
                    // the stored report can never name different facts.
                    'detail' => $report['search_state'] === self::SEARCH_UNANSWERED
                        ? 'no query was answered by the search backend'
                        : 'search returned no results for any query',
                    'news' => [
                        'score' => 0.0,
                        'fresh' => false,
                        'mentions' => [],
                        ...$report,
                    ],
                ]);
            }

            // Results, but none from a portal we asked for: the `site:` filter was ignored,
            // so no portal was searched. Reporting `empty` here read as "nobody wrote about
            // this company" on a run that never saw a single portal page.
            if ($onPortal === 0) {
                Log::warning('News search ignored the portal filter', [
                    'queries_issued' => $issued,
                    'results_returned' => $returned,
                ]);

                return CollectionResult::none($this->name(), 'error', [
                    'detail' => 'search results ignored the portal filter (no engine honouring site: answered)',
                    'news' => [
                        'score' => 0.0,
                        'fresh' => false,
                        'mentions' => [],
                        ...$report,
                    ],
                ]);
            }

            return CollectionResult::none($this->name(), 'empty', [
                'news' => [
                    'score' => 0.0,
                    'fresh' => false,
                    'mentions' => [],
                    ...$report,
                ],
            ]);
        }

        $mentions = $this->fetchArticles($mentions, (string) ($target['session'] ?? ''));
        usort($mentions, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

        [$score, $fresh] = $this->score($mentions);

        return new CollectionResult($this->name(), 'ok', [], [
            'news' => [
                'score' => $score,
                'fresh' => $fresh,
                'mentions' => $mentions,
                ...$report,
            ],
        ]);
    }

    /**
     * What the search backend did for this whole run, decided in one place so the audit
     * `detail`, the stored report and the console cannot describe it differently.
     *
     * The `error` status covers two of these three, and they call for opposite statements:
     * SEARCH_UNANSWERED asked nobody anything, while SEARCH_RETURNED_NOTHING asked every
     * planned question and credits those people in `people_searched`. A consumer keying
     * its wording on the status alone therefore said "the search went unanswered" beside
     * a person it had just reported as searched — the same claim-we-cannot-support defect
     * that naming people exists to remove, one layer further out.
     *
     * `$issued === 0` needs no companion test for an unanswered query: the plan is
     * non-empty by the time we get here and the first query is always attempted, so a run
     * that issued nothing is a run whose only attempt got no answer.
     */
    private function searchState(int $issued, int $returned, int $onPortal): string
    {
        return match (true) {
            $issued === 0 => self::SEARCH_UNANSWERED,
            $returned === 0 => self::SEARCH_RETURNED_NOTHING,
            $onPortal === 0 => self::SEARCH_OFF_PORTAL,
            default => self::SEARCH_ANSWERED,
        };
    }

    /**
     * Who this run actually asked about, and who it did not — the receipt, spread into
     * all three return paths from one place so none of them can drift into reporting
     * something the others do not.
     *
     * The builder plans; only the loop above knows which of its queries reached the search
     * backend, because the wall-clock exit and an unanswered query both leave a planned
     * query unsearched. A person whose queries were merely planned therefore belongs on the
     * dropped side: calling them searched is the same false claim ("we looked") that
     * naming people exists to remove. One answered query is enough to count as searched —
     * a person has two query forms and the second adds reach, not the first look.
     *
     * `people_dropped` is the single honest answer to "who went unresearched", whichever
     * ceiling or failure stopped us, and `people_dropped_reasons` pairs each name with
     * the one that did, because they call for different responses:
     *
     * - `query_budget`     — the configured ceilings never had room. Raise
     *                        react.news.max_queries if more people must be researched.
     * - `time_budget`      — react.news.time_budget_s ran out mid-run; the backend was slow.
     * - `search_unanswered`— the query got no answer: refused by our own rate-limit window,
     *                        or the backend was unreachable / non-200. More queries is the
     *                        one response that makes this worse.
     * - `unusable_name`    — the value is not purely a person's name (a registry string with
     *                        the role glued on, several people in one field, a company), so
     *                        no query could have matched it. Fix the data, not the budget.
     *
     * A person whose query went unanswered keeps `search_unanswered` even if the wall clock
     * then ended the loop: that is the specific thing that happened to them, and the retry
     * the deadline prevented would have gone unanswered too.
     *
     * The three groups are listed in the order the run refused them — reached-but-not-asked,
     * over budget, then unusable — so `people_dropped` is grouped by cause rather than
     * following the caller's list.
     *
     * `people_dropped_reasons` is a LIST of records, not a name-keyed map, so its JSON
     * shape cannot change with the data: PHP casts a numeric-string key such as "0" to an
     * integer, and a map keyed by such a name encodes as a JSON array instead of an
     * object, silently costing the consumer the name it was reading by.
     *
     * @param array{people_planned: array<int, string>, people_over_budget: array<int, string>, people_unusable?: array<int, string>} $plan
     * @param array<string, true> $asked People a query the backend answered named.
     * @param array<string, true> $unanswered People whose query got no answer at all.
     *
     * @return array{people_searched: array<int, string>, people_dropped: array<int, string>, people_dropped_reasons: array<int, array{name: string, reason: string}>}
     */
    private function peopleReport(array $plan, array $asked, array $unanswered): array
    {
        $searched = [];
        $dropped = [];

        // Both lists keep the caller's own order: planned names are a prefix of the people
        // it supplied, and over-budget names are the rest of that same list.
        foreach ($plan['people_planned'] as $name) {
            if (isset($asked[$name])) {
                $searched[] = $name;

                continue;
            }

            $dropped[] = [
                'name' => $name,
                'reason' => isset($unanswered[$name]) ? 'search_unanswered' : 'time_budget',
            ];
        }

        foreach ($plan['people_over_budget'] as $name) {
            $dropped[] = ['name' => $name, 'reason' => 'query_budget'];
        }

        foreach ($plan['people_unusable'] ?? [] as $name) {
            $dropped[] = ['name' => $name, 'reason' => 'unusable_name'];
        }

        return [
            'people_searched' => $searched,
            'people_dropped' => array_column($dropped, 'name'),
            'people_dropped_reasons' => $dropped,
        ];
    }

    /**
     * Download each kept article once and take everything that fetch can give: the
     * publish date when the search result omitted it, and the article's text.
     *
     * One fetch serves both. Previously only date-less mentions were downloaded and the
     * body was thrown away — so the pipeline paid for the request and kept the least
     * useful part of the response. Text extraction reuses the runner's own conversion
     * (POST /markdown), which needs no browser, so this cannot hit a bot wall.
     *
     * Bounded by react.news.extract_max, because each article is a request to a third
     * party. Every failure is soft: no text simply means the mention keeps whatever the
     * search gave us. The three outcomes stay distinguishable on the mention itself —
     * `excerpt` plus `excerpt_state` = EXCERPT_BODY when the article's words were found,
     * `excerpt_state` = EXCERPT_NO_BODY when the page converted but held no article body,
     * and neither key when the text was never fetched or converted at all.
     *
     * @param array<int, array<string, mixed>> $mentions
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchArticles(array $mentions, string $session): array
    {
        $wantDates = (bool) config('react.news.fetch_dates', true);
        $wantText = (bool) config('react.news.extract_text', true);

        if (! $wantDates && ! $wantText) {
            return $mentions;
        }

        $limit = max(0, (int) config('react.news.extract_max', 5));
        $basePath = $session === '' ? null : $this->fs->findAnalysisBase($session);
        $fetched = 0;

        foreach ($mentions as $i => $mention) {
            $needsDate = $wantDates && $mention['date'] === null;
            $needsText = $wantText && $fetched < $limit;

            if (! $needsDate && ! $needsText) {
                continue;
            }

            $html = $this->articles->fetch((string) $mention['url']);
            if ($html === null || trim($html) === '') {
                continue;
            }
            $fetched++;

            if ($needsDate) {
                $date = ArticleDateParser::fromHtml($html);
                if ($date !== null) {
                    $mentions[$i]['date'] = $date;
                }
            }

            if (! $needsText) {
                continue;
            }

            $markdown = $this->runner->markdown($html);
            if ($markdown === null) {
                continue;
            }

            // The full text lives in the artifact; the profile carries an excerpt. The
            // profile is returned over HTTP to the calling agent on every run, and eight
            // whole articles would dwarf everything else in it.
            //
            // The excerpt is the article's own words, located by ArticleExcerpt — never
            // the first N characters of the page, which on most portals are the menu
            // (measured: entirely so on 11 of 17 real pages). When no body can be
            // located, the mention keeps NO excerpt and says so: navigation reads as
            // evidence and is not, so an empty excerpt is the honest answer and a
            // confident wrong one is the defect.
            $excerpt = ArticleExcerpt::fromMarkdown($markdown, (int) config('react.news.excerpt_chars', 600));

            if ($excerpt === null) {
                $mentions[$i]['excerpt_state'] = self::EXCERPT_NO_BODY;
            } elseif ($excerpt !== '') {
                $mentions[$i]['excerpt_state'] = self::EXCERPT_BODY;
                $mentions[$i]['excerpt'] = $excerpt;
            }

            // The '' case is neither of those two and must claim neither: it means the
            // article's own text WAS located and react.news.excerpt_chars left no budget to
            // carry any of it. Storing '' beside EXCERPT_BODY would assert located evidence
            // and then show an empty cell, which is the exact confusion this state exists to
            // remove, and EXCERPT_NO_BODY would blame the page for a configured 0.

            if ($basePath !== null) {
                $mentions[$i]['artifacts'] = $this->fs->writeNewsArticle(
                    $basePath,
                    (string) $mention['title'],
                    $html,
                    $markdown,
                );
            }
        }

        return $mentions;
    }

    private function normalizeDate(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $ts = strtotime($raw);

        return $ts === false ? null : date('Y-m-d', $ts);
    }

    /**
     * Best-effort publish date from the article URL path — SearXNG news results
     * often omit publishedDate. Recognises only delimited /YYYY/MM/DD/ and
     * YYYY-MM-DD patterns (validated, plausible year), so it never mistakes an
     * article ID for a date. ID-based URLs (tv3/15min/bns) return null.
     */
    private function dateFromUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $maxYear = (int) Carbon::now()->year + 1;

        if (preg_match('#/(20\d{2})/(\d{1,2})/(\d{1,2})(?:/|$)#', $path, $m)
            || preg_match('#(20\d{2})-(\d{1,2})-(\d{1,2})#', $path, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
            if ($year <= $maxYear && checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return null;
    }

    /**
     * @param array<int, array{portal: string, title: string, url: string, date: ?string, subject: string, person: ?string}> $mentions
     *
     * @return array{0: float, 1: bool}
     */
    private function score(array $mentions): array
    {
        $portals = array_unique(array_map(static fn (array $m): string => $m['portal'], $mentions));
        $strong = max(1, (int) config('react.news.strong_portal_count', 3));
        $base = min(1.0, count($portals) / $strong);

        $dates = array_values(array_filter(array_map(static fn (array $m): ?string => $m['date'], $mentions)));
        $hasDates = $dates !== [];
        $cutoff = Carbon::now()->subMonths((int) config('react.news.freshness_months', 12))->format('Y-m-d');

        $fresh = false;
        foreach ($dates as $d) {
            if ($d >= $cutoff) {
                $fresh = true;
                break;
            }
        }

        $freshMultiplier = $hasDates ? ($fresh ? 1.0 : 0.6) : 0.8;

        return [round($base * $freshMultiplier, 3), $fresh];
    }
}
