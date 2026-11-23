<?php

namespace App\Services;

/**
 * Pure (I/O-free) locator of an article's own text inside the markdown the runner's
 * POST /markdown returns, for the news dimension's stored excerpt.
 *
 * WHY THIS EXISTS. The excerpt used to be the first N characters of the converted page,
 * whatever they were. The runner's main-content mode is exclusion-based (it strips
 * header/footer/nav/aside plus a class blacklist), so every portal that renders its
 * chrome outside those tags keeps it — and the chrome lands first. Measured on 17 real
 * pages from 7 portals: the article's own heading sits at character 420 on lrytas, at
 * 1 156 on BNS and at 13 580 on tv3, so a 600-character excerpt cut from character 0 was
 * mostly or entirely menu links and logos. Navigation reads as evidence and is not, and
 * the excerpt is exactly the field an operator and a consumer judge a mention by.
 *
 * THE RULE. The page is split into blank-line-separated blocks and every block is sorted
 * into one of three kinds by {@see self::classify()} — the article's own prose, page
 * furniture, or the boundary that announces a DIFFERENT article. That one classifier is
 * consulted at both ends of the walk, so a guard added to it cannot protect the excerpt's
 * end while leaving its start open, which is the defect this file had:
 *
 * - The article's own `# ` heading, when the portal renders one, is the anchor:
 *   everything above it is chrome by construction.
 * - The excerpt opens at the first prose block from there down. Below a boundary the page
 *   is showing other articles, so a candidate there must both run on for
 *   MIN_BODY_RUN_CHARS before the next boundary and not restate the headline just above it
 *   — a teaser gives one paragraph and echoes its own headline, an article body does
 *   neither. A candidate that fails either test is remembered and returned if no later one
 *   passes, which bounds the cost of a wrong answer without erasing it — see
 *   {@see self::bodyStart()} for what it does still cost.
 * - Following prose blocks extend the excerpt until the budget is spent; furniture in
 *   between is skipped rather than quoted, and a boundary ends the walk.
 *
 * WHAT IT REFUSES TO DO. When no block qualifies, {@see self::fromMarkdown()} returns
 * null and the caller stores no excerpt ({@see NewsSource::fetchArticles()} records
 * `excerpt_state` instead). A topic or tag page, a paywall stub and a JS-only shell all
 * land here, and for all three an empty excerpt is the true answer.
 *
 * WHAT IT STILL CANNOT DO — including one shape it provably cannot judge — is documented
 * in docs/architecture/news-excerpt.md.
 */
class ArticleExcerpt
{
    /** The article's own words. */
    private const BODY = 'body';

    /** Page furniture: captions, credits, share and subscribe widgets, breadcrumbs, menus. */
    private const FURNITURE = 'furniture';

    /**
     * A level-2-or-deeper heading whose whole text is a link — how a portal announces a
     * DIFFERENT article on this page (`[##### Title](/other)` or `#### [Title](/other)`).
     * Never article text, and the marker that one article's region has ended.
     *
     * Level 1 is deliberately excluded: tv3 renders the article's OWN headline as a linked
     * `# [Title](/this-article)`, and calling that a boundary would make the article's own
     * lead look like a neighbour's teaser (its lead restates its own headline word for
     * word). Every announcement of another article measured across the 7 portals is level
     * 2 or deeper.
     */
    private const BOUNDARY = 'boundary';

    /**
     * Minimum plain-text characters for a block to open or extend the excerpt. Measured
     * floor: the shortest true lead paragraph across the 17 captured pages is lrytas at
     * 122 characters. It is a floor against furniture, not against every kind of chrome —
     * a page `<title>` line is longer than this on most portals (148 characters on BNS),
     * and it is the `# ` anchor, not this constant, that excludes those.
     */
    private const MIN_PROSE_CHARS = 120;

    /**
     * Maximum share of a block's raw characters that may sit inside link or image markup.
     * The evidence constrains this only loosely: the most link-dense true body paragraph
     * measured is delfi at 0.44 (it links company names inline), and the only block above
     * 0.44 that must be rejected is the 15min tag row at 0.97. Anything in between is
     * untested slack, and a Delfi lead a little link-heavier than 0.44 would be dropped.
     */
    private const MAX_MARKUP_SHARE = 0.5;

    /**
     * How far prose must run past a boundary before it reads as an article body rather than
     * a teaser's paragraph. Measured gap, in CHARACTERS: the one real teaser summary in the
     * captures is 303, and the two real bodies that sit below boundaries are 677 (diena) and
     * 827 (kauno.diena). The usable window is therefore 304…677 — the smaller body, not the
     * larger, is the ceiling — and a fixture built on each of those two pages pins both ends,
     * so an edit into the window that used to pass silently now fails a test.
     *
     * It is its own constant rather than the excerpt budget precisely so that an operator
     * raising `react.news.excerpt_chars` cannot switch this guard off —
     * {@see self::readsAsArticleRun()}.
     */
    private const MIN_BODY_RUN_CHARS = 600;

    /**
     * How much of an announcement's headline a block must repeat to read as that
     * article's teaser: both an absolute number of shared stems and a share of the
     * headline's own, so a one-word coincidence in a long headline is not enough.
     * Measured gap: 3 of 7 stems (0.43) for the one real teaser pair in the captures,
     * 0 of 4 for both real counter-pairs.
     */
    private const MIN_RESTATED_STEMS = 2;

    private const MIN_RESTATED_SHARE = 0.33;

    /** Prefix length a word is cut to before comparing, and the shortest word compared. */
    private const STEM_LENGTH = 5;

    /**
     * Fraction of the budget after which a truncated excerpt may be trimmed back to a
     * word boundary. Below it, cutting to the previous space would cost more text than
     * the ragged final word is worth.
     */
    private const WORD_BOUNDARY_FLOOR = 0.8;

    /** Images: dropped whole, alt text included — it names a photo, it is not article text. */
    private const IMAGE_PATTERN = '/!\[[^\]]*\]\([^)\s]*(?:\s+"[^"]*")?\)/u';

    /** Links: unwrapped to their label, so the excerpt spends its budget on words, not URLs. */
    private const LINK_PATTERN = '/\[([^\]]*)\]\([^)\s]*(?:\s+"[^"]*")?\)/u';

    /**
     * The article's own text from the start of its body, at most $limit characters, or
     * null when this page has no locatable article body.
     *
     * A $limit below 1 yields an empty string, not null: the body may well have been
     * found, and there was simply no budget to carry any of it. The caller must not
     * report that as a page without an article ({@see NewsSource::fetchArticles()}).
     */
    public static function fromMarkdown(?string $markdown, int $limit): ?string
    {
        if ($markdown === null || trim($markdown) === '') {
            return null;
        }

        $blocks = preg_split('/\R{2,}/u', $markdown);
        if ($blocks === false) {
            return null;
        }

        $limit = max(0, $limit);
        $start = self::bodyStart($blocks, $limit);
        if ($start === null) {
            return null;
        }

        return self::trimToLimit(implode("\n\n", self::collect($blocks, $start, $limit)), $limit);
    }

    /**
     * Index of the block the article's own text starts at, or null when the page has none.
     *
     * Below a boundary the page is showing other articles, and a teaser's summary
     * paragraph is prose by every local test — on kauno.diena.lt and diena.lt, which
     * render no `# ` heading at all, such a paragraph sits ABOVE the article's own body,
     * so refusing to open below a boundary is not an option either: it would return null
     * for both of those portals, whose real bodies are the 23rd and 30th block of their
     * pages, below a dozen boundaries.
     *
     * Two things separate them, and both are needed. Size: a teaser gives one paragraph
     * before the next boundary, while an article body runs on for MIN_BODY_RUN_CHARS — that
     * alone fails when the teaser is the last thing above the body, because then its
     * paragraph and the body run together into one long stretch. Attribution: a teaser
     * echoes the headline directly above it ({@see self::restates()}), which a body sitting
     * under someone else's headline does not.
     *
     * A candidate that fails either test is REMEMBERED rather than discarded, and returned
     * if no LATER candidate passes. That is weaker than "nothing better on the page", and
     * the difference is a real cost, not a technicality: a short article body below a
     * boundary IS dropped when 600-plus characters of other prose follow it — a comment
     * thread, a syndicated block, two stacked teaser summaries. Measured on a constructed
     * page (a 402-character kauno.diena body followed by a boundary and 726 characters of
     * other prose): this returns the later prose and the article's own words are absent,
     * where the byte-0 rule before it returned the body. It is the deliberate direction of
     * the trade — a short body lost on a shape nobody has captured, against a neighbour's
     * words shipped as evidence on a shape two portals render — and it is pinned by
     * ArticleExcerptTest so it cannot be traded again by accident.
     *
     * When several candidates are deferred the EARLIEST wins. No captured page defers more
     * than one, so that order is a choice rather than a measurement: it is right for a short
     * body followed by comments, and wrong for a page whose teasers carry bare summaries
     * above a short body.
     *
     * @param array<int, string> $blocks
     */
    private static function bodyStart(array $blocks, int $limit): ?int
    {
        $heading = self::headingIndex($blocks);
        $announced = null;
        $belowBoundary = false;
        $remembered = null;

        foreach ($blocks as $i => $block) {
            if ($i < $heading) {
                continue;
            }

            $kind = self::classify($block);

            if ($kind === self::BOUNDARY) {
                $announced = $block;
                $belowBoundary = true;

                continue;
            }
            if ($kind === self::FURNITURE) {
                continue;
            }

            $isTeaser = $announced !== null && self::restates($announced, $block);
            // Only the nearest announcement can own a summary; past this block the walk is
            // no longer directly under it.
            $announced = null;

            if (! $belowBoundary || (! $isTeaser && self::readsAsArticleRun($blocks, $i))) {
                return $i;
            }

            $remembered ??= $i;
        }

        return $remembered;
    }

    /**
     * Does this block read as the summary belonging to the announcement above it — a
     * DIFFERENT article's teaser rather than this page's own text? A teaser restates its
     * own headline; a body paragraph that merely happens to sit under someone else's
     * headline does not.
     *
     * Thin evidence, stated plainly: the committed captures offer one real pair of this
     * kind (a delfi teaser headline and the summary under it, 3 of 7 stems
     * shared) and two real counter-pairs (diena's and kauno.diena's own bodies sitting
     * directly under a neighbour's headline, 0 of 4 shared). The thresholds sit in that
     * gap with room on both sides, and a wrong answer here costs at most a deferral, never
     * a dropped body — {@see self::bodyStart()}.
     */
    private static function restates(string $announcement, string $block): bool
    {
        $title = self::stems(self::plainText($announcement));
        if ($title === []) {
            return false;
        }

        $shared = count(array_intersect_key($title, self::stems(self::plainText($block))));

        return $shared >= self::MIN_RESTATED_STEMS
            && $shared / count($title) >= self::MIN_RESTATED_SHARE;
    }

    /**
     * The distinctive word stems of a piece of text: words of at least STEM_LENGTH
     * characters, cut to that length so Lithuanian inflection (`paauglių` / `paaugliai`)
     * still matches. Short words are dropped because they are mostly function words, which
     * every two Lithuanian sentences share.
     *
     * @return array<string, true>
     */
    private static function stems(string $text): array
    {
        $stems = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [] as $word) {
            if (mb_strlen($word) >= self::STEM_LENGTH) {
                $stems[mb_substr($word, 0, self::STEM_LENGTH)] = true;
            }
        }

        return $stems;
    }

    /**
     * Does the prose starting at this block run on far enough to be an article body rather
     * than a teaser's single paragraph?
     *
     * Deliberately NOT measured against the excerpt budget, which is what it used to be.
     * `react.news.excerpt_chars` is a presentation knob an operator may raise to carry more
     * evidence, and while this test was tied to it, raising it above a page's own body
     * length switched both new guards off and restored the pre-fix behaviour — measured: at
     * 900 the size guard stopped catching a teaser on kauno.diena (body 827) and diena
     * (body 677), and at 2000 the attribution guard went with it. A booby trap for whoever
     * inherits this, and a green suite while they set it.
     *
     * @param array<int, string> $blocks
     */
    private static function readsAsArticleRun(array $blocks, int $start): bool
    {
        return self::runLength($blocks, $start, self::MIN_BODY_RUN_CHARS) >= self::MIN_BODY_RUN_CHARS;
    }

    /**
     * Characters of prose from this block to the next boundary, counted exactly as
     * {@see self::collect()} would join them and stopped once $cap is reached.
     *
     * Exact on purpose: the earlier version reused collect()'s own accumulator, which adds
     * two separator characters per block before comparing, so a run landing on the
     * threshold or one past it was reported as falling short. Harmless at the shipped
     * numbers (measured: no such case at 600) and a needless deferral at others.
     *
     * @param array<int, string> $blocks
     */
    private static function runLength(array $blocks, int $start, int $cap): int
    {
        $length = 0;

        foreach (array_slice($blocks, $start) as $block) {
            $kind = self::classify($block);

            if ($kind === self::BOUNDARY) {
                break;
            }
            if ($kind === self::FURNITURE) {
                continue;
            }

            $length += ($length === 0 ? 0 : 2) + mb_strlen(self::plainText($block));

            if ($length >= $cap) {
                break;
            }
        }

        return $length;
    }

    /**
     * Index of the block holding the article's own `# ` heading, or 0 when the portal
     * renders none. Level 1 only, and the FIRST one: `##`+ is used for subheads inside the
     * article, and anchoring on one of those would skip the lead. Portals without an h1
     * (the diena.lt family) simply search from the top.
     *
     * @param array<int, string> $blocks
     */
    private static function headingIndex(array $blocks): int
    {
        foreach ($blocks as $i => $block) {
            if (preg_match('/^#\s+\S/u', ltrim($block)) === 1) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * The article's own prose blocks from $start, in order, stopping at the budget or at
     * the boundary that announces another article. Furniture in between is skipped, not
     * quoted: on the pages measured that is a photo caption, a FOTO credit, a share or
     * subscribe widget — never a sentence of the article.
     *
     * @param array<int, string> $blocks
     *
     * @return array<int, string>
     */
    private static function collect(array $blocks, int $start, int $limit): array
    {
        $parts = [];
        $length = 0;

        foreach (array_slice($blocks, $start) as $block) {
            $kind = self::classify($block);

            if ($kind === self::BOUNDARY) {
                break;
            }
            if ($kind === self::FURNITURE) {
                continue;
            }

            $text = self::plainText($block);
            $parts[] = $text;
            $length += mb_strlen($text) + 2;

            if ($length >= $limit) {
                break;
            }
        }

        return $parts;
    }

    /**
     * Sort one block into article prose, page furniture, or another article's boundary.
     * The single notion of "this is not article text" in this class — both the opening
     * and the extension read it, so neither can be guarded without the other.
     *
     * @return self::BODY|self::FURNITURE|self::BOUNDARY
     */
    private static function classify(string $block): string
    {
        $block = trim($block);

        if (self::isLinkedHeading($block)) {
            return self::BOUNDARY;
        }

        // A block opening mid-link is the tail of a link the converter wrapped across a
        // blank line — markup, however much text follows it. tv3 puts ~1.5 kB of exactly
        // this above its articles, one block of which carries an obfuscated-email payload
        // long enough for any length test to accept.
        if ($block === '' || str_starts_with($block, '](')) {
            return self::FURNITURE;
        }

        // A heading is a title (this article's or a section's), never the body itself.
        if (str_starts_with($block, '#')) {
            return self::FURNITURE;
        }

        if (self::markupShare($block) > self::MAX_MARKUP_SHARE) {
            return self::FURNITURE;
        }

        return mb_strlen(self::plainText($block)) >= self::MIN_PROSE_CHARS
            ? self::BODY
            : self::FURNITURE;
    }

    /** A level-2-or-deeper heading whose whole text is a link. {@see self::BOUNDARY}. */
    private static function isLinkedHeading(string $block): bool
    {
        $block = ltrim($block);

        return preg_match('/^\[#{2,6}\s/u', $block) === 1
            || preg_match('/^#{2,6}\s+\[/u', $block) === 1;
    }

    /** Share of the raw block's characters that sit inside link or image markup. */
    private static function markupShare(string $block): float
    {
        $length = mb_strlen($block);
        if ($length === 0) {
            return 1.0;
        }

        $stripped = (string) preg_replace([self::IMAGE_PATTERN, self::LINK_PATTERN], ['', ''], $block);

        return ($length - mb_strlen($stripped)) / $length;
    }

    /**
     * The block's words: images dropped, links reduced to their labels, whitespace runs
     * collapsed. The verbatim markdown stays in the artifact on disk; the excerpt is the
     * evidence a human and a consumer read, so it spends its characters on text.
     */
    private static function plainText(string $block): string
    {
        $text = (string) preg_replace(self::IMAGE_PATTERN, '', $block);
        $text = (string) preg_replace(self::LINK_PATTERN, '$1', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Cut to the budget, back to the last word boundary when that costs little, so the
     * excerpt never ends mid-word.
     *
     * No trailing-whitespace guard, deliberately: neither branch can produce one. The word
     * boundary branch cuts immediately before a space, and the hard cut is only reached
     * when the last $limit characters hold no space above the floor — which for a cut that
     * lands on the "\n\n" between two blocks would mean a spaceless stretch a fifth of the
     * budget long inside prose. Measured: across every committed fixture at every budget
     * from 20 to 700 characters, no pair reaches it.
     */
    private static function trimToLimit(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $lastSpace = mb_strrpos($cut, ' ');

        return $lastSpace !== false && $lastSpace >= (int) ($limit * self::WORD_BOUNDARY_FLOOR)
            ? mb_substr($cut, 0, $lastSpace)
            : $cut;
    }
}
