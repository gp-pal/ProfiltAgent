# HTTP API reference

ProfiltAgent exposes one working endpoint, `POST /api/run`, and one health endpoint.
A request names a company; the response is the company's accumulated profile together
with everything needed to judge how far to trust it.

For a task-oriented introduction see the [integration guide](../integration-guide.md);
for the vocabulary used below (`verified`, `supplied`, `data_limited`, …) see
[concepts](../concepts.md).

## Base URL

```
http://localhost/api
```

In the Docker stack the nginx container publishes host port 80 and is the only HTTP
entry point. `http://localhost:8000/api` applies only when the Laravel app is served
directly on the host (`php artisan serve`).

## Authentication

`POST /api/run` requires a shared secret. Send it in either header:

```
X-Api-Key: <key>
Authorization: Bearer <key>
```

If both are present, `X-Api-Key` is the one that is checked. The value is compared with
the server's `AGENT_API_KEY` in constant time.

| Situation | Response |
|---|---|
| Key missing or wrong | `401` `{"error":"unauthorized"}` |
| `AGENT_API_KEY` empty on the server, environment `local` or `testing` | Authentication is disabled; the request is processed (a warning is logged) |
| `AGENT_API_KEY` empty on the server, any other environment | `503` `{"error":"server_misconfigured"}` — every request is refused until a key is configured |

The health endpoint is not authenticated.

## `POST /api/run`

Send JSON (`Content-Type: application/json`). Form-encoded bodies are also accepted,
but booleans must then be sent as `1` / `0` — the strings `true` / `false` fail
validation.

The request is synchronous: the response arrives when collection has finished. A run
that needs every source can take a few minutes; see
[Timeouts and cost](../integration-guide.md#6-timeouts-and-throughput).

### Parameters

| Parameter | Type | Required | Limits | Description |
|---|---|---|---|---|
| `company` | string | yes | 1–255 characters | Company name. |
| `code` | string | no | max 32; letters, digits, `-` | The company's registry code. **Always send it when you know it.** It keys the stored profile (without it the profile is keyed by a slug of the name, so two spellings become two profiles) and lets the registry source look the company up directly instead of guessing it from name and domain. |
| `domain` | string | no | max 255 | The company's website, if known. A leading `http://` / `https://` and a trailing `/` are stripped; what remains must be a bare hostname (`acmena.example`). When absent, the official-site source resolves the domain itself. |
| `fields` | array of strings | no | max 40 items; each max 64 characters, `^[a-z][a-z0-9_]*$` | The profile fields this run must fill — the *declared fields*. See [Declared fields](#declared-fields). |
| `known_fields` | object | no | max 40 keys | Values you already hold, keyed by field key. See [Known fields](#known-fields). |
| `people` | array of objects | no | max 10 items | Directors or executives you already know, each `{name, role?}`. `name` is required: max 120 characters; letters, combining marks, whitespace, `.`, `'`, `-`. `role` is optional, max 60 characters. See [People](#people). |
| `verify_fields` | boolean | no | default `false` | Run the sources even when every declared field is already filled, so they can establish match verification for stored values. See [Verification pass](#verification-pass). |
| `session` | string | no | max 36; letters, digits, `-` | Your correlation id; also names the directory that holds this run's captured pages. A UUID is generated when absent. |
| `collect` | boolean | no | default `true` | `true` runs the collector described here. `false` runs the [raw page capture](#raw-page-capture-collect-false) instead. |
| `thinking` | boolean | no | default `false` | **`collect: false` only.** Ignored otherwise. |
| `dry_run` | boolean | no | default `false` | **`collect: false` only.** Ignored otherwise. |

Parameters not listed here are ignored.

### What a request does

With `collect` omitted or `true`, the request runs the collector. It walks four sources
in a fixed order —

1. `registry_db` — the company registry,
2. `official_site` — the company's own website,
3. `open_web` — open informational pages,
4. `contacts` — contact-focused search,

— merging each accepted source's fields into the company's stored profile. The first
source always runs. Before each later one the collector stops if every declared field
is filled (unless `verify_fields` is set) or if the wall-clock budget
(`REACT_LADDER_BUDGET_S`, default 120 seconds) is spent. There is no overall-score
threshold: a declared field is either filled or still missing.

After the sources, the collector stores the company's people and then searches news
portals. News runs on every request and never affects `coverage` or `verdict`.

The profile **accumulates across requests**: the response returns the stored profile,
including values contributed by earlier runs.

### Example

```bash
curl -X POST http://localhost/api/run \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: ${AGENT_API_KEY}" \
  -d '{
    "company": "Acmena, UAB",
    "code": "300000123",
    "known_fields": { "phones": ["+370 600 00000"] }
  }'
```

`200 OK`:

```json
{
  "run_hash": "b6d1a2b0-3f4e-4c9a-9d7f-2a1e6c8d5f10",
  "profile": {
    "phones": ["+370 600 00000"],
    "activity": "Biuro ir prekybos baldų gamyba",
    "address": "Pavyzdžio g. 1, LT-01100 Vilnius",
    "description": "Acmena designs and manufactures office furniture for companies across the Baltic states, from single desks to fully fitted workspaces.",
    "emails": ["info@acmena.example"],
    "social_links": ["https://social.example/acmena"],
    "people": [
      { "name": "Jonas Jonaitis", "role": "Vadovas", "source": "registry_db" }
    ],
    "news": {
      "score": 0.333,
      "fresh": true,
      "mentions": [
        {
          "portal": "naujienos.example",
          "title": "Acmena opens a second production line, says CEO Jonas Jonaitis",
          "url": "https://naujienos.example/verslas/acmena-opens-a-second-production-line",
          "date": "2026-10-02",
          "subject": "person",
          "person": "Jonas Jonaitis",
          "excerpt_state": "body",
          "excerpt": "Office furniture maker Acmena has started a second production line at its Vilnius plant. \"Orders from the Baltic states doubled in a year,\" said chief executive Jonas Jonaitis.",
          "artifacts": {
            "html": "runs/5d0c7c1e-8a52-4f0b-9a3e-1f2b6c7d8e90/acmena.example/analyze/news/acmena-opens-a-second-production-line-says-ceo-jonas-jonaitis.html",
            "markdown": "runs/5d0c7c1e-8a52-4f0b-9a3e-1f2b6c7d8e90/acmena.example/analyze/news/acmena-opens-a-second-production-line-says-ceo-jonas-jonaitis.md"
          }
        }
      ],
      "search_state": "answered",
      "people_searched": ["Jonas Jonaitis"],
      "people_dropped": [],
      "people_dropped_reasons": []
    }
  },
  "field_sources": {
    "phones": "caller",
    "activity": "registry_db",
    "address": "registry_db",
    "description": "official_site",
    "emails": "official_site",
    "social_links": "official_site"
  },
  "field_verification": {
    "description": { "status": "verified", "source": "official_site", "verdict": "pass", "basis": "stored_verdict", "at": "2026-11-23T09:41:38+00:00" },
    "activity": { "status": "verified", "source": "registry_db", "verdict": "pass", "basis": "registry_code", "at": "2026-11-23T09:41:12+00:00" },
    "emails": { "status": "verified", "source": "official_site", "verdict": "pass", "basis": "stored_verdict", "at": "2026-11-23T09:41:38+00:00" },
    "phones": { "status": "verified", "source": "caller", "verdict": "pass", "basis": "caller_supplied", "at": "2026-11-23T09:41:11+00:00" },
    "address": { "status": "verified", "source": "registry_db", "verdict": "pass", "basis": "registry_code", "at": "2026-11-23T09:41:12+00:00" },
    "social_links": { "status": "verified", "source": "official_site", "verdict": "pass", "basis": "stored_verdict", "at": "2026-11-23T09:41:38+00:00" }
  },
  "coverage": {
    "fields": {
      "description": 1.0,
      "activity": 0.75,
      "emails": 1.0,
      "phones": 1.0,
      "address": 1.0,
      "social_links": 1.0
    },
    "overall": 0.958,
    "missing": []
  },
  "sources_used": ["registry_db", "official_site", "news"],
  "verdict": "sufficient",
  "field_status": {
    "description": "found",
    "activity": "found",
    "emails": "found",
    "phones": "supplied",
    "address": "found",
    "social_links": "found"
  },
  "match_verdicts": {
    "registry_db": {
      "accepted": true,
      "verdict": "pass",
      "basis": "registry_code",
      "verified": true,
      "derived_from": null,
      "match_confidence": null,
      "data_coverage": null,
      "reasons": ["registry row keyed by company code"]
    },
    "official_site": {
      "accepted": true,
      "verdict": "pass",
      "basis": "stored_verdict",
      "verified": true,
      "derived_from": null,
      "match_confidence": 0.93,
      "data_coverage": 0.8,
      "reasons": ["company name and code appear on the site", "address matches the registry"]
    }
  }
}
```

What happened in this run: the caller supplied `phones`; the registry filled `activity`
and `address`; three fields were still missing, so the official site was captured and
filled them; nothing was missing any more, so `open_web` and `contacts` did not run;
news then found one article.

### Response keys

A successful collector response always has HTTP status `200` and exactly these keys.
There is no `ok` key — read `verdict`.

| Key | Type | Description |
|---|---|---|
| `run_hash` | string | UUID of this run in the run registry (visible in the admin panel). |
| `profile` | object | The stored profile: field values keyed by field key, plus `people` and `news` when the profile has them. See [`profile`](#profile). |
| `field_sources` | object | For each stored field, the source its value came from: `registry_db`, `official_site`, `open_web`, `contacts`, or `caller`. |
| `field_verification` | object | For each declared field the profile holds, what is known about the match verification of the stored value. See [`field_verification`](#field_verification). |
| `coverage` | object | `{fields, overall, missing}` over the declared fields. See [`coverage`](#coverage). |
| `sources_used` | array of strings | Sources whose contribution was accepted in this run, in order. `news` is appended when this run found at least one mention. |
| `verdict` | string | `sufficient`, `partial`, `data_limited` or `none`. See [`verdict`](#verdict). |
| `field_status` | object | Per declared field: `supplied`, `found` or `not_found`. See [`field_status`](#field_status). |
| `match_verdicts` | object | For each source that reached a match decision in this run, what its acceptance rested on. See [`match_verdicts`](#match_verdicts). |

#### `profile`

Field values are strings or arrays of strings, as the field catalogue declares them.
The profile may also hold fields that are not declared in this request — it is the
whole stored profile, not a projection onto `fields`.

`profile.people` — the people known to run the company, each
`{name, role, source}`. `role` may be `null`; `source` is `caller` or the source that
reported the person. Present once any run has stored people.

`profile.news` — the most recent news report **that found at least one mention**. The
key is absent until a run finds one, and a later run that finds nothing leaves the
earlier report in place.

| Key | Description |
|---|---|
| `score` | 0–1. Distinct portals among the mentions (three or more score full), reduced when no mention is recent or no mention has a date. |
| `fresh` | `true` when at least one mention is dated within the last 12 months. |
| `mentions` | The kept articles, newest first (at most 8 by default). |
| `search_state` | What the search backend did. See [News search states](#news-search-states). |
| `people_searched` | Names for which at least one query was answered. |
| `people_dropped` | Names that were not researched. |
| `people_dropped_reasons` | `[{name, reason}]`, `reason` one of `query_budget`, `time_budget`, `search_unanswered`, `unusable_name`. |

Each mention:

| Key | Description |
|---|---|
| `portal` | Domain of the news portal. Only portals on the configured whitelist are kept. |
| `title` | Article title as the search returned it. |
| `url` | Article URL. |
| `date` | Publication date `YYYY-MM-DD`, or `null` when none could be established. |
| `subject` | `company` or `person` — which kind of query found the article. |
| `person` | The person's name when `subject` is `person`, otherwise `null`. |
| `excerpt_state` | `body` — the article's own text was located and `excerpt` holds its start. `no_body_found` — the page was read but has no article body (a listing page, a paywall stub); there is then no `excerpt`. Absent — the text was not fetched. |
| `excerpt` | The start of the article body (up to 600 characters by default). Present only with `excerpt_state: body`. |
| `artifacts` | `{html, markdown}` — storage-relative paths of the saved article, when it was saved. |

Every mention, including one found by a person query, names the company in its title
or search snippet; an article that names only the person is not kept.

#### `coverage`

| Key | Description |
|---|---|
| `fields` | Score 0–1 per declared field. `description` and `activity` scale with text length (full at 120 and 40 characters); every other field scores 1 when it has a value and 0 when it does not. |
| `overall` | Mean of the per-field scores, three decimals. |
| `missing` | Declared fields scoring below the gap threshold (`REACT_COVERAGE_GAP_THRESHOLD`, default 0.5). A field supplied in `known_fields` in this request is never listed, whatever it scores. |

#### `verdict`

| Value | Meaning | Retry? |
|---|---|---|
| `sufficient` | `coverage.missing` is empty. | No need. |
| `partial` | Something is missing, and either a source failed with an error or the wall-clock budget ended the run early. | Yes. |
| `data_limited` | Something is missing although the sources ran to completion without error. The data is not publicly available. | No. |
| `none` | Something is missing and no source's contribution was accepted in this run. | Depends on the cause; check the run in the admin panel. |

News does not enter the verdict: a run can be `none` with `news` in `sources_used`.

#### `field_status`

One entry per declared field:

| Value | Meaning |
|---|---|
| `supplied` | The stored value's provenance is `caller` (`field_sources` reads `caller`). That is the case when this request sent the field in `known_fields`, **and also when an earlier request did** and no source has replaced the value since. `supplied` therefore does not prove that *this* request sent it. See [known limitations](../known-limitations.md#d10). |
| `found` | The field is not in `coverage.missing` and its provenance is a source — filled in this run or an earlier one. |
| `not_found` | The field is missing and the run was not cut short by the wall-clock budget. |

A field that is missing because the budget ran out has **no entry**: reporting
`not_found` would state that the data does not exist when the run merely stopped
looking.

#### `field_verification`

`field_sources` says where a stored value came from; `field_verification` says what was
established about that source's claim to be this company. One entry per declared field
the profile holds, always with the same five keys:

| Key | Description |
|---|---|
| `status` | `verified`, `unverified` or `unknown`. |
| `source` | Who established the verification: a source name or `caller`. Usually equal to `field_sources`, but a verified source that contributes an identical value corroborates a stored value it does not own. `null` when `unknown`. |
| `verdict` | `pass`, `warn`, `fail` or `unverified`. `null` when `unknown`. |
| `basis` | How the verdict was reached — the same vocabulary as in [`match_verdicts`](#match_verdicts). `null` when `unknown`. |
| `at` | ISO 8601 timestamp of the record. `null` when `unknown`. |

The three states:

- **`verified`** — a source accepted on a real match verdict established this value.
  Registry values (`basis: registry_code`) and values supplied by a caller
  (`basis: caller_supplied`) are verified by definition.
- **`unverified`** — the value's source was accepted with nothing verifying it: the
  match judge was unreachable or produced no usable verdict. The value is stored and
  returned; treat it as unconfirmed.
- **`unknown`** — no verification record exists for this value. This is a statement
  about the records, not about the company: it applies to values stored before
  verification was recorded. `unknown` never means verified, and it does not change
  on its own.

Alert on `unverified`, not on `unknown`. `people` and `news` carry no verification
record of their own.

#### `match_verdicts`

Only the registry is certain of a company's identity, because it is keyed by company
code. Every other source is *found*, so its contribution is accepted or rejected on a
match verdict. The judge **fails open**: when it cannot answer, the contribution is
accepted anyway, and this object is where that becomes visible.

A source appears here when it returned data and a match decision was made. It is
absent when it was skipped, failed, or returned nothing. `news` never appears: it is
not match-gated.

| Key | Description |
|---|---|
| `accepted` | Whether the source's fields were merged into the profile. Only a definite `fail` rejects. |
| `verdict` | `pass`, `warn` or `fail` when something produced a verdict; `unverified` when nothing did. |
| `basis` | `registry_code` (registry row keyed by company code), `judge` (the match judge was asked for this source), `stored_verdict` (the official-site pipeline's own judge verdict), `caller_supplied`; or, when unverified, `judge_unavailable` (the judge errored or is disabled) or `no_verdict`. |
| `verified` | `false` when the decision rested on no working check. This is the value to filter on. |
| `derived_from` | Whose assertion the verdict rests on when it was not reached directly; otherwise `null`. |
| `match_confidence`, `data_coverage` | The judge's 0–1 scores; `null` when no judge answered. |
| `reasons` | Short strings naming the signals behind the verdict, or the error behind a fail-open. |

### Declared fields

The fields a run is measured against come from the request:

- `fields` present — those keys, filtered to the enabled field catalogue. An unknown or
  disabled key is dropped and logged; it is not an error. (A key that does not match
  the format is a `422`.)
- `fields` absent, or every key dropped — the catalogue fields marked *required*.

The catalogue is edited in the admin panel. As installed it holds twelve keys:

| Key | Type | Required by default |
|---|---|---|
| `description` | text | yes |
| `activity` | text | yes |
| `emails` | array | yes |
| `phones` | array | yes |
| `address` | string | yes |
| `social_links` | array | yes |
| `products_services` | array | no |
| `customer_segments` | array | no |
| `positioning` | text | no |
| `recent_developments` | array | no |
| `locations` | array | no |
| `brands` | array | no |

If the catalogue is empty, or nothing in it is marked required, the first six are used.

### Known fields

`known_fields` carries values you already hold so the collector does not search for
them.

- Only keys that are declared fields are used; the rest are ignored.
- A supplied field is recorded with `caller` provenance, is never searched for, and is
  not overwritten by any source in the same run. It replaces a previously stored value
  even when that value was longer.
- A value is coerced to the field's catalogue type: a string sent for an array field
  is split on commas; an array sent for a text field is joined with `, `.
- Entries that cannot be used are dropped silently rather than rejected: an empty
  value, a string longer than 2000 characters, array elements longer than 2000
  characters, and array elements beyond the first 50.

### People

Each person in `people` is stored with `caller` provenance and becomes a subject of the
news search, alongside people the registry reports. Caller entries take precedence
over registry entries for the same name.

A missing `role`, `null`, `""` and whitespace are all the same thing: no statement
about the role. A role-less entry therefore never erases a role already known for that
person, and there is no way to state that a person holds no title.

The news search has a small query budget, so not every person is necessarily
researched; `profile.news.people_searched` and `people_dropped` say who was.

### Verification pass

Normally a complete profile ends the run after the first source. Send
`verify_fields: true` to run the sources anyway so they can confirm stored values and
turn `unknown` into `verified`. It costs a full run — a browser capture and LLM calls.

- A field whose stored value no source finds again stays `unknown`.
- While the match judge is unavailable a pass changes nothing: an unverified source
  cannot confer verification.
- The pass is still bounded by the wall-clock budget and can stop part-way. `verdict`
  stays `sufficient` and nothing in the response says so; a shorter `sources_used`
  than usual is the only hint. If fields remain `unknown` after a pass, retry before
  concluding anything. See [known limitations](../known-limitations.md#d5).

There is no bulk verification; the flag applies to one company per request
([known limitations](../known-limitations.md#d4)).

### News search states

The news source classifies each run's search with one of four states:

| `search_state` | Meaning |
|---|---|
| `answered` | At least one query was answered and returned results from a whitelisted portal. |
| `unanswered` | No query got an answer from the search backend — it was rate-limited locally, unreachable, or returned an error. Nobody was asked anything. |
| `returned_nothing` | Every query was answered and every answer was an empty list — the signature of rate-limited or suspended upstream search engines. Whether any mention exists is unknown. |
| `off_portal` | Results came back, but none from a whitelisted portal: the portal filter was ignored by the engine that answered. Whether any mention exists is unknown. |

`unanswered`, `returned_nothing` and `off_portal` are recorded as a failed news step,
not as "no news". Because `profile.news` is replaced only by a report that contains
mentions, the value you read in the API response is `answered`; the other three
states appear on the run's news step in the admin panel. A run that searched
successfully and found nothing about the company leaves `profile.news` untouched as
well. To tell whether *this* run found news, check for `news` in `sources_used`.

### Errors

These apply to every request to `POST /api/run`.

| Status | Body | Cause |
|---|---|---|
| `401` | `{"error":"unauthorized"}` | Key missing or wrong. |
| `503` | `{"error":"server_misconfigured"}` | No `AGENT_API_KEY` configured on the server (outside `local` / `testing`). |
| `422` | `{"ok":false,"error":"validation_failed","errors":{…}}` | The request failed validation. `errors` maps each offending parameter to a list of messages. |
| `500` | `{"ok":false,"error":"internal","details":"…"}` | An unexpected error. Also returned for a `domain` that is not a hostname after normalisation (`details: "Invalid domain format"`). |

Validation example:

```json
{
  "ok": false,
  "error": "validation_failed",
  "errors": {
    "company": ["The company field is required."],
    "fields.0": ["The fields.0 field format is invalid."]
  }
}
```

A collector run never returns `404`: a company with no website is an ordinary result,
reported through `verdict` and `field_status`.

### Client example

```javascript
async function collectProfile(company, code) {
  const response = await fetch('http://localhost/api/run', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Api-Key': process.env.AGENT_API_KEY,
    },
    body: JSON.stringify({ company, code }),
  });

  const body = await response.json();

  if (response.status !== 200) {
    // 401 / 503: { error }   422: { error, errors }   500: { error, details }
    throw new Error(`ProfiltAgent ${response.status}: ${body.error}`);
  }

  switch (body.verdict) {
    case 'sufficient':
      break;                      // every declared field is filled
    case 'partial':
      scheduleRetry(company, code); // a source failed or time ran out
      break;
    case 'data_limited':
    case 'none':
      break;                      // use what is there; see field_status
  }

  const unverified = Object.entries(body.field_verification)
    .filter(([, v]) => v.status === 'unverified')
    .map(([field]) => field);

  return { profile: body.profile, verdict: body.verdict, unverified };
}
```

## Raw page capture: `collect: false`

With `collect: false` the request skips the collector entirely. Nothing is read from or
written to the stored profile, no registry, open-web, contacts or news source runs, and
the response has a different shape. Instead the request runs the page-capture pipeline
— the same one the `official_site` source runs internally — once, and returns its raw
result: the captured homepage, the capture plan and the execution trace. The pipeline
is described in [Page-capture pipeline](../architecture/bootstrap-phase-one.md).

Everything in this section applies **only** to `collect: false`.

### Parameters used

`company`, `domain`, `session`, plus:

| Parameter | Description |
|---|---|
| `thinking` | Fill `events` with progress events and add `react_trace` to the response. |
| `dry_run` | Build and validate the capture plan but do not run the browser. Nothing is captured. |

`code`, `fields`, `known_fields`, `people` and `verify_fields` are validated but have no
effect.

### Idempotency

Captured pages are stored under `runs/{session}/{domain}/analyze/`. If that directory
already holds a non-empty `main.html` and at least one screenshot, the request returns
`done: true` without resolving, planning or running the browser again, and `profile` is
read back from the stored `profile.json`. This only applies when the request carries
both the same `session` and the same `domain`.

Two concurrent requests for the same `session` and `domain` do not both run: the second
gets `500` `internal` with `details: "Another run is already in progress for this
session/domain"`.

(The collector passes `session` through to the `official_site` source, so a collector
request that repeats a `session` and `domain` reuses the captured pages too. The
collector run as a whole is never short-circuited by it.)

### Response

```bash
curl -X POST http://localhost/api/run \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: ${AGENT_API_KEY}" \
  -d '{
    "company": "Acmena, UAB",
    "domain": "acmena.example",
    "session": "5d0c7c1e-8a52-4f0b-9a3e-1f2b6c7d8e90",
    "collect": false,
    "thinking": true
  }'
```

```json
{
  "ok": true,
  "done": true,
  "session": "5d0c7c1e-8a52-4f0b-9a3e-1f2b6c7d8e90",
  "domain": "acmena.example",
  "resolved_via": null,
  "paths": {
    "main_html": "/app/storage/app/runs/5d0c7c1e-8a52-4f0b-9a3e-1f2b6c7d8e90/acmena.example/analyze/main.html",
    "iframes_dir": "/app/storage/app/runs/5d0c7c1e-8a52-4f0b-9a3e-1f2b6c7d8e90/acmena.example/analyze/iframes",
    "screenshots_dir": "/app/storage/app/runs/5d0c7c1e-8a52-4f0b-9a3e-1f2b6c7d8e90/acmena.example/analyze/screenshots",
    "plans_dir": "/app/storage/app/runs/5d0c7c1e-8a52-4f0b-9a3e-1f2b6c7d8e90/acmena.example/analyze/plans",
    "logs_dir": "/app/storage/app/runs/5d0c7c1e-8a52-4f0b-9a3e-1f2b6c7d8e90/acmena.example/analyze/logs",
    "relative_base": "runs/5d0c7c1e-8a52-4f0b-9a3e-1f2b6c7d8e90/acmena.example/analyze"
  },
  "links_summary": { "total_links": 64, "unique_links": 51, "by_category": { "internal": 40, "external": 7, "mailto": 2, "tel": 2 }, "unique_domains": 6 },
  "structure": { "…": "contacts, social links and important links found in the homepage HTML" },
  "profile": { "…": "the profile extracted from the site, with its match verdict under quality" },
  "plan": {
    "version": "1.0",
    "meta": {
      "task": "Step-1 homepage capture",
      "company": "Acmena, UAB",
      "domain": "acmena.example",
      "stealth_mode": true,
      "human_behavior": true
    },
    "actions": [
      { "do": "goto", "url": "https://acmena.example", "timeout_ms": 20000 },
      { "do": "wait_for", "selector": "body", "timeout_ms": 8000 },
      { "do": "screenshot_fullpage", "path": "runs/5d0c7c1e-8a52-4f0b-9a3e-1f2b6c7d8e90/acmena.example/analyze/screenshots/home.png" },
      { "do": "extract_html", "selector": "body", "save_as": "main_html" },
      { "do": "extract_markdown", "save_as": "main_md" },
      { "do": "extract_iframe", "save_as": "iframes_html" },
      { "do": "extract_links", "save_as": "links_raw", "limit": 200 },
      { "do": "return", "data": ["main_html", "main_md", "links_raw"] }
    ]
  },
  "runner": { "…": "the browser runner's result: ok, data, artifacts, logs, and challenge when a bot wall was seen" },
  "trace": {
    "policy_caps": { "max_steps": 30, "max_timeout_ms": 20000, "runs_dir": "runs" },
    "schema": { "sha256": "…", "excerpt_bytes": 2000, "path": "…/resources/dsl/schema.json" },
    "prompts": {}
  },
  "events": [
    { "phase": "check_assets", "exists": false },
    { "phase": "plan.prompts_ready" },
    { "phase": "plan.built" },
    { "phase": "plan.validated", "repaired": false },
    { "phase": "runner.start" },
    { "phase": "runner.done", "ok": true },
    { "phase": "persist.done" },
    { "phase": "links.processed", "summary": { "…": "same as links_summary" } },
    { "phase": "structure.analyzed", "company_indicators": {} },
    { "phase": "secondary.crawled", "urls": ["https://acmena.example/about"] },
    { "phase": "profile.built", "name": "Acmena, UAB" },
    { "phase": "done", "value": true }
  ],
  "error": null,
  "details": null,
  "react_trace": null
}
```

| Key | Type | Description |
|---|---|---|
| `ok` | boolean | `false` when `error` is set. |
| `done` | boolean | `true` when `main.html` (non-empty) and at least one screenshot exist for this session and domain. With `dry_run` this is `true` only if they already existed. |
| `session` | string | The session id used. |
| `domain` | string \| null | The domain given or resolved. |
| `resolved_via` | string \| null | How a missing domain was resolved: `cache`, `react` or `heuristic`. `null` when the request supplied the domain. |
| `paths` | object | Absolute paths of `main_html`, `iframes_dir`, `screenshots_dir`, `plans_dir`, `logs_dir`, plus `relative_base`. The absolute strings omit the `private/` segment of the storage disk: the files are under `storage/app/private/runs/…`. Use `relative_base` against the application's local storage disk. |
| `links_summary` | object \| null | `{total_links, unique_links, by_category, unique_domains}` from the link inventory. |
| `structure` | object \| null | Contacts, social links and important links found in the homepage HTML. |
| `profile` | object \| null | The profile extracted from the site, including the match judge's verdict under `quality`. |
| `plan` | object \| null | The validated capture plan. |
| `runner` | object \| null | The browser runner's result. `null` on `dry_run` and when captured pages were reused. |
| `trace` | object | Policy limits and the plan schema's hash. |
| `events` | array | Progress events; empty unless `thinking` is `true`. |
| `error` | string \| null | Error code, see below. |
| `details` | string \| object \| null | Error detail. |
| `react_trace` | array \| null | Present only with `thinking: true`: the steps of the domain search, when one ran. |

### Errors

In addition to the [general errors](#errors). An error response carries the same keys
as a success, with `ok: false`, `error` set and `details` describing it.

| `error` | Status | Cause | `details` |
|---|---|---|---|
| `no_site_found` | `404` | No `domain` was sent and none could be resolved. | string |
| `validation_failed` | `422` | The capture plan failed schema or policy validation. (Distinguish from a request-validation `422` by the absence of `errors`.) | object `{initial_error, repair_error}`, or a string |
| `runner_failed` | `424` | The browser runner call failed. | object `{error, logs}` |
| `challenge_detected` | `424` | The browser runner call failed with a bot-challenge error. A challenge that the runner merely detects does not produce this error: it is recorded on `runner.challenge` and the capture continues. | string |
| `internal` | `500` | Unexpected error, or a concurrent run for the same session and domain. | string |

### Progress events

With `thinking: true`, `events` lists what the pipeline did, in order. Each event has a
`phase` and phase-specific keys; a phase that did not run does not appear.

| `phase` | Emitted | Extra keys |
|---|---|---|
| `check_assets` | After the check for existing captured pages. | `exists` |
| `react.step` | For each step of the domain search. | `step`, `text`, `tool_calls`, `tool_results` |
| `search.domain_resolved` | A missing domain was resolved. | `domain`, `via` |
| `plan.prompts_ready`, `plan.built` | The capture plan was prepared. | — |
| `plan.guardrail_drift` | The plan had to be rebuilt. | — |
| `plan.validated` | The plan passed schema and policy checks. | `repaired` |
| `extraction.skipped` | `dry_run` — the browser was not run. | — |
| `runner.start`, `runner.done` | Around the browser run. | `ok` on `runner.done` |
| `persist.done` | Captured pages were written. | — |
| `links.processed` | The link inventory was built. | `summary` |
| `structure.analyzed` | Homepage structure was analysed. | `company_indicators` |
| `secondary.crawled` | Additional pages of the site were fetched. | `urls` |
| `profile.built` | A profile was extracted. | `name` |
| `done` | Final check. | `value` |

## Health

```
GET /api/v1/agent/health
```

No authentication. Returns `200`:

```json
{
  "status": "healthy",
  "timestamp": "2026-11-23T09:41:00.000000Z",
  "services": {
    "react_loop": "available",
    "browser_runner": "http://browser_runner:8080"
  },
  "version": "0.1.0"
}
```

The response is static apart from the timestamp and the configured runner URL. It
confirms that the application answers HTTP requests; it does not probe the database,
the browser runner, the search backend or the LLM.

## Related documentation

- [Product overview](../overview.md)
- [Integration guide](../integration-guide.md)
- [Concepts](../concepts.md)
- [Known limitations](../known-limitations.md)
- [Page-capture pipeline](../architecture/bootstrap-phase-one.md)
