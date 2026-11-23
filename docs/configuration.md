# Configuration reference

Every setting ProfiltAgent reads from the environment, in one place.

There are two files, and a setting belongs to exactly one of them:

| File | Holds | Read by |
| --- | --- | --- |
| `.env` (repo root) | Stack settings: project name, ports, the runner token | `docker compose`, the `Makefile` |
| `orchestrator/.env` | Application settings and secrets | the Laravel application |

Copy each from its `.env.example`. Quote any value that contains `#` or a space.

Some values in `orchestrator/.env` are **overridden by `docker-compose.yml`** when the
stack runs under Compose: `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `RUNNER_BASE`,
`RUNNER_TOKEN`, `SEARXNG_URL` and `RUNS_DIR`. Change those in the root `.env` or in the
compose file, not in `orchestrator/.env`.

"Default" below is what the code uses when the variable is absent. Where `.env.example`
ships a different value, both are given.

## Stack (root `.env`)

| Variable | Default | Meaning |
| --- | --- | --- |
| `COMPOSE_PROJECT_NAME` | `profiltagent` | Compose project, container names (`<slug>-nginx`, …) and image names. Lowercase, no spaces. |
| `APP_NAME` | `ProfiltAgent` | Display name in the admin panel, page titles and CLI output. |
| `RUNNER_HOST_PORT` | `8080` | Host port of the runner's localhost-only health endpoint. Change it when 8080 is taken. |
| `RUNNER_TOKEN` | empty | Shared secret between the orchestrator and the runner (Bearer). Empty means the runner accepts unauthenticated calls: local development only. Generate with `openssl rand -hex 32`. |
| `SHARED_PROXY_NETWORK` | `shared_proxy` | Production only (`docker-compose.prod.yml`): the external Docker network of your reverse proxy. |

Host ports that are fixed in `docker-compose.yml`: nginx `80`, php-fpm `127.0.0.1:9000`,
SearXNG `127.0.0.1:8888`. The production override moves nginx to `127.0.0.1:8090`.

## Application

| Variable | Default | Meaning |
| --- | --- | --- |
| `APP_KEY` | none, **required** | Laravel encryption key. Generate with `php artisan key:generate`. |
| `APP_ENV` | `production` | Compose forces `production`. With any value other than `local` or `testing`, an empty `AGENT_API_KEY` makes the API refuse requests. |
| `APP_DEBUG` | `false` | Compose forces `false`. Never enable where the app is reachable. |
| `APP_URL` | `http://localhost` | Public base URL, used in generated links. |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL` | Laravel defaults; the example ships `stack`, `single`, `debug` | Application log, written to `orchestrator/storage/logs/laravel.log`. |
| `LOGVIEWER_PATH` | `storage/logs/laravel.log` | The file the admin panel's Logs page tails. |
| `DB_CONNECTION` | example ships `sqlite` | The application database, `orchestrator/database/database.sqlite`. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | example ships `database` | Sessions, cache and the job queue all live in the same SQLite file. |

## API and admin access

| Variable | Default | Meaning |
| --- | --- | --- |
| `AGENT_API_KEY` | empty | The key callers send as `X-Api-Key` or a Bearer token. **Required** for any deployment: when empty, the API answers 503 unless `APP_ENV` is `local` or `testing`. Generate with `openssl rand -hex 32`. |
| `ADMIN_NAME` | `Admin` | Name of the admin user created by `php artisan agent:create-admin`. |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | empty | Credentials of that user. Read only when the command runs; changing them later has no effect until you run it again. |

## LLM

| Variable | Default | Meaning |
| --- | --- | --- |
| `LLM_DRIVER` | `prism` | Transport: `prism` (multi-provider) or `legacy_ollama` (an OpenAI-compatible HTTP client). |
| `LLM_PROVIDER` | `anthropic` | Provider for the `prism` driver: `anthropic`, `openai` or `ollama`. |
| `LLM_MODEL` | `claude-haiku-4-5` | Model used for extraction, judging and the domain search. |
| `LLM_FALLBACK_MODEL` | `claude-haiku-4-5` | Model tried when the primary one fails. |
| `LLM_TEMPERATURE` | `0.1` | Sampling temperature. |
| `LLM_MAX_TOKENS` | `2000` | Output ceiling per call. |
| `LLM_TIMEOUT` | `120` | Seconds allowed per LLM call. |
| `ANTHROPIC_API_KEY` | empty | Required when `LLM_PROVIDER=anthropic`. |
| `OPENAI_API_KEY` | empty | Required when `LLM_PROVIDER=openai`. |
| `OLLAMA_URL` | `http://localhost:11434` | Ollama address when `LLM_PROVIDER=ollama`. |
| `OPENAI_API_BASE`, `NUM_CTX` | `http://localhost:11434/v1`, `8192` | Used only by `LLM_DRIVER=legacy_ollama`. |

`config/prism.php` also reads the endpoint and key variables of the other providers the
Prism library supports (`OPENAI_URL`, `ANTHROPIC_URL`, `GEMINI_API_KEY`, `MISTRAL_API_KEY`
and so on). ProfiltAgent is tested with `anthropic`, `openai` and `ollama` only.

## Browser runner

Read by the orchestrator:

| Variable | Default | Meaning |
| --- | --- | --- |
| `RUNNER_BASE` | `http://localhost:8080`; Compose sets `http://browser_runner:8080` | Address of the runner. |
| `RUNNER_TOKEN` | empty | Outside Compose only; under Compose it comes from the root `.env`. |
| `BROWSER_RUNNER_TIMEOUT` | `120` | Seconds the orchestrator waits for one runner call. |
| `MAX_STEPS` | `30` | Maximum actions in one browsing plan. |
| `ALLOWED_DOMAINS` | empty | Comma-separated hosts a browsing plan may visit. Empty means no restriction. |
| `RUNS_DIR` | `storage/app/runs`; Compose sets `/app/runs` | Has no effect on the orchestrator, which always writes artifacts under `storage/app/private/runs/`. Leave it alone; see [D22](known-limitations.md#d22). |

Read by the runner container itself, set in `docker-compose.yml`:

| Variable | Compose value | Meaning |
| --- | --- | --- |
| `MAX_STEPS` | `30` | The runner's own ceiling on actions per plan. |
| `RUNS_DIR` | `/app/runs` | Must match the shared mount, so the orchestrator finds the artifacts. |
| `HEADLESS` | unset (headless) | Set to `false` to run the browser with a window, for debugging. |
| `STEALTH_MODE` | `true` | Applies the browser fingerprint adjustments. `false` disables them. |
| `USER_AGENT`, `VIEWPORT_WIDTH`, `VIEWPORT_HEIGHT` | a desktop Chrome string, `1920`, `1080` | Browser identity. |
| `TIMEZONE`, `LOCALE` | `Europe/Vilnius`, `lt-LT` | Browser timezone and language. Change both for another market. |

## Search

| Variable | Default | Meaning |
| --- | --- | --- |
| `SEARXNG_URL` | `http://searxng:8080` | The self-hosted SearXNG instance. From the host it is `http://localhost:8888`. |
| `SEARXNG_MAX_PER_MINUTE` | `20` | Ceiling on searches per minute across all processes. `0` disables the throttle. See [search throttling](known-limitations.md#search-throttling). |

Which search engines SearXNG queries is configured in `.deploy/searxng/settings.yml`, not
in an environment variable.

## Collector

| Variable | Default | Meaning |
| --- | --- | --- |
| `REACT_SEARCH_ENABLED` | `true` | Use the LLM-driven search to find a company's domain when the caller gives none. |
| `REACT_MAX_STEPS` | `12`; the example ships `8` | Reasoning steps the domain search may take. |
| `REACT_MATCH_JUDGE_ENABLED` | `true` | Check with the LLM that a page belongs to the target company. When off, or when the LLM fails, nothing is blocked. |
| `REACT_MATCH_PASS_THRESHOLD` | `0.7` | Match confidence at or above this is a pass. |
| `REACT_MATCH_FAIL_THRESHOLD` | `0.4` | Match confidence below this rejects the candidate; in between is a warning. |
| `REACT_COVERAGE_GAP_THRESHOLD` | `0.5` | A declared field scoring below this is still a gap, so the next source is tried. |
| `REACT_LADDER_BUDGET_S` | `120` | Wall-clock ceiling for the whole source ladder. A run cut short here reports `partial`. |
| `REACT_OPEN_WEB_ENABLED` | `true` | Read pages about the company outside its own site. |
| `REACT_OPEN_WEB_MAX_PAGES` | `4` | Pages read by that source per run. |
| `REACT_OPEN_WEB_DIRECTORY_URLS` | `https://imoniurekvizitai.lt/paieska/?q={code}` | URL templates with `{code}` that open the company's page on a business directory. Comma-separated; empty disables. A page is used only when it contains the company's registry code. **Check the directory's terms before using it in your deployment.** |
| `REACT_OPEN_WEB_DIRECTORY_SITES` | empty | Hosts searched with `site:<host> <name>`, for directories without a code-addressable URL. |
| `REACT_CONTACTS_ENABLED` | `true` | A targeted pass for e-mails and phone numbers still missing. |
| `REACT_CONTACTS_MAX_PAGES` | `4` | Pages read by that pass per run. |

## News

The list of news portals is not configured here: it is the `news_portals` table, edited at
`/admin/news-portals`.

| Variable | Default | Meaning |
| --- | --- | --- |
| `REACT_NEWS_ENABLED` | `true` | Collect news mentions. |
| `REACT_NEWS_MAX_MENTIONS` | `8` | Mentions kept per run. |
| `REACT_NEWS_FRESHNESS_MONTHS` | `12` | Age below which a mention counts as recent. |
| `REACT_NEWS_STRONG_PORTAL_COUNT` | `3` | Distinct portals needed for the strongest news score. |
| `REACT_NEWS_PORTALS_PER_QUERY` | `7` | Portals combined into one `site:a OR site:b` query. |
| `REACT_NEWS_MAX_QUERIES` | `4` | Hard ceiling on news searches per run. This, not `REACT_NEWS_MAX_PEOPLE`, is what limits how many people are researched: each person costs two searches. |
| `REACT_NEWS_MAX_PEOPLE` | `3` | Upper bound on people searched per run. |
| `REACT_NEWS_TIME_BUDGET_S` | `45` | Wall-clock ceiling for the news phase. `0` means one query only. |
| `REACT_NEWS_FETCH_DATES` | `true` | Download an article to read its publish date when the search result has none. |
| `REACT_NEWS_EXTRACT_TEXT` | `true` | Extract the text of kept articles; the profile carries an excerpt, the full text is stored as a run artifact. |
| `REACT_NEWS_EXTRACT_MAX` | `5` | Articles downloaded for their text per run. |
| `REACT_NEWS_EXCERPT_CHARS` | `600` | Length of the excerpt in the profile. |

## Company registry (optional)

A read-only connection to your own company-registry database (MariaDB or MySQL). The
expected schema is described in `app/Tools/CompanyRegistryRepository.php`.

| Variable | Default | Meaning |
| --- | --- | --- |
| `REGISTRY_DB_HOST` | `127.0.0.1`; the example ships empty | **Empty host means the registry source is skipped** and the registry sections of the admin panel are hidden. |
| `REGISTRY_DB_PORT` | `3306` | |
| `REGISTRY_DB_DATABASE` | `company_registry` | |
| `REGISTRY_DB_USERNAME`, `REGISTRY_DB_PASSWORD` | empty | Use an account with read-only rights. |
| `REGISTRY_DB_TIMEOUT` | `5` | Seconds any single registry query may take during a run. Exceeding it skips the source instead of stalling the run. |
| `REGISTRY_DB_EXPLORATORY_TIMEOUT` | `30` | Separate, longer limit for the admin panel's registry search, which scans the table. |

## Observability (optional)

| Variable | Default | Meaning |
| --- | --- | --- |
| `LANGFUSE_ENABLED` | `true` | Tracing still switches itself off while the keys are empty. |
| `LANGFUSE_PUBLIC_KEY`, `LANGFUSE_SECRET_KEY` | empty | Langfuse project keys. |
| `LANGFUSE_BASE_URL` | `https://cloud.langfuse.com` | Use your own address for a self-hosted Langfuse. |
| `LANGFUSE_PROJECT_ID` | empty | Enables links from the admin panel to a run's trace. |
| `LANGFUSE_QUEUE_CONNECTION` | `sync` | `sync` sends traces inline; `database` hands them to the queue worker. |

When Langfuse is on, prompts and model outputs are sent to it, and they contain the text
of the pages a run read. See [Personal data](personal-data.md#where-data-leaves-your-infrastructure).

## Minimum to start

For a first run you need only these, all in `orchestrator/.env`:

```bash
APP_KEY=            # php artisan key:generate
AGENT_API_KEY=      # openssl rand -hex 32
ADMIN_EMAIL=
ADMIN_PASSWORD=
ANTHROPIC_API_KEY=  # or another provider, with LLM_PROVIDER and LLM_MODEL
```

Everything else has a working default. For a networked deployment also set `RUNNER_TOKEN`
in the root `.env`; the full procedure is in the [deploy runbook](deploy-runbook.md).
