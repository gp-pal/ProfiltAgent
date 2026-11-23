# News page fixtures (excerpt fixtures)

**The text in these files is invented.** Each file started as the markdown of a real LT
news-portal page, captured on 2026-08-17 through the shipped path —
`App\Tools\HttpArticleClient::fetch()` for the HTML, then the runner's `POST /markdown` via
`App\IO\RunnerClient::markdown()` — and was then rewritten token by token so that no article
text, headline, name or URL path of the original remains (a portal's own host name does
where its labels are three letters or shorter, e.g. `sc.bns.lt`):

- every alphanumeric token was replaced by an invented one of the **same length and letter
  case** (one- to three-letter lower-case words and bare numbers are left alone);
- tokens of five or more characters had their first five characters replaced through a
  one-to-one mapping, so two words share a five-character stem after the rewrite exactly
  when they did before — which is the only thing `ArticleExcerpt::restates()` compares;
- markdown syntax, whitespace, punctuation and block boundaries are untouched.

What survives is therefore exactly what the excerpt rule reads: where the chrome sits, how
long each block is, how much of it is link markup, which headings are linked, and which
blocks restate the heading above them. What does not survive is anything a person could
read. The defect these files pin (`docs/architecture/news-excerpt.md`) was caused by what
real portals render above their articles, and hand-written markdown reproduces none of that
— which is why the files are rewrites of captures rather than pages written from scratch.

The file names keep the portal each shape came from and a neutral label for the page.

Each file is a **slice of whole blank-line-separated blocks**, cut from the head of the
capture so it reaches past the chrome and into the body. No block was added or dropped, and every slice
was verified to produce the same excerpt as its full capture — **except
`kaunodiena-short-body.md`, which is cut short on purpose** and yields 211 characters where the
longer slice of the same page yields 596. That is what makes it a test of a short article.

| File | Size (original capture) | Portal | Slice |
| --- | --- | --- | --- |
| `lrytas-logistikos-centras.md` | 1.5 kB | `lrytas.lt` | head |
| `bns-strategija.md` | 2.5 kB | `sc.bns.lt` | head |
| `15min-retailer-prices.md` | 4.6 kB | `15min.lt` | head — carries the mid-article tag row |
| `15min-pastomatai.md` | 3.2 kB | `15min.lt` | head — carries the press-release disclaimer |
| `delfi-siuntu-apimtys.md` | 1.6 kB | `delfi.lt` | head — furniture between paragraphs |
| `kaunodiena-pasto-skyriai.md` | 4.9 kB | `kauno.diena.lt` | head — no `# ` heading anywhere |
| `diena-store-closing.md` | 4.2 kB | `diena.lt` | head — no `# ` heading anywhere |
| `15min-topic-page.md` | 2.5 kB | `15min.lt` | head — a topic page, no article body |
| `delfi-topic-page.md` | 3.0 kB | `delfi.lt` | head — a topic page with its own blurb, then a neighbouring article |
| `tv3-carrier-trucks.md` | 3.6 kB | `tv3.lt` | see below |
| `tv3-split-link-block.md` | 0.5 kB | (same page) | one block, verbatim |
| `kaunodiena-short-body.md` | 4.2 kB | (same page as `kaunodiena-pasto-skyriai`) | a shorter head slice, cut after the article's FIRST body paragraph — a 211-character news brief, the shape the deferral fallback exists for |

`tv3-carrier-trucks.md` is the one file not cut from the head alone: tv3 renders ~400
navigation blocks (13.6 kB) above its heading, and committing all of them would be a
180 kB fixture. It keeps the first 10 navigation blocks, the split-link block the converter
leaves mid-URL (`](/cdn-cgi/l/email-protection…`, which a length-only rule accepts as
prose), and every block from the article heading into the body.

`tv3-split-link-block.md` is that same split-link block on its own, because on the tv3 page
the heading anchor already skips it — the guard against it only matters on a portal that
renders no heading, and this file is how that is tested without inventing a page.

The two `-tema-` pages are here because a topic page is a real search result and a real
mention with no article on it — the case where the rule must return nothing rather than the
portal's furniture.

Seventeen pages were captured and measured in total; the ten here cover all seven portals.
The per-page numbers and the URLs of all seventeen are in
`docs/architecture/news-excerpt.md`, so the full measurement can be re-run by re-fetching.

## The `spliced-*` files are CONSTRUCTED

Every block in them comes from the files above; the **order is not a page anyone
served**, and each exists because the shape it carries is absent from all 17 captured pages
while being ordinary layout on the live web. Without them the rule's guards are untestable,
and an untested guard is one a later change removes without anyone noticing.

| File | Built from | The one guard it isolates |
| --- | --- | --- |
| `spliced-teaser-cluster-in-chrome.md` | `kaunodiena-pasto-skyriai` with Delfi's real teaser pair (its `##### [headline](…)` and the summary under it) inserted after the last teaser heading | attribution — the teaser's paragraph runs straight into kauno.diena's body, so size cannot separate them |
| `spliced-unrelated-teaser-in-chrome.md` | the same page with Delfi's summary paragraph alone, inserted after an early teaser heading | size — nothing attributes the paragraph to the heading above it, but the next heading ends its run |
| `spliced-teaser-below-heading.md` | `lrytas-logistikos-centras` with Delfi's summary paragraph between the headline and the lead | none — this is the shape the rule provably cannot judge, and the test pins the leak so it stays visible |
| `spliced-teaser-above-heading.md` | a teaser heading plus an unrelated real paragraph (kauno.diena's body) above lrytas's headline | the anchor taking level 1 — with the heading above it excluded, neither other guard applies |
| `spliced-tv3-headline-then-later-prose.md` | `tv3-carrier-trucks` with kauno.diena's body paragraphs appended | boundaries being level 2+ — tv3's own headline is a link, and the appended prose stands in for the later prose every full tv3 page carries |
| `spliced-two-deferred-candidates.md` | `kaunodiena-short-body` plus a real teaser heading and Delfi's topic blurb | which deferral wins — the earliest, not the last |
| `spliced-short-body-then-later-prose.md` | `kaunodiena-short-body` plus a real teaser heading and tv3's 1.4 kB lead | **a cost, not a protection**: the short body this rule drops when a long run of other prose follows it |
| `spliced-unrelated-teaser-above-body.md` | `kaunodiena-pasto-skyriai` with Delfi's summary paragraph in the LAST chrome slot | **a leak, not a protection**: the position neither guard reaches |
| `spliced-unrelated-teaser-in-diena-chrome.md` | the same shape on `diena-store-closing`, whose own body run is 677 characters | the run-length bar from above — diena's body is the shorter of the two and therefore the real ceiling |
| `spliced-run-length-on-the-threshold.md` | Delfi's 303-character summary plus BNS's 293-character paragraph, then a real teaser heading | the run-length arithmetic: 303 + 2 + 293 = 598, two characters under the bar |

Three of the ten pin what the rule *costs* rather than what it protects — the dropped short
body, the deferral order, and the leak in the last chrome slot. A test that asserts a leak is
there so the leak stays visible and so closing it turns a red test green.

Keeping them honest: if any of these ever needs its expected excerpt changed, check
first whether the corresponding captured page still produces the same one. These files can
only say what the rule does with a shape; the captured files are the ones that say what it
does with the web.
