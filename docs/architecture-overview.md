# ProfiltAgent Architecture Overview

## System Architecture

ProfiltAgent is a self-hosted agent that collects company profiles. A Laravel orchestrator
plans and coordinates the work; a Playwright runner captures pages; an LLM provider and a
self-hosted SearXNG instance are called by the orchestrator. For a non-technical summary see
[overview.md](./overview.md).

```
┌─────────────────────────────────────────────────────────────┐
│                    Client Applications                      │
└─────────────────────┬───────────────────────────────────────┘
                      │ HTTP API (POST /api/run)
┌─────────────────────▼───────────────────────────────────────┐
│                Laravel Orchestrator                         │
│  ┌─────────────┐  ┌──────────────┐  ┌─────────────────────┐ │
│  │RunController│  │ReActLoopSvc  │  │   PromptBuilder     │ │
│  └─────────────┘  └──────────────┘  └─────────────────────┘ │
│  ┌─────────────┐  ┌──────────────┐  ┌─────────────────────┐ │
│  │SearchDdgSvc │  │SchemaValidator│  │   PolicyChecks     │ │
│  └─────────────┘  └──────────────┘  └─────────────────────┘ │
└───────┬─────────────────────┬─────────────────────┬─────────┘
        │ HTTP/JSON           │ HTTPS (Prism)       │ HTTP/JSON
┌───────▼──────────┐  ┌───────▼──────────┐  ┌───────▼─────────┐
│ Playwright       │  │ LLM provider     │  │ SearXNG         │
│ Browser Runner   │  │ Anthropic        │  │ (self-hosted    │
│ DSL executor,    │  │ claude-haiku-4-5 │  │  web search)    │
│ bot detection,   │  │ by default;      │  │                 │
│ screenshots      │  │ OpenAI or Ollama │  │                 │
└──────────────────┘  └──────────────────┘  └─────────────────┘
```

The runner never talks to the LLM: the orchestrator asks the LLM for a capture plan,
validates it, and sends the plan to the runner for execution.

> Observability: when Langfuse keys are configured, each `POST /api/run` is recorded as one
> Langfuse trace (a span per pipeline phase plus the LLM generations). Domain discovery
> (when no domain is given) and the news search are backed by the SearXNG instance.

The stack above is the Step-1 bootstrap: one company/domain in, one captured-page profile
out. Sitting above it — invoking it as one of several sources, not chained after it — is a
second, persistent tier that turns single runs into an accumulating company registry:

```
┌─────────────────────────────────────────────────────────────┐
│       CollectorService (Universal Multi-Source Layer)       │
│  legal-first ladder — stops once every declared field fills │
│  ┌──────────┐ ┌──────────┐ ┌────────┐ ┌────────┐            │
│  │ Registry │ │OfficialSt│ │OpenWeb │ │Contacts│            │
│  └──────────┘ └──────────┘ └────────┘ └────────┘            │
│  ┌────────────────┐ ┌────────────────┐ ┌────────────────┐   │
│  │   NewsSource   │ │   MatchJudge   │ │ CoverageScorer │   │
│  └────────────────┘ └────────────────┘ └────────────────┘   │
│   verdict: sufficient | partial | none | data_limited       │
└─────────────────────────┬───────────────────────────────────┘
                          │ RunRegistry
┌─────────────────────────▼───────────────────────────────────┐
│        SQLite Registry  +  Filament Admin (/admin)          │
│   company_profiles · collection_runs · collection_actions   │
│   profile_fields · news_portals                             │
└─────────────────────────────────────────────────────────────┘
```

> `NewsSource` runs unconditionally and is stored alongside the profile, but it never feeds
> `ProfileCoverageScorer` or the verdict — it's an informational side-channel, not a
> collector source. Entry points into this tier: `POST /api/run` (single company), the
> admin's request console at `/admin/request-console` (single company, by hand),
> `CollectCompanyJob` (the same collector on the queue, dispatched from your own code),
> and `php artisan agent:enrich-firms` (batch over registry gap firms — only with an
> external company registry connected). The `Registry` source in the ladder is that
> optional external registry; when none is configured the ladder starts at the official
> site.

## Core Components

### 1. RunController (HTTP Interface)

**Responsibilities**:
- HTTP request handling and validation
- Input normalization (domain cleanup)
- Error mapping to HTTP status codes
- Response formatting

**API Contract**:
```
POST /api/run   (header: X-Api-Key; middleware: api.key + flush.langfuse)
├── Input: {company, domain?, code?, session?, thinking?, dry_run?, collect?,
│           fields?[], known_fields?{}, people?[], verify_fields?}
├── Validation: RunRequest (a failure is 422 {ok:false, error:"validation_failed", errors})
├── Delegation: CollectorService.collect() by default; ReActLoopService.runStep1Bootstrap()
│               when collect:false
└── Output: Structured JSON — collector result by default, Step-1 payload with trace
            and events when collect:false
```

➡️ **[Complete Bootstrap Phase One Documentation →](./architecture/bootstrap-phase-one.md)**
➡️ **[Bootstrap API Reference →](./api/bootstrap-api.md)**

### 2. ReActLoopService (Step-1 Orchestrator)

**State Machine Phases**:
1. **CHECK_ASSETS** → Short-circuit if satisfied (rehydrates saved profile)
2. **RESOLVE_DOMAIN** → cache → ReAct/SearXNG discovery → DDG heuristic fallback (only if no domain)
3. **PLAN_BUILD** → Generate minimal capture plan
4. **PLAN_VALIDATE** → Schema + policy with repair
5. **RUN_OR_DRY_RUN** → Execute or skip
6. **PERSIST** → Save artifacts
7. **STRUCTURE_ANALYSIS** → contacts + social links (`HtmlStructureAnalyzer`)
8. **SECONDARY_CRAWL** → follow About/Contact pages (`SecondaryCrawlService`)
9. **PROFILE_BUILD** → aggregate into `profile.json` + `summary.md` (`CompanyProfileService`)
10. **SATISFACTION** → Final check

**Key Features**:
- Deterministic execution
- Idempotent operations
- Concurrent-safe locking
- Comprehensive error handling
- Progress event collection

### 3. PromptBuilder (LLM Interface)

**Capabilities**:
- Policy injection (caps, timeouts, restrictions)
- DSL schema excerpt with SHA-256 hash
- JSON-only prompt enforcement
- Bootstrap planning messages
- One-shot repair messages
- Cross-platform content normalization

**Prompt Types**:
- **System**: Policy caps + schema + constraints
- **User**: Company card + domain + recipe
- **Repair**: Errors + clipped plan + instructions

### 4. SessionFs (Filesystem Operations)

**Storage Layout**:
```
runs/{session}/{domain}/analyze/
├── main.html              # Homepage content
├── iframes/
│   ├── iframe-0.html      # Extracted iframes
│   └── iframe-1.html
├── screenshots/           # Generated by runner
│   └── home.png
├── plans/
│   ├── initial.json       # Original plan
│   └── repair-1.json      # Repaired plan
└── logs/
    └── runner.jsonl       # Execution logs
```

**Features**:
- Atomic operations
- Directory management
- Lock-based concurrency control
- Path traversal prevention
- Pending→resolved migration

### 5. Domain Resolution

Primary path (when no domain is given): `ReActDomainResolver` runs an LLM tool-loop over
`SearxngSearchService` (self-hosted SearXNG) and verifies the candidate via an About page.
`SearchDdgService` (below) is the **heuristic fallback** when the ReAct path yields nothing.
Aggregators/directories are rejected by `WebSearchFilters`.

**SearchDdgService — LT-Biased Fallback Strategy**:
- Query variants: "company oficiali svetainė", "official site"
- DuckDuckGo with `kl=lt-lt` parameter
- Scoring algorithm:
  - Company in domain: +8 points
  - Company in title: +10 points
  - .lt TLD: +5 points
  - "Official" indicators: +5 points
  - Company in snippet: +3 points
  - Fewer subdomain levels: up to +5 points
  - Directories and aggregators are skipped outright

### 6. Validation Layer

**Schema Validation** (JsonSchema\Validator):
- DSL v1.0 compliance
- Required field validation
- Type checking and constraints

**Policy Validation**:
- Step count ≤ max_steps (30)
- Timeouts ≤ max_timeout_ms (20000)
- Same registered domain enforcement
- Artifact path sandboxing

### 7. CollectorService (Universal Multi-Source Layer)

**Sources** (legal-first ladder order; the loop stops once every declared field is
filled, or once the wall-clock budget — `REACT_LADDER_BUDGET_S`, default 120s — is
spent; there is no overall-score threshold):
1. **`CompanyRegistrySource`** → lookup in an external company registry
   (`CompanyRegistryRepository`); always accepted. **Optional:** with no registry
   configured (`REGISTRY_DB_HOST` empty, the default) the source is skipped and the ladder
   starts at the official site.
2. **`OfficialSiteSource`** → runs the Step-1 bootstrap pipeline above against the
   company's own site; accepted unless its own quality verdict is `fail`.
3. **`OpenWebSource`** → general web search/scrape fallback.
4. **`ContactsSource`** → targeted contact-page pass for whatever's still missing.

`OpenWebSource` and `ContactsSource` contributions are gated by **`CompanyMatchJudge`** —
an LLM-as-judge that scores `match_confidence`/`data_coverage` and derives a
`pass`/`warn`/`fail` verdict (fail-open to `warn` on error/disabled); only `fail` rejects.

**Which fields a run must fill (`FieldRequirements`) is caller-driven, not a fixed
constant**: the request's `fields[]` when given, else the admin-editable field catalogue's
default set (`/admin/profile-fields`), falling back to the six core fields
(`description`, `activity`, `emails`, `phones`, `address`, `social_links`) when the
catalogue is empty. The
caller may also send `known_fields{}` — values it already holds, recorded with `caller`
provenance and never re-searched or overwritten by a source.

**`ProfileCoverageScorer`** is deterministic (no LLM): each declared field scored 0–1;
`overall` is their mean, and fields below `gap_threshold` (default 0.5) are reported
as `missing`.

**Verdicts** (one per collection run):
- `sufficient` — every declared field is filled.
- `none` — no source contributed anything.
- `partial` — a declared field is still missing, and either a source errored or the
  ladder's wall-clock budget ran out first — either way, a retryable gap.
- `data_limited` — the ladder ran to completion cleanly and a declared field is still
  missing (the firm's public-data ceiling, not a failure).

The response's `field_status` map gives the same picture per field — `supplied`
(from `known_fields`), `found`, or `not_found` — except a field left unresolved by a
budget cutoff is omitted rather than marked `not_found`, since the run simply stopped
looking rather than confirming the data doesn't exist.

**`NewsSource`** ("news") runs unconditionally as a separate dimension — third-party LT
news-portal mentions — and is stored alongside the profile, but it carries no profile
`fields`, so it never reaches `ProfileCoverageScorer` or the verdict. Each kept article is
downloaded once and converted; the mention carries an `excerpt` of the article's own body
located by `ArticleExcerpt`, or none plus `excerpt_state: no_body_found` when the page has
no article on it. See [architecture/news-excerpt.md](architecture/news-excerpt.md).

### 8. Registry & Admin (SQLite + Filament)

**Persistence** — a local SQLite database (`DB_CONNECTION=sqlite`, default
`orchestrator/database/database.sqlite`; gitignored, created by `make prepare` + `php artisan
migrate` on deploy). Besides Laravel's own tables (users, sessions, cache, jobs) it holds
five application tables:
- `company_profiles` — the accumulating best-known profile per company (fields, per-field
  coverage, per-field source provenance, per-field match verification, overall score,
  verdict).
- `collection_runs` — one row per `CollectorService::collect()` call (sources used, score,
  verdict, status).
- `collection_actions` — per-source audit log within a run (status, yielded fields,
  per-field scores).
- `profile_fields` — the catalogue of profile fields a run may be asked for, and which of
  them are in the default set.
- `news_portals` — the whitelist of news portals the news dimension searches.

The two catalogues are seeded by their migrations and edited in the admin panel.

**Filament admin**, mounted at `/admin`:
- Resources: **Collection runs** and **Company profiles** (read-only; the run view
  includes a per-field collected/missing diagnosis section), **News portals** and
  **Profile fields** (the two editable catalogues).
- Pages: **Request console** and **Logs** (a log viewer).
- Dashboard widgets: collection stats, verdict distribution, source health, system health
  and latest runs.

The first admin user is created with `php artisan agent:create-admin` (no seeder).

The **request console** (`/admin/request-console`) composes one `/api/run` request by hand,
runs it synchronously and shows the whole result. When an external company registry is
configured it also offers a registry search: picking a firm prefills the request from its
registry facts as *unchecked* suggestions — ticked ones ship as `known_fields` (recorded
`supplied`, never scraped), unticked ones stay gaps for the ladder, which puts supplied and
collected data side by side. Without a registry the page shows a one-line note and the form
is filled in by hand.

No admin screen dispatches `CollectCompanyJob`; it is a queued entry point for code.

**CLI**:
- `php artisan agent:create-admin` — create or update the admin user.
- `php artisan agent:verify-llm` — live end-to-end LLM provider check (structured output +
  full profile build), since unit tests mock the LLM client.
- `php artisan agent:search-engines` — which SearXNG engines answer, and which honour the
  `site:` operator the news search depends on.
- `php artisan agent:search-probe "<query>"` — one search, with the pipeline's keep/drop
  verdict for every result.
- `php artisan agent:enrich-firms --with-site=5 --without-site=5 [--dry-run]` — batch-runs
  the collector over the registry "gap" firms (active companies with no self-description/
  contacts), writing a manifest under `runs/enrichment/`. Works only with an external
  company registry connected.
- `php artisan agent:seed-demo` — fills an empty database with invented demo companies,
  runs and news.

## Data Flow

### 1. Step-1 Bootstrap Flow

```
Request → Validation → Domain Resolution → Plan Generation
    ↓
Response ← Satisfaction ← Persistence ← Execution ← Validation
```

### 2. Asset Short-Circuit

```
Request → Check Assets → [EXISTS] → Response (done: true)
```

### 3. Error Handling

```
Any Phase → Error → Cleanup → Error Response
    ↓
Lock Release + Logging + Status Mapping
```

## Integration Points

### LLM Provider (via Prism)

**Configuration** (`config/llm.php` + `config/prism.php`):
- Client: `App\IO\PrismLlmClient` (bound to the `LlmClient` contract)
- Provider (`LLM_PROVIDER`): `anthropic` (default), `openai` or `ollama`
- Model (`LLM_MODEL`): `claude-haiku-4-5` by default
- Temperature: 0.1; max tokens: 2000
- Credentials: `ANTHROPIC_API_KEY` for Anthropic, `OPENAI_API_KEY` for OpenAI; for Ollama
  the server address in `OLLAMA_URL`

**API Usage**:
- Structured output via Prism (`structured()`) with schema-constrained decoding
- LLM generations recorded to Langfuse when it is configured (model, prompt, output, token usage)

### Playwright Runner Service

**Communication**:
- HTTP POST to `RUNNER_BASE` + `/run` — `http://browser_runner:8080/run` in the Docker
  stack (`http://localhost:8080/run` for a host-based run), with
  `Authorization: Bearer $RUNNER_TOKEN` when the token is set
- DSL plan as JSON payload
- Structured response with artifacts

**Capabilities**:
- Headless browser automation
- Human behavior simulation
- Bot challenge detection
- Screenshot generation
- Artifact path remapping

### Company registry MariaDB (external, read-only, optional)

An existing company-registry database can be connected as an extra, identity-certain
source. It is **not required and not part of the stack**: with `REGISTRY_DB_HOST` empty
(the default) `CompanyRegistrySource` is skipped and everything else works unchanged.

**Access**: `App\Tools\CompanyRegistryRepository`, a separate `company_registry` DB connection
(`REGISTRY_DB_*`), read-only, with a per-query time limit (`REGISTRY_DB_TIMEOUT`, default
5 s) so a slow registry becomes a skipped source rather than a stalled run.

- `resolveCode(domain, name)` finds a firm's registry code by exact domain match (or,
  failing that, an unambiguous normalized-name match) so `CompanyRegistrySource` can pre-fill
  known fields from the registry instead of re-scraping the web for data already on file.
- Backs the request console's registry search and `agent:enrich-firms`' firm selection:
  active companies (`Employees > 0`) with no self-description and no contacts — the
  highest-value web-enrichment targets.

### SQLite Registry (local, read/write)

The `CollectorService`'s own store — see [Registry & Admin](#8-registry--admin-sqlite--filament)
above — is a separate database from the external registry: `company_profiles`,
`collection_runs`, `collection_actions`, `profile_fields` and `news_portals`, surfaced
through the Filament admin at `/admin`.

## Security Architecture

### 1. Input Validation

**Request Level**:
- Laravel validation rules
- Domain format validation
- Parameter sanitization
- Length limits

**Plan Level**:
- JSON schema validation
- Policy constraint enforcement
- Path traversal prevention

### 2. Execution Sandbox

**Filesystem**:
- Restricted to `runs/` directory
- No directory traversal
- Atomic operations
- Lock-based concurrency

**Network**:
- Same-domain enforcement
- Timeout limits
- Bot challenge compliance

### 3. Content Security

**HTML Sanitization**:
- Potential XSS prevention
- Safe content storage
- Controlled artifact access

## Performance Characteristics

### 1. Concurrency

**Session-Level Locking**:
- Per {session, domain} locks
- 10-minute stale lock cleanup
- Non-blocking for different sessions

**Parallel Operations**:
- Multiple sessions can run simultaneously
- Independent execution contexts
- Shared service instances

### 2. Optimization Strategies

**Short-Circuit Optimization**:
- Asset existence check first
- Immediate return if satisfied
- No LLM or runner calls

**Caching**:
- Domain resolution results are remembered and reused

### 3. Scalability Considerations

- Run artifacts live on a filesystem shared by the orchestrator, the queue worker and the
  runner; the profile store is a single SQLite file. The stack is designed to run on one
  host.
- Every network step is timeout-bounded, and a collection run as a whole is bounded by the
  ladder's wall-clock budget (`REACT_LADDER_BUDGET_S`).

## Monitoring and Observability

### 1. Structured Logging

**Log Levels**:
- **INFO**: Phase transitions, domain resolution
- **WARNING**: Validation failures, retry attempts
- **ERROR**: Service failures, exceptions

**Log Context**:
- Session ID correlation
- Company and domain tracking
- Execution phase markers

### 2. Event Tracking

**Thinking Mode Events**:
```json
[
  {"phase": "check_assets", "exists": false},
  {"phase": "search.domain_resolved", "domain": "acmena.example"},
  {"phase": "plan.validated", "repaired": true},
  {"phase": "runner.done", "ok": true},
  {"phase": "done", "value": true}
]
```

The admin dashboard widgets summarise stored runs: collection stats, verdict distribution
and per-source health.

### 3. Health Monitoring

**Service Health Checks**:
- `/api/v1/agent/health` - Orchestrator status (served by nginx on port 80)
- `http://browser_runner:8080/health` - Runner availability (host: `127.0.0.1:8080`)
- SearXNG `/search` - search-engine availability (ReAct domain discovery)

`make health` checks the three in one go. `php artisan agent:search-engines` goes further
for search: it shows which upstream engines answer and whether `site:` queries work.

## Configuration Management

### 1. Environment Variables

The complete list, with defaults, is in the [configuration reference](configuration.md).

**Core Settings**:
- `LLM_PROVIDER` / `LLM_MODEL`: provider + model (default `anthropic` / `claude-haiku-4-5`;
  `openai` and `ollama` are the other providers)
- `ANTHROPIC_API_KEY` (or `OPENAI_API_KEY` / `OLLAMA_URL`, matching the provider)
- `AGENT_API_KEY`: `X-Api-Key` for `POST /api/run`
- `RUNNER_BASE` / `RUNNER_TOKEN`: runner address and the shared Bearer token
- `SEARXNG_URL`, `SEARXNG_MAX_PER_MINUTE`: self-hosted search endpoint and query ceiling
- `REACT_*`: collector budgets and switches (ladder budget, open-web, contacts, news)
- `LANGFUSE_PUBLIC_KEY` / `LANGFUSE_SECRET_KEY` / `LANGFUSE_BASE_URL`: observability (optional)
- `REGISTRY_DB_*`: external company registry (optional; empty host = source skipped)
- Runner: `HEADLESS` (browser mode), `MAX_STEPS`, `RUNS_DIR`

Application settings live in `orchestrator/.env`; stack settings (project name, runner host
port, `RUNNER_TOKEN`) in the root `.env`. See [deploy-runbook.md](./deploy-runbook.md).

### 2. Policy Configuration

**File**: `resources/policy.yaml`
```yaml
profiltagent_policy:
  limits:
    max_steps: 30
    max_timeout_ms: 20000
  security:
    artifact_restrictions:
      base_path: "runs/"
```

### 3. DSL Schema

**File**: `resources/dsl/schema.json`
- JSON Schema draft-07
- DSL v1.0 validation rules — the `version` enum accepts `"1.0"` only
- Action definitions and constraints

## Deployment Architecture

### 1. Docker Composition

**Services** (`docker-compose.yml`):
- `nginx` (`profiltagent-nginx`): the only host-exposed HTTP entry point — port 80, serving
  both `/api/*` and `/admin` by FastCGI to php-fpm
- `php_orchestrator` (`profiltagent-orchestrator`): Laravel application as php-fpm on
  `127.0.0.1:9000` — it answers no HTTP itself
- `queue_worker` (`profiltagent-queue-worker`): `php artisan queue:work` for jobs such as
  `CollectCompanyJob` (dispatched from code; no admin screen enqueues it)
- `browser_runner` (`profiltagent-runner`): Node.js Playwright service, `127.0.0.1:8080`
- `searxng` (`profiltagent-searxng`): self-hosted search engine, host `8888` → container 8080

The LLM provider (Anthropic by default, via Prism) and Langfuse are external services —
neither runs as a local container, and Langfuse is optional. The optional company-registry
MariaDB, when used, is likewise an external, pre-existing read-only database.

### 2. Volume Mounts

**Persistent Storage** (as mounted by `docker-compose.yml`):
- `./orchestrator:/app` - application code (shared by nginx, php-fpm and the queue worker)
- `./orchestrator/storage/app/private/runs:/app/storage/app/private/runs` - run artifacts;
  the same directory is mounted into the runner as `/app/runs` so its screenshots land
  where the orchestrator looks for them
- `./orchestrator/storage/logs`, `./orchestrator/bootstrap/cache` - logs and framework cache
- `./.deploy/nginx.conf`, `./orchestrator/resources/dsl` (read-only), `./.deploy/searxng`
  (SearXNG settings)
- `./runner` is mounted read-only at `/app/runner`, but the runner executes the code baked
  into its image — a runner change needs an image rebuild
- `orchestrator/database/database.sqlite` - the collector's SQLite registry (gitignored;
  created via `touch` + `php artisan migrate` on deploy, not a mounted volume)

### 3. Network Configuration

**Internal Communication**:
- Client → nginx (host port 80) → php-fpm (`php_orchestrator:9000`)
- Orchestrator → Runner: HTTP over the docker network (`browser_runner:8080`)
- Orchestrator → SearXNG: HTTP over the docker network (`searxng:8080`)

**Outbound access required**: the LLM provider over HTTPS (via Prism) and whatever sites
SearXNG and the runner fetch. Optional, when configured: Langfuse ingestion and the
external company-registry MariaDB.
