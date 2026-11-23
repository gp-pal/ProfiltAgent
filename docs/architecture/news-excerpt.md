# News mention excerpts — how the article body is located, and what that cannot do

`NewsSource` stores an `excerpt` on every news mention whose article it downloaded. That
excerpt is the only article text a consumer receives (`POST /api/run` returns the profile
on every run) and the only article text an operator sees in the request console. It is
therefore the evidence a mention is judged by: whether it is a real, relevant piece about
this company, or a search result that merely matched a name.

Implementation: `orchestrator/app/Services/ArticleExcerpt.php`, called from
`NewsSource::fetchArticles()`. Budget: `react.news.excerpt_chars` (600). Number of
articles downloaded for text per run: `react.news.extract_max` (5).

## Why the excerpt is located rather than cut

The obvious excerpt — the first 600 characters of the converted page — is almost entirely
page chrome. The runner's markdown conversion runs in main-content mode, but that mode is
**exclusion-based**: `EXCLUDE_NON_MAIN_SELECTORS` strips `header/footer/nav/aside` plus a
class and id blacklist. Whatever chrome a portal renders outside those tags survives, and it
survives *at the top of the output*: titles, banners, menus, breadcrumbs, share widgets and
teasers for other articles.

On the pages measured for this rule (see the [appendix](#appendix-the-pages-measured)) the
article's first body paragraph starts between 234 and 14 045 characters into the converted
page, and on more than half of them later than character 600. The article's own heading is not a
usable cut point either: the body does not start at the heading, and some portals render no
level-1 heading at all.

So the excerpt is extracted by locating the article body in the markdown and quoting from its
first paragraph, and a page with no locatable body yields no excerpt rather than its
navigation. The full conversion is always written verbatim to
`runs/{session}/{domain}/analyze/news/{slug}.md`; the excerpt is the readable evidence, the
artifact is the record.

## What the rule does

`ArticleExcerpt::fromMarkdown($markdown, $limit)` returns the start of the article's own
body, or `null` when the page has no locatable article body. "Measured" below refers to the
pages listed in the [appendix](#appendix-the-pages-measured).

Every block is sorted by one classifier into **article prose**, **furniture** (captions,
credits, breadcrumbs, share and subscribe widgets, menus) or a **boundary** — a
level-2-or-deeper heading whose whole text is a link, which is how every portal measured
announces a *different* article on the page. That single classifier is read at both ends of
the walk — when choosing where the excerpt opens and when extending it. Consulting the
boundary only while extending would leave a teaser above the body invisible, and it could
open the excerpt with a neighbour's first sentence.

1. **Anchor** at the first level-1 `# ` heading. On the pages measured a `# ` never appears
   in chrome, so everything above it is excluded — this is what skips tv3's 13.6 kB menu and
   BNS's banner stack. Level 1 only: `##`+ is used for subheads inside the article and for
   teaser headings, and anchoring on either would start the walk in the wrong place. Portals
   that render no `# ` (the diena.lt family) search from the top.
2. **Open** at the first prose block from the anchor down — at most 50 % of its characters
   inside link or image markup, at least 120 characters of text once images are dropped and
   links reduced to labels, and not a block that begins mid-link (the converter splits long
   links across blank lines).
3. **Below a boundary, demand more.** There the page is showing other articles, so a
   candidate must *also* (a) run on for `MIN_BODY_RUN_CHARS` (600) before the next boundary —
   a teaser gives one paragraph, an article body keeps going — and (b) not restate the
   headline directly above it, measured as shared 5-character word stems, which is what
   catches a teaser whose paragraph runs straight into the article body with no boundary
   between them. A candidate that fails either test is **remembered, not discarded**, and
   returned if no LATER candidate passes.
   That run length is its own constant and deliberately **not** the excerpt budget. If it
   were tied to `react.news.excerpt_chars`, raising the budget would weaken both guards: at
   900 the size test stops catching a teaser on the two portals whose bodies are 827 and 677
   characters, and at 2000 the attribution test goes with it. Pinned by
   `test_the_guards_do_not_weaken_when_the_excerpt_budget_is_raised`.
4. **Extend** with following prose blocks, skipping furniture rather than quoting it, until
   the budget is spent, and **stop at a boundary**.
5. **Normalise and trim**: images dropped, links reduced to their labels, whitespace runs
   collapsed, cut back to a word boundary when the cut lands inside a word and the boundary
   is within the last 20 % of the budget.

Why the two extra tests in step 3 rather than simply refusing to open below a boundary:
`kauno.diena.lt` and `diena.lt` render no `# ` heading and put a dozen other articles'
headings above the body, whose own first paragraph is block 23 and block 30 of the page.
Refusing to open below a boundary returns **null for both of those portals**, so it would
trade a confident wrong excerpt on a layout nobody has captured for no excerpt at all on two
of the seven portals measured.

| Constant | Value | What the captures actually say about it |
| --- | --- | --- |
| `MIN_PROSE_CHARS` | 120 | The shortest true lead paragraph measured is lrytas at 122. This is a floor against *furniture*, not against all chrome: a page `<title>` line passes it easily (BNS's is 148 characters at 0.00 markup) and only the `# ` anchor excludes those. The real measured headroom is the 15min photo caption at 104 — 16 characters from tripping — and it sits mid-walk, not at an opening. |
| `MAX_MARKUP_SHARE` | 0.5 | Loosely constrained, and worth knowing: the densest **true** body paragraph measured is Delfi at 0.44 (it links company names inside its sentences), and the only 120+ character block above that which must be rejected is the 15min tag row at 0.97. Everything between 0.44 and 0.97 is untested slack, and a Delfi lead a little denser than 0.44 would be dropped as markup. |
| `MIN_BODY_RUN_CHARS` | 600 | In characters: the one real teaser summary in the captures is 303, and the two real bodies that sit below boundaries are 677 (diena) and 827 (kauno.diena), so the usable window is **304…677** — bounded by the *smaller* body, not the larger. Both ends are pinned, each by a fixture built on the page that constrains it: 300 lets the teaser through, and 700 makes diena.lt ship a neighbour's words. |
| `MIN_RESTATED_STEMS` / `MIN_RESTATED_SHARE` / `STEM_LENGTH` | 2 / 0.33 / 5 | Thin evidence, stated as such: one real teaser pair (Delfi's headline and the summary under it, 3 of 7 stems shared) and two real counter-pairs (diena's and kauno.diena's own bodies directly under a neighbour's headline, 0 of 4 shared). **Only one direction is pinned.** Loosening any of the three (2 → 4, 0.33 → 0.5) fails a test, because it lets the real teaser through. Tightening them (2 → 1, 0.33 → 0.1, 5 → 3) does not, because both counter-pairs share zero stems, so nothing in the captures constrains the values from below; the cost of tightening too far is a deferral, which the fallback usually absorbs. |
| `WORD_BOUNDARY_FLOOR` | 0.8 | Below it, trimming back to the previous space costs more text than the ragged word is worth. |

### What the mention carries

| Key | Meaning |
| --- | --- |
| `excerpt` + `excerpt_state: body` | The article's words were located; `excerpt` is the start of them. |
| `excerpt_state: no_body_found`, no `excerpt` | The page was downloaded and converted, and no block in it reads as an article body. Storing its opening characters would mean storing navigation as evidence. |
| neither key | The text was never fetched or never converted (beyond `extract_max`, the fetch failed, or `POST /markdown` returned nothing). |

The request console renders the third state as silence and the second as a stated sentence —
"No article text was found on this page – the page was downloaded, but no article body was
recognised in it. See the downloaded text in the file." — because a blank cell reads as
"this article has no text" when the truth is "this page has no article". The sentence deliberately names no cause: `no_body_found` covers
a topic page, a paywall stub and a JS-only shell alike, and nothing in the state tells them
apart. The stored artifact path is shown whenever the mention carries one, independently of the
state — which is every case where the conversion succeeded, including the zero-budget one
below. Only a mention whose text was never fetched or never converted has no path to show,
because `writeNewsArticle()` runs after a successful conversion and nowhere else.

A fourth situation exists and claims neither: with `react.news.excerpt_chars` below 1 there
is no budget to carry any evidence, so the mention gets **no** `excerpt` and **no**
`excerpt_state`. Storing `''` beside `body` would assert located evidence and then render an
empty cell, and `no_body_found` would blame the page for a configured zero. The text is still
fetched, converted and stored as an artifact.

## What it cannot do

These are the known limits, measured or reasoned, in descending order of how likely they
are to bite. None of them is a reason to store page chrome instead.

1. **Portal boilerplate next to the lead is quoted.** 15min prints its press-release
   disclaimer ("„Pranešimas spaudai“ - tai naujienų agentūrų …") as an ordinary paragraph
   directly under the lead, and no structural signal separates it from the article. It takes
   404 of the 592 characters of the `15min-pastomatai` excerpt, against 186 of article. It is the page's own editorial
   text rather than navigation, so the excerpt is not a false claim — but it is a poor
   one. Pinned by
   `ArticleExcerptTest::test_a_portal_disclaimer_next_to_the_lead_is_quoted_and_that_is_a_known_limit`;
   an improvement here should invert that test.
2. **A bare paragraph between the headline and the lead is taken as the lead.** This is the
   one shape the rule provably cannot judge, and it is not confined to any portal. Below the
   anchor, a paragraph of 120+ characters with few links *is* what a lead paragraph looks
   like; if a portal moves a "susiję" / "populiariausi" blurb, a newsletter pitch or an
   editor's note between the headline and the first sentence, and it carries no teaser
   heading of its own, nothing in the markdown separates it from the article's own opening.
   The anchor is a floor, not a fence: it excludes what is *above* the headline and makes no
   claim about what follows it. Pinned as it stands by
   `ArticleExcerptTest::test_a_paragraph_directly_under_the_headline_reads_as_the_lead_and_that_is_a_known_limit`,
   using a real teaser paragraph spliced under a real headline; a fix should invert that
   test. Closing it needs a signal the page does not carry; the candidates
   are the mention's own title or the company's name tokens, both of which live in
   `NewsSource` and neither of which is safe as a hard gate (a lead that names neither is
   ordinary, and dropping it would cost real evidence).

   The classifier does not look at list markers (every list-shaped block in the captures is
   a breadcrumb of 23–33 characters, far under the 120 floor, so a marker test would reject
   nothing real), so this shape also covers a `* Title — summary…` row of 120+ low-markup
   characters under the headline. `plainText()` does not strip the marker, so the stored
   evidence would begin with `* `.
3. **What IS guarded below a boundary, where each guard runs out — and the leak where the two
   gaps meet.** Once the walk is
   below a teaser heading, two tests must both pass (see step 3 of the rule):
   - *size* catches a teaser whose paragraph is followed by another teaser heading before the
     600 characters are reached. It cannot catch one placed immediately above the article's
     body, because then teaser and body run together into one article-length stretch.
   - *attribution* catches that second case when the teaser echoes the headline above it,
     which a real teaser does. It cannot catch a paragraph that shares no vocabulary with the
     heading it sits under — a syndicated blurb, an advertisement, a different language.
   Both are pinned (`…teaser_in_the_chrome…`, `…stray_paragraph_in_a_teaser_region…`) with
   real blocks in a constructed order, because no captured page has this layout. On
   `kauno.diena.lt` and `diena.lt` — the two portals with no `# ` heading, whose bodies sit
   below a dozen teaser headings — these two tests are the *only* thing standing between a
   neighbour's words and the mention's evidence.

   **Where the two gaps overlap, the excerpt leaks.** A paragraph in the LAST chrome slot — directly above the body, with
   no heading of its own to be attributed to — is caught by neither test: nothing attributes
   it, and its prose plus the article's reach 600 characters together. The excerpt then opens
   on it with `excerpt_state: body`. Reachable, not theoretical: blocks #25–#29 of the
   kauno.diena capture are that slot, holding a 71-character newsletter pitch, and a
   template change that lengthens it past 120 characters makes it the excerpt of every
   kauno.diena mention. Pinned as a leak by
   `test_an_unattributed_paragraph_in_the_last_chrome_slot_still_leaks`.
4. **A short article body below a boundary is dropped when a long run of other prose follows
   it.** This is the cost of the size test. The fallback is not "nothing better on the page"
   but "no *later* candidate passes", and a later candidate that passes outranks the
   remembered earlier one. Measured on
   a constructed page — kauno.diena's own 211-character brief, a real teaser heading, then
   1.4 kB of other real prose:

   ```
   this rule            : opens on the later prose; the article's own words are absent
   first-600-characters : would open on the brief
   ```

   Both preconditions are ordinary. A body under 600 characters is a routine news brief, and
   what has to follow it is any prose reaching 600 before the next boundary — a comment
   thread, a syndicated block, two stacked teaser summaries. Scope: the two heading-less
   portals, plus any page with a teaser heading between the headline and the body. The trade
   is deliberate in that direction (a short body lost on a shape nobody has captured, against
   a neighbour's words shipped as evidence on a shape two portals render), but it *is* a
   trade, and `test_a_short_body_followed_by_a_long_run_of_other_prose_is_lost_and_that_is_the_trade`
   pins it so it cannot change silently.
5. **The fallback can hand back a teaser.** The same mechanism from the other side: a page
   whose *only* 120+ character prose block is a neighbour's teaser summary yields that summary
   rather than nothing. No captured page does this (`15min-topic-page` has no qualifying block
   at all and correctly yields nothing). Which deferral wins when several fail is the earliest,
   which is a choice rather than a measurement — right for a short body followed by comments,
   wrong for a page whose teasers carry bare summaries above a short body — and it is pinned
   (`test_the_earliest_deferred_candidate_wins`) rather than left to drift.
6. **"Excerpt present" does not mean "an article exists".** A topic page with its own
   descriptive paragraph — a delfi.lt topic page — yields that paragraph (322 characters
   about the company, real page text). It is not an article, and nothing downstream says
   so. Whether topic pages should be mentions at all is a search-layer question, not this
   one.
7. **The excerpt is normalised, not verbatim.** Links become their labels, images vanish,
   whitespace collapses. Byte offsets into the excerpt do not map to the artifact. The
   artifact is the verbatim record; the excerpt is the readable evidence.
8. **A paywalled article yields only what the page gives away.** `15min-retailer-prices` is
   part-paywalled: it hands out a lead and three paragraphs and marks the rest for
   subscribers. Whatever sits behind the wall never reaches the conversion, so the excerpt
   is as complete as the page we are allowed to read and no completeness can be promised.
9. **The rule reads markdown shape, so it inherits the runner's conversion.** A change to
   `htmlToMarkdown` / `onlyMainContent` in `runner/` can move where the body appears to
   start. The fixtures are the tripwire: they are captured *output*, so a conversion change
   that alters this behaviour will not be caught by them until they are re-captured. Treat
   a runner conversion change as a reason to re-run the capture.
10. **Locating a body says nothing about relevance.** That a page has an article does not
   mean the article is about the target company; that judgement is made elsewhere — the
   `CompanyNameTokens` gate on title and snippet inside `NewsSource`.

## The two budgets

`react.news.excerpt_chars` is 600 and `react.news.extract_max` is 5.

600 is sized for a profile that carries up to 8 mentions over HTTP on every run. Because the
excerpt starts at the body, the budget is spent on page text: on the 15 article pages
measured the excerpt fills 583–599 of its 600 characters — the article's own prose on 14 of
them, and on `15min-pastomatai` a 186-character lead plus 404 characters of the portal's
press-release disclaimer (limit #1 above).

Raising the excerpt budget does not weaken the below-a-boundary guards: they have their own
constant, and a test runs the guard fixtures at 300, 900 and 2000 characters.

`extract_max` is a ceiling on **excerpts, not on requests**: at most 5 articles have their
text extracted, so at most 5 excerpts exist, i.e. ≤ 3 kB of the profile. Downloads are not
bounded by it — publish dates are backfilled beyond the ceiling (`config/react.php`), so up
to `max_mentions` (8) articles can be fetched in a run. And because the counter that enforces
`extract_max` counts every fetch including the date-only ones, a run with several date-less
mentions produces **fewer** than 5 excerpts even when more articles were eligible for text.
That is a cost trade, not a defect, but it is not what the constant's name suggests.

## Re-measuring

```bash
cd orchestrator && TEST_TOKEN=$$ php vendor/bin/phpunit tests/Unit/ArticleExcerptTest.php
```

Twelve of the fixtures in `orchestrator/tests/Fixtures/news/` are captured output: eleven
slices of eleven pages, each reproducing the excerpt its full capture produced, plus
`kaunodiena-short-body.md`, which is deliberately cut short and does not — it stops after the
article's first body paragraph, which is how a short article is tested, and it yields 211
characters where the longer slice of the same page yields 596. The ten `spliced-*` files are
**constructed**: every block in them comes from those files, but the order is not a
page anyone served. They exist because the shapes that break this rule — a teaser in the
chrome, a paragraph under the headline, a linked headline with later prose below it — are
absent from the 17 pages captured, and a rule whose guards are untested is a rule that will
lose them. Three of them exist to pin costs rather than protections: the short body this rule
drops, which deferral wins when several fail, and the leak in the last chrome slot. Two more
pin arithmetic: the run-length window against the shorter of the two real bodies, and a run two
characters under the bar. Each says which guard it isolates in its test's comment, and
`tests/Fixtures/news/README.md` says how it was built.

To measure a portal that is not in the set, capture it the way the runtime does —
`HttpArticleClient::fetch()`, then `RunnerClient::markdown()` — and add a slice plus a row to
the provider in `ArticleExcerptTest::portalPages()`. Do not hand-write markdown for this rule:
the shapes that matter are ones real portals render and nobody would invent.

## Appendix: the pages measured

Seventeen article and topic pages from seven Lithuanian news portals (15min, BNS, Delfi,
diena, kauno.diena, lrytas, tv3), captured the way the runtime does — a plain HTTP GET
through `HttpArticleClient`, then `POST /markdown` — because it is that conversion, not the
raw HTML, that the rule is about. The page labels name the portal and a neutral description
of the page. Offsets are in characters of the converted markdown; `committed` marks the pages
kept as test fixtures.

| Page | md size | `# ` heading at | body starts at | excerpt (page text) | committed |
| --- | --- | --- | --- | --- | --- |
| `15min-retailer-prices` | 6284 | 280 | 762 | 584 chars | yes |
| `15min-pastomatai` | 5457 | 253 | 337 | 592 chars | yes |
| `15min-topic-page` | 6400 | 47 | none | **none** | yes |
| `bns-retailer-wages` | 12592 | 1106 | 1313 | 583 chars | no |
| `bns-carrier-software` | 16124 | 1108 | 1233 | 593 chars | no |
| `bns-strategija` | 9977 | 1156 | 1359 | 589 chars | yes |
| `delfi-retailer-layoffs` | 5372 | 209 | 886 | 592 chars | no |
| `delfi-siuntu-apimtys` | 2567 | 200 | 558 | 594 chars | yes |
| `delfi-topic-page` | 18621 | 105 | 234 | 322 chars | yes |
| `diena-store-closing` | 4713 | none | 3378 | 597 chars | yes |
| `kaunodiena-pasto-skyriai` | 12599 | none | 3874 | 596 chars | yes |
| `kaunodiena-postal-regulator` | 12985 | none | 3930 | 598 chars | no |
| `lrytas-carrier-fine` | 2329 | 421 | 527 | 598 chars | no |
| `lrytas-logistikos-centras` | 4291 | 420 | 524 | 595 chars | yes |
| `lrytas-store-closing` | 4462 | 419 | 522 | 594 chars | no |
| `tv3-carrier-split` | 21121 | 13583 | 14018 | 592 chars | no |
| `tv3-carrier-trucks` | 22689 | 13580 | 14045 | 599 chars | yes |

The excerpt column is **page text, not guaranteed article text**. On 14 of the 15 article
pages it is the article's own prose from its first sentence on; the exception is
`15min-pastomatai` (limit #1 above).

Ten of the pages are kept as slices under `orchestrator/tests/Fixtures/news/`. **Their text
is not the portals' text**: each committed file is a token-by-token rewrite that keeps every
length, block boundary, link and five-character word stem of the capture and replaces every
word with an invented one (`tests/Fixtures/news/README.md` describes the rewrite). The
offsets and character counts in this document were measured on the original captures and
hold for the rewritten files, because the rewrite changes no length.
