# Page-capture pipeline

This page describes the state machine that captures a company's website: it resolves
the official domain, renders the homepage in a headless browser, stores the rendered
page, and extracts a company profile from it.

**Where it runs.** This pipeline is what the `official_site` source runs. A normal
`POST /api/run` request runs the collector, which calls this pipeline as one of its
four sources and merges the extracted profile into the stored company profile. The
pipeline's own result is returned directly by `POST /api/run` only when the request
sets `collect: false`. See the [HTTP API reference](../api/bootstrap-api.md) for both
response shapes and the [product overview](../overview.md) for the collector as a
whole.

The pipeline is implemented by `ReActLoopService::runStep1Bootstrap()` in the
orchestrator (the Laravel application) and by the browser runner (a Node.js service
driving Playwright).

## Inputs and outputs

Input: `{company, domain?, session}` plus two options, `thinking` (record progress
events) and `dry_run` (do not run the browser). When called by the collector it also
receives the known facts about the company (name, code, address, …) and the list of
declared profile fields.

Output, all under `runs/{session}/{domain}/analyze/`:

- the rendered homepage as HTML and as markdown, same-origin iframe HTML, and a
  full-page screenshot;
- a link inventory;
- a structure analysis, the text of a few secondary pages, and an extracted company
  profile with a match verdict;
- the capture plan and execution logs.

## State machine

```
CHECK_ASSETS → RESOLVE_DOMAIN → PLAN_BUILD → PLAN_VALIDATE → RUN_OR_DRY_RUN → PERSIST
  → LINKS → STRUCTURE → SECONDARY_CRAWL → PROFILE_BUILD → SATISFACTION
```

The phases run in this order, once. A phase that sets an error ends the run: later
phases are skipped and the result carries the error code. Before the first phase a
lock is taken for the session and domain; a second run for the same pair while the
lock is held (it expires after 10 minutes) is refused with `internal`.

### 1. CHECK_ASSETS

Creates the directory tree, then checks whether the capture already exists: a
non-empty `main.html` and at least one file in `screenshots/`.

If it does, the run is marked done and RESOLVE_DOMAIN through PERSIST are skipped. The
previously extracted `profile.json` is read back so the result still carries the
profile. This is what makes a repeated request with the same `session` and `domain`
cheap.

### 2. RESOLVE_DOMAIN

Runs only when no domain was given. Three steps, first hit wins:

1. **Cache.** A domain resolved for the same company name within the last 24 hours.
2. **`ReActDomainResolver`.** An LLM tool loop over the self-hosted SearXNG instance.
   The model can search the web, visit a page (fetched as markdown with its internal
   links) and confirm a domain; it is expected to confirm only a site it has visited,
   checking the About or Contact page when the homepage links to one. The loop is
   limited to `REACT_MAX_STEPS` steps (default 12). Enabled by `REACT_SEARCH_ENABLED`
   (default on).
3. **`SearchDdgService`.** A heuristic fallback, used only when the tool loop is
   disabled, fails or confirms nothing: Lithuanian-biased DuckDuckGo queries, scored on
   brand match in the domain and title, the `.lt` TLD and "official" cues. Because the
   heuristic has no identity check of its own, the candidate's homepage is fetched and
   passed to the company-match judge, and a definite mismatch is discarded.

Business directories and aggregators are filtered out at every step.

On success the domain is cached, the working directory is moved from
`runs/{session}/pending/` to `runs/{session}/{domain}/`, and the result records how
the domain was found in `resolved_via` (`cache`, `react` or `heuristic`). What the
search surfaced is written to `logs/search_results.json`, and the tool loop's steps to
`logs/react_trace.jsonl`.

If nothing is found the run ends with `no_site_found`. For the collector this is an
ordinary outcome — a company without a website — not a failure.

### 3. PLAN_BUILD

Produces the capture plan: a JSON document in the plan DSL (below). The plan is a
**fixed template** with the company, domain and screenshot path filled in; it is not
written by the LLM, so the same input always yields the same plan:

```json
{
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
    { "do": "screenshot_fullpage", "path": "runs/{session}/acmena.example/analyze/screenshots/home.png" },
    { "do": "extract_html", "selector": "body", "save_as": "main_html" },
    { "do": "extract_markdown", "save_as": "main_md" },
    { "do": "extract_iframe", "save_as": "iframes_html" },
    { "do": "extract_links", "save_as": "links_raw", "limit": 200 },
    { "do": "return", "data": ["main_html", "main_md", "links_raw"] }
  ]
}
```

The plan is saved as `plans/initial.json`.

### 4. PLAN_VALIDATE

The plan is checked twice before anything touches a browser:

- **Schema** — against `orchestrator/resources/dsl/schema.json`.
- **Policy** — at most 30 actions; no `timeout_ms` above 20000; every `goto` URL is a
  safe public URL and all of them share one registered domain; every `path` is
  relative, starts with `runs/` and contains no `..`.

If either check fails, the LLM is asked once to repair the plan, and the repaired plan
is validated again. A plan that still fails ends the run with `validation_failed`. A
successful repair is saved as `plans/repair-1.json`. (The repair step re-attaches a
`session` key to `meta`, which the schema does not allow, so in practice a plan that
fails the first validation ends in `validation_failed`. Since the plan comes from a
fixed template, this path is not reached in normal operation.)

### 5. RUN_OR_DRY_RUN

With `dry_run` the browser is not started and the run goes straight to SATISFACTION.

Otherwise the plan is posted to the runner's `/run` endpoint. The runner validates the
plan against the same schema again, enforces its own step limit, launches the browser
and executes the actions in order.

**Bot challenges.** After each navigation the runner checks for a Cloudflare,
Turnstile, hCaptcha or similar wall. A detected challenge is recorded on the runner
result (`challenge`) and in its logs, and the plan **continues** with whatever is
visible. The runner never attempts to solve or bypass a challenge. (`policy.yaml`
declares `bot_challenges.action: stop_and_report`; the runner does not act on that
setting.)

Errors: `runner_failed` when the runner call fails; `challenge_detected` only when the
call fails *and* its error message names a challenge — detection alone is not an
error.

### 6. PERSIST

Writes what the runner returned:

- `main.html` — the rendered homepage;
- `main.md` — the runner's markdown conversion of it;
- `iframes/iframe-{n}.html` — one file per same-origin iframe, if any;
- `logs/runner.jsonl` — runner logs, appended.

The screenshot is written by the runner itself, to the path given in the plan.

### 7. LINKS

Builds the link inventory offline from the saved files. It also runs when
CHECK_ASSETS found an existing capture. A failure here is logged and does not fail
the run.

- Input: `main.html`, any `iframes/*.html`, and the runner's `extract_links` output,
  which is saved first as `links_raw.json`.
- Each link is resolved to an absolute URL, stripped of tracking parameters
  (`utm_*`, `fbclid`, `gclid`, …) and categorised as `internal`, `external`,
  `mailto`, `tel`, `file` or `anchor`.
- Each link gets two hints: `about_hint` (Lithuanian and English "about" words in the
  text or path) and `nav_hint` (found in a header, navigation, footer or menu).
- Links are de-duplicated by cleaned URL and location, and capped at 1000.
- Output: `links.json`, `links.md` (the same list as a readable table), and
  `links_summary` — `{total_links, unique_links, by_category, unique_domains}` — in
  the result.

### 8. STRUCTURE

A deterministic pass over the homepage HTML that extracts contact details, social
links, company indicators and the site's important internal links. Written to
`structure.json`.

### 9. SECONDARY_CRAWL

Fetches up to four of the important internal links found by STRUCTURE — typically
About, Contact and Services pages — so that the profile is built from more than the
homepage. Skipped when STRUCTURE found none.

### 10. PROFILE_BUILD

Extracts a structured company profile from the homepage, the secondary pages and the
structure analysis, using the LLM with a JSON schema built from the declared profile
fields. The model is instructed to leave a field empty when the page gives no evidence
for it.

The company-match judge then scores the profile against the known facts about the
target company — is this site really that company, and how complete is the data — and
its verdict is stored inside the profile under `quality`. The collector uses that
verdict to accept or reject the official site's contribution. The judge fails open: if
it cannot answer, the profile is kept and the verdict says so.

Written to `profile.json`, with a readable `summary.md` beside it.

STRUCTURE, SECONDARY_CRAWL and PROFILE_BUILD are best-effort: a failure is logged and
the run continues without that output. None of them runs on `dry_run`, and they
produce nothing when CHECK_ASSETS reused an existing capture.

### 11. SATISFACTION

Sets `done` to `true` when a non-empty `main.html` and at least one screenshot exist.
The link inventory and the profile are not part of this test. On `dry_run`, `done` is
therefore `true` only if a capture already existed.

## Components

| Component | Responsibility |
|---|---|
| `RunController` | Validates and normalises the request; with `collect: false` calls the pipeline directly and maps its error code to an HTTP status. |
| `OfficialSiteSource` | The collector's adapter: runs the pipeline and exposes the extracted profile's declared fields, the resolved domain and the `quality` verdict. |
| `ReActLoopService` | Drives the state machine and assembles the result. |
| `DomainResolutionService`, `ReActDomainResolver` | RESOLVE_DOMAIN. |
| `PlanBuilder`, `PromptBuilderService` | PLAN_BUILD and PLAN_VALIDATE; `SchemaValidator` and `PolicyChecks` do the checking. |
| `RunnerClient` | Calls the browser runner. |
| `LinkExtractor` | LINKS. |
| `ProfileExtractionPipeline` | STRUCTURE, SECONDARY_CRAWL and PROFILE_BUILD. |
| `SessionFs` | All file access under `runs/`. |
| Browser runner | Re-validates the plan, executes the actions, detects bot challenges, writes screenshots. |

## Plan DSL

Plans are JSON documents validated against `orchestrator/resources/dsl/schema.json`
(JSON Schema draft-07). The orchestrator and the runner load the same file.

Top level — `version`, `meta` and `actions` are required, and no other keys are
allowed:

| Key | Rule |
|---|---|
| `version` | The string `"1.0"`; no other value is accepted. |
| `meta` | Object with `task` (string, required), `company` (string, required), `domain` (string or null), `stealth_mode` (boolean), `human_behavior` (boolean). **No other keys are allowed** — in particular, there is no `meta.session`; the session is carried by the artifact paths in the actions. |
| `actions` | Array of at most 50 actions. Policy lowers the effective limit to 30. |

Each action is an object with a `do` key naming one of 23 actions:

| Group | Actions |
|---|---|
| Navigation | `goto`, `wait_for` |
| Interaction | `click`, `type`, `press`, `dismiss_consent` |
| Scrolling | `scroll`, `scroll_to_bottom`, `scroll_progressive`, `scroll_smart`, `human_scroll` |
| Timing | `random_delay` |
| Extraction | `extract_text`, `extract_attr`, `extract_html`, `extract_links`, `extract_iframe`, `extract_markdown` |
| Screenshots | `screenshot`, `screenshot_fullpage`, `screenshot_viewport`, `screenshot_element` |
| Result | `return` |

Parameters the schema requires per action:

| Action | Required parameters |
|---|---|
| `goto` | `url` |
| `wait_for`, `click` | `selector` |
| `type` | `selector`, `value` |
| `press` | `selector`, `key` |
| `extract_text`, `extract_html` | `selector`, `save_as` |
| `extract_attr` | `selector`, `attr`, `save_as` |
| `extract_links`, `extract_markdown` | `save_as` |
| `screenshot`, `screenshot_fullpage`, `screenshot_viewport` | `path` |
| `screenshot_element` | `path`, `selector` |
| `return` | `data` — the list of `save_as` names to return |

The remaining actions have no required parameters. `extract_iframe` returns an array
of same-origin iframe contents; cross-origin iframes are skipped, and a page with no
same-origin iframe yields an empty array, which is not an error.

There are no actions for logging in, closing login dialogs or scraping social-network
pages: the pipeline reads only what a site shows an anonymous visitor.

## Policy

`orchestrator/resources/policy.yaml` holds the limits the validators enforce:

- `limits.max_steps` (30) and `limits.max_timeout_ms` (20000);
- `security.artifact_restrictions.base_path` (`runs/`) — every artifact path must be
  under it;
- navigation is confined to one registered domain per plan.

Validation is layered: the request is validated by the controller, the plan by the
orchestrator's schema and policy checks, and again by the runner before execution.

## Storage layout

Files are written to the application's local storage disk, whose root is
`storage/app/private`. `{domain}` is the domain in lower case with any character other
than letters, digits, `.` and `-` replaced by `_`.

```
runs/{session}/{domain}/analyze/
├── main.html                 rendered homepage
├── main.md                   homepage as markdown
├── iframes/
│   └── iframe-0.html         same-origin iframes, if any
├── screenshots/
│   └── home.png              full-page screenshot
├── links_raw.json            links as the browser saw them
├── links.json                normalised link inventory
├── links.md                  the inventory as a table
├── structure.json            contacts, social links, important links
├── profile.json              extracted profile with its match verdict
├── summary.md                readable summary of the profile
├── news/                     articles saved by the news source (collector runs only)
├── plans/
│   ├── initial.json
│   └── repair-1.json         only after a successful repair
└── logs/
    ├── runner.jsonl
    ├── react_trace.jsonl     only when the domain was searched for
    └── search_results.json   only when the domain was searched for
```

The `paths` object in the `collect: false` response lists `main_html`, `iframes_dir`,
`screenshots_dir`, `plans_dir`, `logs_dir` and `relative_base`. Its absolute strings
are built without the `private/` segment and therefore do not point at the files;
resolve `relative_base` against the local storage disk instead.

## Result

The pipeline returns one object: `ok`, `done`, `session`, `domain`, `resolved_via`,
`paths`, `links_summary`, `structure`, `profile`, `plan`, `runner`, `trace`, `events`,
`error`, `details`, and — with `thinking` — `react_trace`. Each key, the error codes
and their HTTP statuses are documented in the
[HTTP API reference](../api/bootstrap-api.md#raw-page-capture-collect-false).

The collector reads three things from it: `profile` (the declared fields and the
`quality` verdict), `domain` and `resolved_via`. A result without a profile counts as
an error for the collector when a domain was resolved and the run failed, and as an
empty result otherwise.

## What the pipeline does not do

- It does not navigate across registered domains within one plan.
- It does not solve, bypass or retry bot challenges.
- It does not log in anywhere or read pages behind authentication.
- It does not execute model-written code: the browser only performs the fixed set of
  DSL actions.

## Related documentation

- [HTTP API reference](../api/bootstrap-api.md)
- [Product overview](../overview.md)
- [Concepts](../concepts.md)
- [Known limitations](../known-limitations.md)
