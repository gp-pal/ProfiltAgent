# Codebase map

Where things live, and how to extend the two parts that need more than an edit.

For what the product does, start with [overview.md](./overview.md); for how the parts fit
together, [architecture-overview.md](./architecture-overview.md).

---

## Entry points

Everything flows through one of these:

| entry point | what it does | notes |
|---|---|---|
| `POST /api/run` | the HTTP API. Defaults to the universal collector; `collect: false` falls through to the raw Step-1 pipeline | needs `X-Api-Key` (`EnsureApiKey`) |
| `/admin/request-console` | the operator's page: compose one request, run it **synchronously**, and read the result, all on one page. With an external registry configured it also offers registry search and prefill | Filament |
| `php artisan agent:enrich-firms` | batch-runs the collector over registry gap firms | CLI; writes a manifest; works only with an external company registry connected |
| `CollectCompanyJob::dispatch()` | the same collector **on the queue**, for your own code | needs `queue_worker` running; no admin screen dispatches it |

There are two layers, and the distinction matters when reading the code:

- **The collector** (`CollectorService`) — a ladder of sources building an accumulating
  per-company profile. This is the default path.
- **Step-1 bootstrap** (`ReActLoopService::runStep1Bootstrap`) — the browser-capture pipeline
  that drives Playwright through a JSON DSL. The collector calls it as *one* of its sources
  (`OfficialSiteSource`); it is also reachable directly with `collect: false`.

---

## Orchestrator (`orchestrator/`)

### The collector and its sources

| file | role |
|---|---|
| `app/Services/CollectorService.php` | the ladder, the match gate, the merge rules, the verdict. The centre of the system |
| `app/Services/CollectionSource.php` / `CollectionResult.php` | the source contract and its return shape |
| `app/Services/CompanyRegistrySource.php` | lookup in the optional external company registry by company code — the one identity-certain source. Skipped when `REGISTRY_DB_HOST` is empty |
| `app/Services/OfficialSiteSource.php` | runs Step-1 bootstrap against the company's own site |
| `app/Services/OpenWebSource.php` | general web search + LLM extraction fallback |
| `app/Services/ContactsSource.php` | contact-page pass for emails/phones still missing |
| `app/Services/NewsSource.php` | the separate news dimension — mentions, and person research |
| `app/Services/RunRegistry.php` | all writes to the local registry: `mergeProfile`, `mergeNews`, `mergePeople`, run/action rows |
| `app/Services/ProfileCoverageScorer.php` | per-field 0–1 scoring that drives the ladder |
| `app/Services/FieldRequirements.php` | resolves which fields a run must fill (caller's list, else the catalogue) |

Ladder order is fixed in `CollectorService`'s constructor:
**`registry_db` → `official_site` → `open_web` → `contacts`**, with `news` run
separately afterwards. `registry_db` is skipped when no external registry is configured, so
on most installations the ladder effectively starts at `official_site`.

### Identity, matching and verification

| file | role |
|---|---|
| `app/Services/CompanyMatchJudge.php` | LLM-as-judge: is this evidence the target company? Returns `pass`/`warn`/`fail`. **Fails open** |
| `app/Services/MatchAssessment.php` | the verdict *and* how it was reached — the `basis` vocabulary, and derived-verdict inheritance |
| `app/Models/CompanyProfile.php` | the accumulating profile, and `verificationOf()` — the **only** place a stored verification entry is interpreted |

Read [concepts.md](./concepts.md) before changing any of these three. The rules they encode
are not obvious from the code alone.

### News and people

| file | role |
|---|---|
| `app/Services/NewsQueryBuilder.php` | plans the queries and the people budget. `peopleBudget()` asks `build()` rather than re-deriving arithmetic |
| `app/Services/ArticleExcerpt.php` | locates the article body in a converted page. See `docs/architecture/news-excerpt.md` |
| `app/Services/NewsPortalRegistry.php` | the enabled-portal whitelist, DB-backed (`/admin/news-portals`) |
| `app/Tools/PersonName.php` | pulls the person out of a registry manager string; refuses what is not a name |
| `app/Tools/RegistryPeople.php` | the shared ordering used by both the collector and the console prefill |
| `app/Services/ArticleDateParser.php` | publish-date extraction from portal pages |

### Step-1 bootstrap pipeline

| file | role |
|---|---|
| `app/Services/ReActLoopService.php` | the phase state machine: CHECK_ASSETS → RESOLVE_DOMAIN → PLAN_BUILD → PLAN_VALIDATE → RUN → STRUCTURE → SECONDARY_CRAWL → PROFILE_BUILD → LINKS |
| `app/Services/DomainResolutionService.php` | `Memory` cache → `ReActDomainResolver` → `SearchDdgService` fallback |
| `app/Services/ReActDomainResolver.php` | the LLM tool-loop over SearXNG that finds and verifies a domain |
| `app/Services/PromptBuilderService.php` / `PlanBuilder.php` | renders the DSL capture plan |
| `app/Validation/SchemaValidator.php` / `PolicyChecks.php` | plan validation; one repair attempt on failure |
| `app/Services/HtmlStructureAnalyzer.php` | contacts + social links out of captured HTML |
| `app/Services/SecondaryCrawlService.php` | follows About/Contact pages |
| `app/Services/CompanyProfileService.php` | aggregates into `profile.json` + `summary.md` |
| `app/Services/LinkExtractor.php` | categorises links → `links.json` / `links.md` |

### IO, tools, observability

| file | role |
|---|---|
| `app/IO/PrismLlmClient.php` | the LLM client (Prism; Anthropic by default) |
| `app/IO/RunnerClient.php` | HTTP client for the Playwright runner |
| `app/Tools/SearxngSearchService.php` | SearXNG JSON API, plus the local rate-limit window |
| `app/Tools/SearchDdgService.php`, `WebSearchFilters.php` | heuristic search fallback; aggregator/directory rejection |
| `app/Tools/CompanyRegistryRepository.php` | **read-only** access to the optional external company-registry MariaDB |
| `app/Tools/SessionFs.php` | all run-artifact filesystem writes; path-traversal prevention |
| `app/Observability/LangfuseTracer.php`, `LangfuseClient.php` | one trace per `POST /api/run` |

**Langfuse traces start and flush on the HTTP path.** A CLI entry point that does not call
`startTrace()` and flush itself produces spans that go nowhere; `agent:enrich-firms` does
both, per firm. If you add a command that should be traced, do the same.

### Admin panel (Filament)

| file | role |
|---|---|
| `app/Filament/Pages/RequestConsole.php` + `resources/views/filament/pages/request-console.blade.php` | `/admin/request-console`. The blade holds the display logic for the three news states |
| `app/Filament/Pages/LogViewer.php` | log browsing (menu label "Logs") |
| `app/Filament/Resources/CollectionRuns/` | `/admin/collection-runs`, including `Schemas/CollectionRunInfolist.php` |
| `app/Filament/Resources/CompanyProfiles/`, `NewsPortals/`, `ProfileFields/` | the profile browser and the two editable catalogues |
| `app/Filament/Widgets/` | dashboard widgets: collection stats, verdict distribution, source health, system health, latest runs |
| `app/Http/Controllers/RunArtifactController.php` | authenticated screenshot streaming; the client never supplies a path |

Request console and Logs are the only custom pages; everything else is a resource. First
admin user: `php artisan agent:create-admin`. There is no seeder.

### Artisan commands (`app/Console/Commands/`)

| command | file | purpose |
|---|---|---|
| `agent:create-admin` | `SeedAdmin.php` | create or update the admin user |
| `agent:verify-llm` | `VerifyLlm.php` | live check of the configured LLM provider |
| `agent:search-engines` | `SearchEngines.php` | which SearXNG engines answer, and which honour `site:` |
| `agent:search-probe` | `SearchProbe.php` | one search, with the pipeline's keep/drop verdict per result |
| `agent:enrich-firms` | `EnrichFirms.php` | batch collection over registry gap firms; needs an external registry |
| `agent:seed-demo` | `SeedDemo.php` | fill an empty database with invented demo companies, runs and news |

### Configuration

| file | holds |
|---|---|
| `config/react.php` | every budget and ceiling: `news.max_queries`, `max_people`, `portals_per_query`, `time_budget_s`, `coverage.ladder_budget_s`, `match_judge.*`, `open_web.*`, `contacts.*` |
| `config/llm.php`, `config/prism.php` | provider and model selection (`anthropic` by default; `openai`, `ollama`) and provider credentials |
| `config/services.php` | runner, SearXNG and Langfuse endpoints |
| `config/company_registry.php` | the optional read-only external registry connection, and its PDO timeouts |
| `config/agent.php` | `AGENT_API_KEY` and related |
| `resources/policy.yaml` | execution policy: max 30 steps, timeouts ≤ 20000ms, artifacts under `runs/`, same-registered-domain enforcement |
| `resources/dsl/schema.json` | the DSL validation schema (version `"1.0"`, the only value its `version` enum accepts) |
| `.env` (from `.env.example`) | secrets and runtime config. **Quote anything containing `#`** — `phpdotenv` truncates an unquoted value there; see [known-limitations.md D6](./known-limitations.md#d6) |

`RUNS_DIR` is an exception worth knowing: it is live and load-bearing for the **runner**
(every screenshot path is built from it, and it must match the shared volume mount), and
unused by the orchestrator — nothing in its application code reads `config('app.runs_dir')`;
artifact paths come from `SessionFs` on the Laravel `local` disk. See
[known-limitations.md D22](./known-limitations.md#d22).

**Budgets are deliberately small.** With the default `max_queries=4` and the 14 seeded
portals there is room for one person per run (`NewsQueryBuilder::peopleBudget()` computes
the actual figure). If you think a budget is too low, change it as a decision with a
measurement behind it, not as a side effect of another fix.

### Prompts

`resources/prompts/` — loaded by `PromptBuilderService`:

- `bootstrapping/system_plan.txt`, `bootstrapping/user_plan.txt` — the Step-1 capture plan.
- `user_repair.txt` — the single plan-repair attempt.
- `react_search.txt` — the ReAct domain-discovery loop.

---

## Runner (`runner/`)

Node.js HTTP server on 8080, executing DSL plans in headless Chromium via Playwright.

| file | role |
|---|---|
| `server.js` | the HTTP server: `POST /run`, `POST /markdown`, `GET /health` |
| `actions/index.js` | every DSL action's implementation |
| `detectors/index.js` | bot-challenge detection |
| `markdown.js` | HTML → markdown conversion (also serves `POST /markdown`) |
| `locale.js` | locale/timezone shaping |
| `test/` | `npm test` |

**The runner's code is baked into its image.** `.deploy/Dockerfile.runner` copies `runner/`
into `/app`; the compose bind-mount lands on `/app/runner`, which nothing executes. Editing
runner code and restarting changes nothing —
see [deploy-runbook.md §1.2](./deploy-runbook.md#12-a-runner-change-needs-an-image-rebuild-not-a-restart).

---

## Data

### Local registry — SQLite, gitignored, irreplaceable

`orchestrator/database/database.sqlite`. The application tables:

- `company_profiles` — the accumulating best-known profile per company: `fields`,
  `field_coverage`, `field_sources`, `field_verification`, `overall_score`, `verdict`.
  Note `fields` also carries `news` and `people`, which are **not** coverage fields.
- `collection_runs` — one row per collector run.
- `collection_actions` — per-source audit log within a run. `detail` is a JSON blob and is
  where the match verdict (`match` key) and the whole news report live.
- `profile_fields`, `news_portals` — admin-editable catalogues, seeded by their own migrations.

This is the accumulated product of every run ever made and there is no copy in the
repository. **Never `migrate:fresh`.**

### External company registry — optional

A read-only MariaDB (`REGISTRY_DB_*`) that an installation may connect as an extra source.
It is not part of the stack, and `REGISTRY_DB_HOST` is empty by default.

### Run artifacts

```
storage/app/private/runs/{session}/{domain}/analyze/
├── main.html / main.md         # captured homepage + conversion
├── structure.json              # contacts, social links, indicators
├── profile.json / summary.md   # built profile
├── links.json / links.md
├── screenshots/                # e.g. home.png
├── plans/                      # initial.json, repair-1.json
└── logs/                       # runner.jsonl, react_trace.jsonl
```

Enrichment runs also write `runs/enrichment/{ts}/manifest.json`.

> The API response's `paths` object reports these under `storage/app/runs/…`, while the files
> are actually written through the Laravel `local` disk, whose root is
> `storage/app/private`. Use `relative_base` against the `local` disk, not the absolute
> strings. This mismatch is documented in
> [api/bootstrap-api.md](./api/bootstrap-api.md).

---

## Extending

### Adding a DSL action

Four places, and skipping any one of them fails in a different way:

1. **`runner/actions/index.js`** — implement it.
2. **`orchestrator/resources/dsl/schema.json`** — add it, or `SchemaValidator` rejects every
   plan that uses it.
3. **`orchestrator/resources/policy.yaml`** — add any constraint it needs (timeout ceiling,
   artifact extension, domain rule).
4. **`orchestrator/app/Validation/`** — wire the policy check if it needs more than the schema.

Then **rebuild the runner image** (`docker compose build browser_runner`) — a restart will not
pick it up. And add it to the action list in
[architecture/bootstrap-phase-one.md](./architecture/bootstrap-phase-one.md), which documents
the full action set.

### Adding a prompt

Put it under `orchestrator/resources/prompts/` and load it through `PromptBuilderService`.
Do not inline prompt text in a service.

### Adding a collection source

Implement `CollectionSource` and register it in `CollectorService`'s constructor, in ladder
order. Then decide, deliberately, **how its identity is established** — it will be judged by
`CompanyMatchJudge` through `assessMatch()`'s `default` arm unless you say otherwise, and that
default is the safe one. Give it a meta payload the judge can actually use: at minimum a
`domain` and a `content` snippet. `OpenWebSource` does not, and
[known-limitations.md D2](./known-limitations.md#d2) is the result.

**Never** extend the registry's identity bypass to it. That rule and its reasoning are in
[concepts.md](./concepts.md#identity-why-only-the-registry-is-certain).

### Adding a declared field

Fields are data, not code: add it at `/admin/profile-fields`, and teach the relevant sources
to fill it. `ProfileCoverageScorer` scores whatever is declared.

---

## Tests

```bash
cd orchestrator && TEST_TOKEN=$$ php vendor/bin/phpunit tests/     # NOT php artisan test
cd runner && npm test
```

`make test` runs both suites. `TEST_TOKEN` keeps two concurrent PHP runs in the same tree
from deleting each other's `Storage::fake()` fixtures. Conventions worth keeping:

- **Prove a test can fail.** Change one thing and watch exactly the assertion that claims it
  break, and nothing else. Where a fix could drift in either direction, pin it from **both**
  sides — the defect and the over-correction.
- **Bind the `CompanyRegistryRepository` mock** in any request-console test. A test that does
  not constructs the real repository, and with `REGISTRY_DB_HOST` pointing at an unreachable
  host each such test waits out the connect timeout.
- Fixtures under `tests/Fixtures/news/` are **captured real page structures with invented
  words** plus a few constructed ones; the README there says which is which. Only the
  captured ones say anything about how the code behaves against the real web.

---

## Related documents

- [overview.md](./overview.md) — what the product does, in one page.
- [concepts.md](./concepts.md) — the vocabulary. Read before changing the collector.
- [known-limitations.md](./known-limitations.md) — what is still open.
- [deploy-runbook.md](./deploy-runbook.md) — how to deploy and operate it.
- [architecture-overview.md](./architecture-overview.md) — components and data flow in prose.
- [architecture/bootstrap-phase-one.md](./architecture/bootstrap-phase-one.md) — the Step-1
  state machine and the full DSL action list.
- [api/bootstrap-api.md](./api/bootstrap-api.md) — the `POST /api/run` contract.
