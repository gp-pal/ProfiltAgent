<?php

namespace Tests\Unit;

use App\Services\ArticleExcerpt;
use Tests\TestCase;

/**
 * Every input here is a slice of a page really captured through the shipped
 * HttpArticleClient + RunnerClient::markdown() path, with its words replaced by invented
 * ones of the same length (see tests/Fixtures/news/README.md). The structure is the
 * portal's own, which is the point: the defect this rule fixes was caused by what real
 * portals put in front of their articles, and each fixture below still reproduces that
 * defect in its first 600 characters.
 */
class ArticleExcerptTest extends TestCase
{
    /** The configured default; the rule is measured at the budget it actually runs with. */
    private const LIMIT = 600;

    /**
     * One captured page per portal: the first sentence of its article, and a string from
     * its chrome that the old byte-0 excerpt carried instead.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function portalPages(): array
    {
        return [
            'lrytas' => [
                'lrytas-logistikos-centras',
                'Yšužūvos višoš mėdūsmas avėpoų etošūatymo ūzolėas „Ėnydūnd“ Edypejoje ašėrėrė',
                'Sužėtkopai',
            ],
            'bns' => [
                'bns-strategija',
                'Yšužūvos omūžūs reteza į ožadėų umumeos ibučū',
                'Yžut okužėugą',
            ],
            '15min' => [
                '15min-retailer-prices',
                'Ūnyšyotuvių dišetnose – tolubs umisorencinės mosėb ubūzas.',
                '15kak.lt/zašopas',
            ],
            'delfi' => [
                'delfi-siuntu-apimtys',
                'Yšužūvos omūžūs per du šių tūpo gėgerius per „EZ Sopožss“ višošmatus',
                '/zašopas/zašopo-zeryts/',
            ],
            'kaunodiena' => [
                'kaunodiena-pasto-skyriai',
                'Libėt iš 166 ėviz sūmo ėgogeančių višoš kunošų dulyk ėžeg ribybyti',
                'Šėdegti į olyšindinį ūguboį',
            ],
            'diena' => [
                'diena-store-closing',
                '„Šis dūtoddienis bus irutotinė Gedėluje, zyzuvu Ezulies g. 58',
                'Šėdegti į olyšindinį ūguboį',
            ],
            'tv3' => [
                'tv3-carrier-trucks',
                '**Onidė iš ukogyausių Šurygos pūrūdų Itučoka Ičyzotics egėl damėpkų uditė',
                '/odėčaka/sapėgenos',
            ],
        ];
    }

    /**
     * @dataProvider portalPages
     */
    public function test_the_excerpt_is_the_article_body_and_never_the_page_chrome(string $fixture, string $opening, string $chrome): void
    {
        $page = $this->fixture($fixture);

        // What the old rule stored. Asserted first, so a fixture that stopped
        // reproducing the defect fails here instead of silently weakening the test below.
        $this->assertStringContainsString(
            $chrome,
            mb_substr($page, 0, self::LIMIT),
            "the first " . self::LIMIT . " characters of {$fixture} must still contain the chrome this rule exists to drop",
        );

        $excerpt = ArticleExcerpt::fromMarkdown($page, self::LIMIT);

        $this->assertNotNull($excerpt, "{$fixture} has an article body and it must be found");
        $this->assertStringStartsWith($opening, $excerpt, "{$fixture} must start at the article's own first sentence");
        $this->assertStringNotContainsString($chrome, $excerpt, "{$fixture} must not carry its navigation");
        $this->assertStringNotContainsString('](', $excerpt, 'the excerpt carries words, not link markup');
        $this->assertStringNotContainsString('http', $excerpt, 'the excerpt spends its budget on text, not URLs');
    }

    public function test_a_topic_page_yields_no_excerpt_rather_than_its_navigation(): void
    {
        // A tema/tag page is a real search result and a real mention, and it has no
        // article body at all. Storing its first 600 characters — "Ėneku čėtuv", RSS
        // links, teaser thumbnails — as the mention's evidence is the exact defect this
        // rule removes, so the honest answer is nothing.
        $page = $this->fixture('15min-topic-page');

        $this->assertStringContainsString('Ėneku čėtuv', mb_substr($page, 0, self::LIMIT));
        $this->assertNull(ArticleExcerpt::fromMarkdown($page, self::LIMIT));
    }

    public function test_the_excerpt_stops_before_a_neighbouring_article_on_the_same_page(): void
    {
        // Delfi's tema page opens with its own description of the company (real text,
        // worth keeping) and then lists other articles as linked headings. Walking past
        // one of those would file a different article's words as this mention's evidence.
        $excerpt = ArticleExcerpt::fromMarkdown($this->fixture('delfi-topic-page'), self::LIMIT);

        $this->assertNotNull($excerpt);
        $this->assertStringContainsString('vavemiaujantis rūtilninės upysabos ūzolėas Yšužūvoje', $excerpt);
        $this->assertStringNotContainsString('Вусырстки', $excerpt, "a neighbouring article's teaser must not enter the excerpt");
        $this->assertStringNotContainsString('Azyrūliniuose ūzolėuose', $excerpt);
    }

    public function test_chrome_between_two_paragraphs_is_skipped_and_the_article_is_read_on(): void
    {
        // Delfi renders a photo caption, a FOTO credit and a "Pynače Ūžyče Ebabee" widget
        // in the middle of the body. Stopping at the first of them would cost the article;
        // quoting them would put furniture in the evidence.
        $excerpt = ArticleExcerpt::fromMarkdown($this->fixture('delfi-siuntu-apimtys'), self::LIMIT);

        $this->assertNotNull($excerpt);
        $this->assertStringContainsString('etošūatė uzašūk 3 mln. avėpoų', $excerpt);
        $this->assertStringContainsString('Eručūtis Pepūztinas', $excerpt, 'the article continues past the mid-body furniture');
        $this->assertStringNotContainsString('OSOP', $excerpt);
        $this->assertStringNotContainsString('Pynače Ūžyče', $excerpt);
    }

    public function test_a_link_the_converter_split_across_a_blank_line_is_not_mistaken_for_prose(): void
    {
        // tv3 puts its whole menu above the article, ~13.7 kB of it, and ten of those
        // blocks open mid-link: share buttons, and one obfuscated-email payload 487 bytes
        // long. None of them is inside link markup any more — the opening bracket is in the
        // previous block — so to a rule that only weighs length and markup they are 400+
        // characters of prose.
        $page = $this->fixture('tv3-carrier-trucks');

        $this->assertStringContainsString('](/cdn-cgi/l/šižėv-ipezėction', $page, 'the fixture must keep the split link that fooled a length-only rule');

        $excerpt = ArticleExcerpt::fromMarkdown($page, self::LIMIT);

        $this->assertNotNull($excerpt);
        $this->assertStringNotContainsString('cdn-cgi', $excerpt);
        $this->assertStringNotContainsString('šižėv-ipezėction', $excerpt);

        // On tv3 the heading anchor already skips these blocks, so the guard against them
        // is not what saves the page above. It is what saves a page with no heading at all
        // — the diena.lt family renders none — and that is what this fixture is: the very
        // same captured block, alone, with nothing else to skip it.
        $this->assertNull(
            ArticleExcerpt::fromMarkdown($this->fixture('tv3-split-link-block'), self::LIMIT),
            'a block that opens mid-link is markup and must never open an excerpt',
        );
    }

    public function test_the_excerpt_honours_the_budget_and_ends_on_a_whole_word(): void
    {
        $page = $this->fixture('lrytas-logistikos-centras');

        $this->assertLessThanOrEqual(self::LIMIT, mb_strlen((string) ArticleExcerpt::fromMarkdown($page, self::LIMIT)));

        // 45 characters land inside "ūzolėas", so the excerpt gives up the partial word.
        $this->assertSame('Yšužūvos višoš mėdūsmas avėpoų etošūatymo', ArticleExcerpt::fromMarkdown($page, 45));

        // 40 characters land inside "etošūatymo", and the previous word boundary is at 30
        // — below the floor. Trimming back to it would cost a quarter of the budget, so
        // the ragged word stays and the reader still gets 40 characters of article.
        $this->assertSame('Yšužūvos višoš mėdūsmas avėpoų etošūatym', ArticleExcerpt::fromMarkdown($page, 40));
    }

    public function test_a_page_that_converts_to_nothing_has_no_excerpt(): void
    {
        $this->assertNull(ArticleExcerpt::fromMarkdown(null, self::LIMIT));
        $this->assertNull(ArticleExcerpt::fromMarkdown('', self::LIMIT));
        $this->assertNull(ArticleExcerpt::fromMarkdown("   \n\n  \n", self::LIMIT));
    }

    public function test_a_tag_row_between_two_paragraphs_is_not_quoted_as_article_text(): void
    {
        // 15min renders "Čėtuv: 9" plus nine tag links mid-article. Unwrapped, its labels
        // are 166 characters of plausible-looking text — long enough for any length test —
        // and it is a navigation row. The article's next paragraph is what belongs here.
        $excerpt = ArticleExcerpt::fromMarkdown($this->fixture('15min-retailer-prices'), self::LIMIT);

        $this->assertNotNull($excerpt);
        $this->assertStringNotContainsString('Čėtuv: 9', $excerpt);
        $this->assertStringNotContainsString('15kak idutumerata', $excerpt);
        $this->assertStringContainsString('apežynija „Zūtilu, nėvėn ūčulyau“', $excerpt, 'the article is read on past the tag row');
    }

    public function test_a_portal_disclaimer_next_to_the_lead_is_quoted_and_that_is_a_known_limit(): void
    {
        // Documented in docs/architecture/news-excerpt.md, and pinned here so it stays
        // visible: 15min prints its press-release disclaimer as an ordinary paragraph
        // right under the lead, and no structural signal separates it from the article.
        // The rule quotes it. It costs excerpt budget and says nothing about the company —
        // but it is the page's own editorial text, not navigation, so it does not make the
        // excerpt a false claim. Improving this should turn this assertion around.
        $excerpt = ArticleExcerpt::fromMarkdown($this->fixture('15min-pastomatai'), self::LIMIT);

        $this->assertNotNull($excerpt);
        $this->assertStringStartsWith('„Yšužūvos omūžūs“ žisamda višošmatų ūzolėo žigasą Ygėgijos žėmėtne', $excerpt);
        $this->assertStringContainsString('„Keravšimas ložydai“ - tai sapėgenų čėrimūrų', $excerpt);
    }

    public function test_a_neighbouring_articles_teaser_in_the_chrome_does_not_open_the_excerpt(): void
    {
        // The defect this rule had: the boundary that ends the walk was consulted only from
        // the body start onward, so a teaser ABOVE the body was invisible to it. On
        // kauno.diena.lt and diena.lt — which render no `# ` heading and do put other
        // articles' headings above the body — that shipped a different article's opening
        // sentence as this mention's evidence, labelled as the article's own words.
        //
        // Fixture: kauno.diena's own page with Delfi's real teaser (its linked heading and
        // the summary paragraph under it, both verbatim) spliced into the chrome. Refusing
        // to open below a boundary would answer this by losing kauno.diena's body entirely,
        // so what disqualifies the teaser is that it restates the headline above it.
        $excerpt = ArticleExcerpt::fromMarkdown($this->fixture('spliced-teaser-cluster-in-chrome'), self::LIMIT);

        $this->assertNotNull($excerpt);
        $this->assertStringStartsWith('Libėt iš 166 ėviz sūmo ėgogeančių višoš kunošų', $excerpt, "the page's own article must open the excerpt");
        $this->assertStringNotContainsString('Azyrūliniuose ūzolėuose', $excerpt, "a neighbour's teaser must not open the excerpt");
        $this->assertStringNotContainsString('tėsėzlių', $excerpt);
    }

    public function test_a_stray_paragraph_in_a_teaser_region_does_not_open_the_excerpt(): void
    {
        // The same leak with nothing to attribute the paragraph by: Delfi's summary
        // paragraph alone, with no headline of its own, dropped into kauno.diena's teaser
        // region. What disqualifies it here is size — one paragraph, then the next teaser
        // heading, which cannot be an article body when the budget is 600 characters.
        $excerpt = ArticleExcerpt::fromMarkdown($this->fixture('spliced-unrelated-teaser-in-chrome'), self::LIMIT);

        $this->assertNotNull($excerpt);
        $this->assertStringStartsWith('Libėt iš 166 ėviz sūmo ėgogeančių višoš kunošų', $excerpt);
        $this->assertStringNotContainsString('Azyrūliniuose ūzolėuose', $excerpt);
    }

    public function test_the_anchor_takes_the_articles_own_heading_and_not_the_first_heading_of_any_level(): void
    {
        // What makes chrome above the headline free: the anchor lands on the article's own
        // `# `, never on a `####`/`#####` teaser heading above it. The fixture puts a real
        // teaser heading and an UNRELATED real paragraph (kauno.diena's body) above lrytas's
        // headline — nothing attributes that paragraph to the heading, and its run reaches
        // the budget through the lrytas article below it, so no other guard can exclude it.
        $excerpt = ArticleExcerpt::fromMarkdown($this->fixture('spliced-teaser-above-heading'), self::LIMIT);

        $this->assertNotNull($excerpt);
        $this->assertStringStartsWith('Yšužūvos višoš mėdūsmas avėpoų etošūatymo ūzolėas', $excerpt);
        $this->assertStringNotContainsString('Libėt iš 166', $excerpt);
    }

    public function test_an_article_whose_own_headline_is_a_link_still_opens_at_its_lead(): void
    {
        // tv3 renders the article's OWN headline as `# [Title](/this-article)`. Counting that
        // as another article's announcement would make the lead — which restates its own
        // headline word for word — read as a teaser OF it, and the page's later prose would
        // open the excerpt instead. The fixture is the tv3 capture with one real paragraph
        // from another page appended, standing in for the later prose every full tv3 page
        // carries and the committed slice is too short to include.
        $excerpt = ArticleExcerpt::fromMarkdown($this->fixture('spliced-tv3-headline-then-later-prose'), self::LIMIT);

        $this->assertNotNull($excerpt);
        $this->assertStringStartsWith('**Onidė iš ukogyausių Šurygos pūrūdų Itučoka Ičyzotics', $excerpt);
        $this->assertStringNotContainsString('Libėt iš 166', $excerpt);
    }

    public function test_a_paragraph_directly_under_the_headline_reads_as_the_lead_and_that_is_a_known_limit(): void
    {
        // Documented in docs/architecture/news-excerpt.md, and pinned here so it stays
        // visible rather than being discovered again: a bare paragraph between the headline
        // and the lead, with no teaser heading and no other marker, is the same shape as a
        // lead paragraph. Nothing in the markdown distinguishes them, so the rule takes it —
        // this fixture is Delfi's teaser paragraph spliced under lrytas's own headline.
        //
        // The excerpt then opens with another article's sentence. That is the residual hole
        // this rule cannot close, and closing it needs a signal the page does not carry.
        $excerpt = ArticleExcerpt::fromMarkdown($this->fixture('spliced-teaser-below-heading'), self::LIMIT);

        $this->assertNotNull($excerpt);
        $this->assertStringStartsWith('Azyrūliniuose ūzolėuose vėl sasaka yrūbuo olyzei', $excerpt);
    }

    public function test_the_markup_ceiling_keeps_a_link_dense_paragraph_and_drops_a_tag_row(): void
    {
        // What the 0.5 ceiling is actually measured against, both ends of it, because the
        // value itself has slack the fixtures cannot close: Delfi links company names inside
        // its sentences (0.44 markup share, its densest true paragraph) and 15min renders a
        // tag row that is 0.97 markup. Every value in between is untested — a Delfi lead
        // denser than 0.44 would be dropped as markup, and that is the risk the number
        // carries.
        $delfi = ArticleExcerpt::fromMarkdown($this->fixture('delfi-siuntu-apimtys'), self::LIMIT);
        $fifteen = ArticleExcerpt::fromMarkdown($this->fixture('15min-retailer-prices'), self::LIMIT);

        $this->assertNotNull($delfi);
        $this->assertStringContainsString(
            'Tuo sūmo per „Ėnydūnd“ višošmatų ūzolėą Edypejoje ir Nubėšoje',
            $delfi,
            'the 0.44 paragraph — Delfi links two names inside it — is article text and must be kept',
        );
        $this->assertNotNull($fifteen);
        $this->assertStringNotContainsString('15kak idutumerata', $fifteen, 'the 0.97 tag row is markup and must be dropped');
    }

    public function test_a_short_article_below_a_boundary_is_kept_by_the_deferral_fallback(): void
    {
        // The mechanism the whole below-a-boundary design leans on, and it was unpinned:
        // a candidate that fails the size test is remembered, not dropped. This page is a
        // further head slice of the kauno.diena capture, cut after its FIRST body paragraph
        // — a 211-character news brief on a portal that renders no `# ` heading, so the
        // only candidate on the page is one the size test rejects. Without the fallback the
        // mention would carry no excerpt at all and the run would report `no_body_found`
        // about a page that plainly has an article on it.
        $excerpt = ArticleExcerpt::fromMarkdown($this->fixture('kaunodiena-short-body'), self::LIMIT);

        $this->assertNotNull($excerpt, 'a short body must survive the size test it cannot pass');
        $this->assertStringStartsWith('Libėt iš 166 ėviz sūmo ėgogeančių višoš kunošų', $excerpt);
    }

    public function test_the_earliest_deferred_candidate_wins(): void
    {
        // Which deferral is returned when several fail is a choice, not a measurement (no
        // captured page defers more than one), so it is pinned rather than left to drift:
        // the page's own short body comes first and a later short paragraph — Delfi's topic
        // blurb, real text, below a real teaser heading — must not displace it.
        $excerpt = ArticleExcerpt::fromMarkdown($this->fixture('spliced-two-deferred-candidates'), self::LIMIT);

        $this->assertNotNull($excerpt);
        $this->assertStringStartsWith('Libėt iš 166 ėviz sūmo ėgogeančių višoš kunošų', $excerpt);
        $this->assertStringNotContainsString('vavemiaujantis rūtilninės upysabos ūzolėas', $excerpt);
    }

    public function test_a_short_body_followed_by_a_long_run_of_other_prose_is_lost_and_that_is_the_trade(): void
    {
        // The cost of the size test, stated as a test rather than as an absolute in prose:
        // the fallback returns a deferred candidate only when no LATER candidate passes, so
        // a short body below a boundary IS dropped when 600-plus characters of other prose
        // follow it. Here that is kauno.diena's 211-character brief, a real teaser heading,
        // and tv3's 1.4 kB lead standing in for a comment thread or a syndicated block.
        //
        // The byte-0 rule this replaced would have returned the brief. The trade is
        // deliberate — a short body lost on a shape nobody has captured, against a
        // neighbour's words shipped as evidence on a shape two portals render every day —
        // and this test exists so it cannot be re-traded silently.
        $excerpt = ArticleExcerpt::fromMarkdown($this->fixture('spliced-short-body-then-later-prose'), self::LIMIT);

        $this->assertNotNull($excerpt);
        $this->assertStringStartsWith('**Onidė iš ukogyausių Šurygos pūrūdų Itučoka Ičyzotics', $excerpt);
        $this->assertStringNotContainsString('Libėt iš 166', $excerpt, "the page's own short body is what this trade costs");
    }

    public function test_an_unattributed_paragraph_in_the_last_chrome_slot_still_leaks(): void
    {
        // The residual half of M-1, pinned like the other known leak rather than left to be
        // derived from two bullets in the document. Neither guard reaches it: the paragraph
        // carries no heading to be attributed to, and because it sits directly above the
        // body its prose and the article's run together past 600 characters, so the size
        // test sees an article-length run.
        //
        // Reachable, not theoretical: blocks #25-#29 of the kauno.diena capture are that
        // slot, a 71-character newsletter pitch today. A template change that lengthens it
        // past 120 characters makes it the excerpt of every kauno.diena mention.
        $excerpt = ArticleExcerpt::fromMarkdown($this->fixture('spliced-unrelated-teaser-above-body'), self::LIMIT);

        $this->assertNotNull($excerpt);
        $this->assertStringStartsWith('Azyrūliniuose ūzolėuose vėl sasaka yrūbuo olyzei', $excerpt);
    }

    public function test_the_guards_do_not_weaken_when_the_excerpt_budget_is_raised(): void
    {
        // The trap this closes: both below-a-boundary tests used to be measured against
        // react.news.excerpt_chars, so an operator raising it to carry more evidence — the
        // one reason anyone would touch it — switched them off and restored the leak, with
        // the whole suite green. Measured before the fix: the size guard failed at 900 (above
        // kauno.diena's 827-character body) and the attribution guard at 2000.
        //
        // The run length is now its own constant. Both guard fixtures must therefore hold at
        // every budget, not only at the shipped 600.
        foreach ([300, 900, 2000] as $budget) {
            $this->assertStringStartsWith(
                'Libėt iš 166 ėviz sūmo ėgogeančių višoš kunošų',
                (string) ArticleExcerpt::fromMarkdown($this->fixture('spliced-unrelated-teaser-in-chrome'), $budget),
                "the size guard must not depend on the excerpt budget (budget {$budget})",
            );
            $this->assertStringStartsWith(
                'Libėt iš 166 ėviz sūmo ėgogeančių višoš kunošų',
                (string) ArticleExcerpt::fromMarkdown($this->fixture('spliced-teaser-cluster-in-chrome'), $budget),
                "the attribution guard must not depend on the excerpt budget (budget {$budget})",
            );
        }
    }

    public function test_the_run_length_bar_is_pinned_against_the_shorter_of_the_two_real_bodies(): void
    {
        // The bar has to clear the longest teaser summary (303 characters) and stay under the
        // SHORTEST real body that sits below a boundary. That is diena's 677, not
        // kauno.diena's 827, and testing only the longer one left a silent window: at 700 the
        // suite stayed green while diena.lt started shipping a neighbour's words. Same shape
        // as the kauno.diena size fixture, on the page whose body is the tighter constraint.
        $excerpt = ArticleExcerpt::fromMarkdown($this->fixture('spliced-unrelated-teaser-in-diena-chrome'), self::LIMIT);

        $this->assertNotNull($excerpt);
        $this->assertStringStartsWith('„Šis dūtoddienis bus irutotinė Gedėluje, zyzuvu Ezulies g. 58', $excerpt);
        $this->assertStringNotContainsString('Azyrūliniuose ūzolėuose', $excerpt);
    }

    public function test_a_run_two_characters_under_the_bar_does_not_pass_it(): void
    {
        // The run length is counted exactly: separators between blocks, none before the first.
        // The arithmetic this replaced added two characters per block including the first, so a
        // run of 598 characters reported 600 and cleared a 600 bar it was under.
        //
        // This page is that case to the character — Delfi's 303-character teaser summary and
        // BNS's 293-character paragraph, then a real teaser heading to end the run: 303 + 2 +
        // 293 = 598. Two characters of arithmetic decide whether a neighbour's words open the
        // excerpt of every mention from this page.
        $excerpt = ArticleExcerpt::fromMarkdown($this->fixture('spliced-run-length-on-the-threshold'), self::LIMIT);

        $this->assertNotNull($excerpt);
        $this->assertStringStartsWith('Libėt iš 166 ėviz sūmo ėgogeančių višoš kunošų', $excerpt);
        $this->assertStringNotContainsString('Azyrūliniuose ūzolėuose', $excerpt);
    }

    /** A captured page slice, its words replaced by invented ones of the same length. */
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . "/../Fixtures/news/{$name}.md");
    }
}
