# Known limitations

What ProfiltAgent does not do, or does imperfectly, in the current release — and how to work
around each item. Every entry has a stable anchor (`#d1`, `#d2`, …) that other pages link to.

For the vocabulary used here (`verified` / `unverified` / `unknown`, `supplied` / `found` /
`not_found`, the news search states) see [concepts.md](./concepts.md). For the response
fields see [api/bootstrap-api.md](./api/bootstrap-api.md).

| Anchor | Limitation | Area |
|---|---|---|
| [D1](#d1) | `verdict` and `coverage` ignore match verification | Verification |
| [D2](#d2) | `open_web` is match-judged on its extracted fields only | Verification |
| [D3](#d3) | The match judge fails open during an LLM outage | Verification |
| [D14](#d14) | `official_site` data with no match verdict is accepted | Verification |
| [D4](#d4) | No bulk or scheduled verification pass | Verification |
| [D5](#d5) | A verification pass cut short by the time budget is not flagged | Verification |
| [D18](#d18) | A kept value can be verified by a second source that agrees | Verification |
| [D13](#d13) | People and news carry no stored verification record | People and news |
| [D12](#d12) | A caller cannot state that a person has no title | People and news |
| [D9](#d9) | Invalid UTF-8 in scraped news text fails the run | People and news |
| [D20](#d20) | News excerpts can still contain non-article text | People and news |
| [search throttling](#search-throttling) | Many searches in a short time make news searches go blind | People and news |
| [D10](#d10) | `field_status: supplied` can refer to an earlier run | Collection |
| [D11](#d11) | The request console does not normalise the domain | Collection |
| [D17](#d17) | Long pages are truncated from the top before extraction | Collection |
| [D6](#d6) | A `#` in an unquoted `.env` value truncates it | Operations |
| [D22](#d22) | `RUNS_DIR` has no effect on the orchestrator | Operations |

---

## Verification

<a name="d1"></a>
### D1 — `verdict` and `coverage` ignore match verification

The run verdict is computed from field coverage alone: `sufficient` means every declared
field is filled, not that every field was confirmed to belong to the target company. A field
accepted without a working match check (`verified: false`, for example basis
`judge_unavailable`) scores the same as a confirmed one and still counts toward
`sufficient`.

**Workaround.** Read `field_verification` (per field) and `match_verdicts` (per source) in
the response and apply your own policy — for example, do not auto-merge a field whose status
is `unverified` or `unknown`. See [integration-guide.md](./integration-guide.md).

<a name="d2"></a>
### D2 — `open_web` is match-judged on its extracted fields only

For most sources the match judge receives the page's domain and a content sample. The
`open_web` source passes neither: the judge sees only the fields that were extracted and has
to decide from those whether they belong to the target company. (`contacts` passes a content
sample of its first mined page, but no domain.)

**Cost.** A `pass` verdict on an `open_web` contribution is weaker evidence than a `pass`
elsewhere. Data about a related entity — a parent group's website filed under
`social_links`, say — can pass.

**Workaround.** Treat fields whose `field_sources` entry is `open_web` as lower-confidence
even when verified. Sending `code` and, when you know it, `domain` lets the stronger sources
(registry, official site) fill the fields first.

<a name="d3"></a>
### D3 — The match judge fails open during an LLM outage

Only a definite `fail` verdict rejects a source. When the judge is disabled or the LLM call
errors, the source is accepted and recorded as `unverified` with basis `judge_unavailable`
and the underlying error as the reason. This is deliberate: failing closed would stop all
collection whenever the LLM provider is unavailable.

**Cost.** During an outage, data for the wrong company can enter a profile. It is labelled,
but still stored and still scored ([D1](#d1)).

**Workaround.** Check `match_verdicts[*].verified` in each response; the admin panel marks
the affected sources and fields "match not verified". Re-run the company with `verify_fields`
once the LLM is reachable again. Unverified acceptances are also logged at `warning` level.

<a name="d14"></a>
### D14 — `official_site` data with no match verdict is accepted

The `official_site` source reuses the verdict its own profile-building step produced. If the
built profile carries no verdict at all — typically a profile restored from artifacts of an
older run — the contribution is accepted and recorded as `unverified`, basis `no_verdict`.

Cost and workaround are the same as for [D3](#d3).

<a name="d4"></a>
### D4 — No bulk or scheduled verification pass

`verify_fields` is a per-request option: a parameter of `POST /api/run` and the checkbox
"Verify the match of fields already collected" in the request console. Nothing schedules it,
and `agent:enrich-firms` does not set it.

**Cost.** Values stored before verification records existed stay `unknown` until someone
re-runs that company with `verify_fields`. For an already complete profile a normal run
stops after the first source, so `unknown` never clears by itself.

**Workaround.** Treat `unknown` as "not verified". To clear it for specific companies, call
`POST /api/run` with `verify_fields: true` for each one from your own loop, at a low request
rate (see [search throttling](#search-throttling)). Operator steps are in
[deploy-runbook.md](./deploy-runbook.md).

<a name="d5"></a>
### D5 — A verification pass cut short by the time budget is not flagged

The source ladder stops when its wall-clock budget is spent (`REACT_LADDER_BUDGET_S`,
default 120 s; the first source always runs). A `verify_fields` pass that runs out of time
before reaching the later sources still returns `verdict: sufficient`, because nothing is
missing, and `field_status` does not change either.

**Cost.** A field still `unknown` after a pass means either "no source re-found this value"
or "the pass did not finish", and the response does not say which.

**Workaround.** Compare `sources_used` with what you expect for that company; if the later
sources are absent, repeat the pass. Raising `REACT_LADDER_BUDGET_S` makes cut-offs rarer at
the price of longer requests.

<a name="d18"></a>
### D18 — A kept value can be verified by a second source that agrees

When a later source contributes a value identical to the one already stored (lists are
compared as sets), the stored value gains that source's verification record even though the
value itself was not replaced. A source that contributes a *different* value never verifies
the stored one.

This is intended. As a result `field_sources` and `field_verification.source` can name
different sources for the same field.

---

## People and news

<a name="d13"></a>
### D13 — People and news carry no stored verification record

`field_verification` covers the declared profile fields only. People and news are stored
beside them without a per-item record of what established that they belong to this company.

**Cost.** For a stored person you can see the `source`, but not whether that source passed a
match check. The request console shows "match not verified" next to people from an
unverified source for the run it has just executed; the stored profile cannot answer the
question later.

**Workaround.** If you need this, keep the run's `match_verdicts` together with the people
list at the time you receive the response.

<a name="d12"></a>
### D12 — A caller cannot state that a person has no title

For `people[]`, a missing `role`, `null`, an empty string and whitespace all mean "role not
stated". A role already stored for a person is kept when a later list names the same person
without one.

**Cost and workaround.** A stored role outlives its truth until a caller or a source states
a different one; it cannot be cleared to "none". Send the person's current role explicitly, or omit the person from the list
— the incoming list is authoritative about who is present.

<a name="d9"></a>
### D9 — Invalid UTF-8 in scraped news text fails the run

News mentions and people are stored as JSON. If a scraped title or excerpt contains a byte
sequence that is not valid UTF-8, saving it throws.

**Cost.** The run is closed as `failed` and `POST /api/run` returns an error, although the
profile fields collected earlier in the same run were already saved. The news and people
from that run are not stored. In the request console the error is shown as a "Request
failed" notification.

**Workaround.** Read the stored profile — the coverage fields are there. Re-running usually
fails the same way while the offending article is still among the results.

<a name="d20"></a>
### D20 — News excerpts can still contain non-article text

Excerpts are cut from the article body rather than the top of the page (see
[architecture/news-excerpt.md](./architecture/news-excerpt.md)). Remaining limits:

- A portal whose teaser blocks share no vocabulary with their headlines can have a teaser
  for a different article taken as the excerpt.
- The threshold that rejects markup-heavy blocks is tuned on a small set of captured pages;
  a new portal layout may fall between the tested cases.
- A change in how the runner converts HTML to Markdown can degrade excerpts without any
  test failing.

**Workaround.** Treat `excerpt` as a hint and use the mention's URL as the evidence.

<a name="search-throttling"></a>
### Many searches in a short time make news searches go blind

Upstream search engines behind the bundled SearXNG instance suspend a client that issues
many queries in quick succession; the suspension can last up to an hour. Searches then
return nothing.

**Cost.** News is missing for runs made during the suspension. The run view and profile view
show a warning that the news search was not answered instead of reporting "no mentions", but
the data is still absent.

**Workaround.** Pace batch work. Before trusting a negative news result, check the news
search state on the run and the SearXNG container logs; `php artisan agent:search-engines`
and `agent:search-probe` report on the search backend.

---

## Collection

<a name="d10"></a>
### D10 — `field_status: supplied` can refer to an earlier run

`field_status` reports `supplied` for any field whose stored provenance is `caller`. That
provenance persists on the profile, so a field supplied through `known_fields` in an earlier
run is still reported as `supplied` on later runs whose caller sent nothing.

**Cost and workaround.** `supplied` does not reliably mean "this request sent it". Decide
from your own request payload which fields you supplied.

<a name="d11"></a>
### D11 — The request console does not normalise the domain

`POST /api/run` lower-cases `domain` and strips a leading `http://` or `https://` and a
trailing slash; a value that still contains a path is rejected as invalid, and a `www.`
prefix is kept. The request console applies none of this. Selecting a company from the
registry prefills the domain from the registry's website column verbatim, which may be a
full URL such as `http://www.acmena.example/`.

**Cost and workaround.** A console run started with such a value may fail to capture the
official site. In the console, edit the prefilled domain down to the bare host
(`acmena.example`) before running. Over the API, send a bare host.

<a name="d17"></a>
### D17 — Long pages are truncated from the top before extraction

`open_web` (4,000 characters per page, 12,000 in total), `contacts` (the 4,000-character
sample given to the match judge) and the official-site profile builder (12,000 characters of
page text) keep the beginning of each converted page and discard the rest.

**Cost.** On pages that open with long navigation or cookie text, the relevant content may
fall outside the kept part, so a field present on the page can be reported `not_found`. The
size of this effect has not been measured for these sources.

**Workaround.** Supply values you already hold through `known_fields`.

---

## Operations

<a name="d6"></a>
### D6 — A `#` in an unquoted `.env` value truncates it

In `orchestrator/.env`, an unquoted `#` preceded by whitespace starts a comment. An
`AGENT_API_KEY` (or any other secret) containing one may be silently cut short.

**Cost and workaround.** Clients sending the full key get `401` with no explanation. Generate keys with `openssl rand -hex 32`, as `.env.example` suggests, or
wrap the value in double quotes.

<a name="d22"></a>
### D22 — `RUNS_DIR` has no effect on the orchestrator

`RUNS_DIR` appears in `orchestrator/.env.example` and `config/app.php` (`app.runs_dir`), but
no orchestrator code acts on it: artifacts are written through the Laravel `local` disk
under `runs/`. The variable is load-bearing only for the **runner**, where it must match the
shared volume mount (`/app/runs` in `docker-compose.yml`) so the orchestrator can find the
screenshots.

**Cost and workaround.** Changing it for the orchestrator does not move artifacts; changing
it for the runner without changing the volume mount breaks screenshot capture. Leave both at their defaults. To relocate artifacts, change the volume
mapping in `docker-compose.yml`.

---

## Related documents

- [concepts.md](./concepts.md) — the terms used on this page.
- [integration-guide.md](./integration-guide.md) — how to call the API and read the response.
- [admin-panel.md](./admin-panel.md) — where these states appear in the admin panel.
- [deploy-runbook.md](./deploy-runbook.md) — deployment and operator procedures.
