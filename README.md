# ProfiltAgent

ProfiltAgent is a self-hosted agent that collects company profiles on demand. Given a
company name or registry code, it gathers facts from a company registry, the company's
own site, the open web and the news, checks that each source is really about that
company, and returns one structured profile — with a source and a verification status
behind every value. It was built for Lithuanian companies first.

**New here? Read the [product overview](docs/overview.md)** — what it does, how a profile is
collected, why the result can be trusted, and what an integration looks like.

## How it works

- **Orchestrator** (`orchestrator/`, PHP / Laravel) — runs the collection, calls the LLM,
  scores coverage, stores profiles and serves the HTTP API and the admin panel.
- **Browser runner** (`runner/`, Node.js / Playwright) — reads web pages by executing
  plans written in a strict JSON DSL. It never solves or bypasses a bot challenge.
- **SearXNG** — a self-hosted search engine used to find a company's site, third-party
  pages and news.
- **LLM** — extraction and the company-match judge, through your own API key. Anthropic
  `claude-haiku-4-5` is the default; OpenAI and Ollama can be configured.
- **SQLite** — profiles, run history and a per-source audit log.
- **Company registry** (optional) — an external, read-only database of companies keyed by
  registry code. When connected it is the most trusted source.
- **Langfuse** (optional) — LLM tracing, one trace per request.

A collection tries sources in order of trust — registry, official site, open web,
contact pages — asks each later source only for what is still missing, and stops when
every field the caller declared is filled or the time budget (120 s by default) is
spent. News mentions are collected alongside and never affect the verdict. The
[overview](docs/overview.md) and [Concepts](docs/concepts.md) explain the result in
detail.

## Quick start

Requirements: Docker with Compose, and an API key for an LLM provider.

```bash
# 1. Configure
cp .env.example .env                              # stack settings: project name, ports
#    If host port 8080 is already taken, change RUNNER_HOST_PORT in .env.
cp orchestrator/.env.example orchestrator/.env    # application settings and secrets
#    In orchestrator/.env set at least: ANTHROPIC_API_KEY, AGENT_API_KEY,
#    ADMIN_EMAIL, ADMIN_PASSWORD. Quote any value that contains '#'.

# 2. Build and prepare the application
make setup                                        # build images, install PHP dependencies,
                                                  # create the SQLite file, fix permissions
docker compose run --rm --no-deps php_orchestrator php artisan key:generate

# 3. Start
make up
docker compose exec php_orchestrator php artisan migrate --force
docker compose exec php_orchestrator php artisan agent:create-admin
make health
```

The admin panel is at <http://localhost/admin>, the API at `http://localhost/api/run`.

```bash
curl -X POST http://localhost/api/run \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: $AGENT_API_KEY" \
  -d '{"company": "Acmena, UAB", "code": "300000123", "fields": ["description", "emails", "phones"]}'
```

To look at the admin panel without collecting anything, fill an empty database with
invented demo companies: `docker compose exec php_orchestrator php artisan agent:seed-demo`.

Production deployment is described in the [deploy runbook](docs/deploy-runbook.md).

## Documentation

- [Product overview](docs/overview.md) — what ProfiltAgent does and what you get, in one page.
- [Integration guide](docs/integration-guide.md) — calling the API from another service.
- [HTTP API reference](docs/api/bootstrap-api.md) — `POST /api/run` parameters and response.
- [Concepts](docs/concepts.md) — what `verified`, `unverified`, `unknown`, `supplied`,
  `found`, `not_found` and the news search states mean.
- [Admin panel](docs/admin-panel.md) — every screen of `/admin`, with screenshots.
- [Deploy runbook](docs/deploy-runbook.md) — deploying and operating the system.
- [Configuration reference](docs/configuration.md) — every environment variable in one place.
- [Known limitations](docs/known-limitations.md) — what it does not do, and workarounds.
- [Personal data](docs/personal-data.md) — what is collected about people, where it is
  stored and sent, and what you must handle yourself.

For contributors:

- [Architecture overview](docs/architecture-overview.md) — components, data flow, integration points.
- [Codebase map](docs/codebase-map.md) — where everything lives and how to extend it.
- [Page capture pipeline](docs/architecture/bootstrap-phase-one.md) — how the official
  site is read: the state machine and the DSL.
- [News excerpts](docs/architecture/news-excerpt.md) — how an article's text is extracted.

## Commands

```bash
make up / down     # start / stop the stack
make build         # build the images
make rebuildupy    # stop -> build -> start
make health        # check the orchestrator, the runner and SearXNG
make status        # docker compose ps
make logs          # follow the logs
make test          # PHP and Node.js tests (needs PHP, and `npm install` in runner/, on the host)
make test-llm      # live check of the LLM provider
```

Inside the orchestrator (`docker compose exec php_orchestrator php artisan …`):

| Command | Purpose |
|---|---|
| `agent:create-admin` | Create or update the admin panel user |
| `agent:seed-demo` | Fill an empty database with invented demo data |
| `agent:verify-llm` | Live check of the LLM provider |
| `agent:search-engines` | Show which SearXNG engines answer and which support `site:` |
| `agent:search-probe` | Run one search and show how the pipeline judges each result |
| `agent:enrich-firms` | Batch-collect firms from a connected company registry |

Run the PHP tests with `cd orchestrator && TEST_TOKEN=$$ php vendor/bin/phpunit tests/`
(not `php artisan test`).

## Where results are stored

Profiles, runs and the per-source audit log are in the SQLite database
(`orchestrator/database/database.sqlite`): tables `company_profiles`, `collection_runs`
and `collection_actions`, plus the editable catalogues `profile_fields` and
`news_portals`.

Page captures and downloaded article text are kept as files:

```
orchestrator/storage/app/private/runs/{session}/{domain}/analyze/
├── main.html, main.md      # captured homepage and its markdown
├── structure.json          # contacts, social links, indicators
├── profile.json            # structured profile from the site
├── summary.md              # human-readable summary
├── links.json, links.md
├── news/                   # downloaded articles
├── screenshots/
├── plans/                  # the DSL plans that were run
└── logs/
```

## Security

- Every API request needs the key in `AGENT_API_KEY`; the admin panel has its own login.
- The runner writes only under `runs/`, validates URLs and stays on the target domain.
- A bot challenge is recorded and the plan continues with what is already visible; it
  is never solved or bypassed.
- Execution limits are set by policy (`orchestrator/resources/policy.yaml`).
- TLS is expected to be provided by a reverse proxy in front of the stack.

To report a vulnerability, see [SECURITY.md](SECURITY.md).

## License

Copyright (c) 2026 Neuro Momentum, MB.

Source-available under the [PolyForm Noncommercial License 1.0.0](LICENSE): free to
read, run and modify for noncommercial purposes. Commercial use — including running it
inside a company — requires a licence from Neuro Momentum, MB, which also provides
integration and deployment. See [COMMERCIAL.md](COMMERCIAL.md).
