# Deploy runbook

Step-by-step deployment and operation of ProfiltAgent: what the stack consists of, how to
install it for the first time, how to update it, how to check that it works, and the
day-to-day operator procedures.

**Read [§1 Before you start](#1-before-you-start) first.** Both items there cost data or
ship stale code silently.

---

## 1. Before you start

### 1.1 Never `migrate:fresh`

```bash
php artisan migrate:fresh     # ← NEVER on any deployment
```

The local SQLite registry (`orchestrator/database/database.sqlite`) is the accumulated
product of every collection run ever made — `company_profiles`, `collection_runs`,
`collection_actions` — and it is gitignored, so there is no copy in the repository.
`migrate:fresh` drops all of it. It also drops the two admin-editable catalogues
(`news_portals`, `profile_fields`), which are seeded by their own migrations and are
edited in the admin panel afterwards, so re-seeding does not restore operator changes.

The only migration command a deployment needs is `php artisan migrate --force`, which
applies whatever is pending and touches nothing else.

If you believe you need `migrate:fresh`, copy `database/database.sqlite` somewhere outside
the repository first.

### 1.2 A runner change needs an image rebuild, not a restart

`.deploy/Dockerfile.runner` does `COPY runner/ .` into `/app` and runs `node server.js`
from there. The `browser_runner` service in `docker-compose.yml` separately bind-mounts
`./runner:/app/runner:ro` — a **different path**, which nothing executes. The mount does
not shadow the baked code.

Consequence: editing anything under `runner/` and restarting the container changes nothing.
The container keeps serving whatever was baked when the image was last built.

```bash
docker compose build browser_runner && docker compose up -d browser_runner
```

A stale runner image fails quietly: for example, an image built before `POST /markdown`
existed answers that endpoint with a 404, so news-article text extraction does nothing
while every other part of the pipeline reports success.

---

## 2. What you are deploying

Five compose services. The **service** name is what `docker compose` commands take; the
**container** name is what `docker ps` and `docker exec` show (the `profiltagent` prefix
follows `COMPOSE_PROJECT_NAME` in the root `.env`).

| service | container | listens | role |
|---|---|---|---|
| `nginx` | `profiltagent-nginx` | host `:80` → container `:80` | the only entry point; serves the app and proxies `/runner/` |
| `php_orchestrator` | `profiltagent-orchestrator` | `127.0.0.1:9000` (php-fpm) | the Laravel app; **answers no HTTP itself** |
| `browser_runner` | `profiltagent-runner` | `127.0.0.1:8080` | Playwright DSL executor |
| `queue_worker` | `profiltagent-queue-worker` | — | drains the database queue (`CollectCompanyJob`) |
| `searxng` | `profiltagent-searxng` | `127.0.0.1:8888` → container `:8080` | self-hosted search backend |

The LLM is an external provider reached over HTTPS (Anthropic by default; OpenAI or an
Ollama server you run yourself are the alternatives). There is no LLM container in the
stack.

### Ports — what actually answers where

- **`http://localhost/`** — the app. Admin panel at **`http://localhost/admin`**, API at
  `http://localhost/api/run`. This is the only port the Docker stack serves the app on.
- **`:9000`** is php-fpm. It speaks FastCGI, not HTTP. `curl` against it gets nothing
  useful; nginx is what talks to it.
- **`:8000`** is **not part of the Docker stack.** It applies only to a host-based
  `php artisan serve` / `php -S` run outside Docker
  ([§9](#a-host-based-run-the-only-place-8000-applies)). Nothing listens there when only
  the stack is up.
- **`:8080`** is the runner, bound to localhost only. `RUNNER_HOST_PORT` in the root
  `.env` moves the host side if 8080 is taken.
- **`:8888`** is SearXNG, bound to localhost only. Note the asymmetry: whatever
  `orchestrator/.env` says, `docker-compose.yml` sets `SEARXNG_URL=http://searxng:8080`
  in the container environment, and the container environment wins. To change SearXNG for
  one process only, use `docker compose exec -e SEARXNG_URL=… php_orchestrator …` rather
  than editing the file.

The same override applies to `APP_NAME`, `APP_ENV` (`production`), `APP_DEBUG` (`false`),
`RUNNER_BASE`, `RUNNER_TOKEN` and `RUNS_DIR`: under compose they come from
`docker-compose.yml`, not from `orchestrator/.env`.

`searxng` is pinned **by digest**, not by tag (the `image:` of the `searxng` service in
`docker-compose.yml`), because an image refresh can change which upstream engines answer,
and the news dimension depends on that. Do not move it to `:latest` without re-checking
the news results ([§7.6](#76-search-diagnostics)).

---

## 3. Production deploy target

This section describes one production layout, as a worked example: a single Linux host
(`deploy@your-server`, SSH key auth) that **already runs a reverse proxy** on ports 80 and
443 for other services. The clone lives at `~/profiltagent`. Substitute your own host,
user, network and domain names throughout.

Because host ports 80 and 443 belong to the existing proxy, ProfiltAgent must **not** bind
host 80 there. `docker-compose.prod.yml` is the override that handles this:

- replaces the nginx port binding with `127.0.0.1:8090:80` (`ports: !override` — a plain
  `ports:` key would *append*, leaving the host-80 collision in place);
- joins `nginx` and `php_orchestrator` to the proxy's external docker network (named by
  `SHARED_PROXY_NETWORK`, default `shared_proxy`), so the proxy and any other service on
  that network reach `http://profiltagent-nginx/` by service name;
- caps `browser_runner` at `mem_limit: 1500m` (Chromium spikes under host memory pressure).

Every production command therefore carries both files:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml <command>
```

To see what a host is actually running before changing it:

```bash
ssh deploy@your-server 'cd ~/profiltagent && git log --oneline -1 && docker compose ps'
```

On a host with nothing else on port 80, skip the override and use plain `docker compose`;
the app is then served on host `:80` as in local development.

---

## 4. First-time deploy

### 4.1 Clone

```bash
ssh deploy@your-server
cd ~ && git clone <your-git-remote> profiltagent
cd profiltagent
```

### 4.2 Configure

There are two env files with separate jobs:

- **`~/profiltagent/.env`** — stack settings read by docker compose and the Makefile:
  project name, display name, the runner's host port and `RUNNER_TOKEN` (shared by the
  orchestrator and the runner, so it is set once here).
- **`~/profiltagent/orchestrator/.env`** — application settings and every other secret.
  Compose does not pass LLM or API keys into the containers; Laravel reads them from this
  file.

```bash
cd ~/profiltagent && cp .env.example .env
cd ~/profiltagent/orchestrator && cp .env.example .env
```

Then edit `orchestrator/.env`. The values that must be right:

```
APP_URL=https://profiltagent.example.com
DB_CONNECTION=sqlite
LLM_PROVIDER=anthropic
LLM_MODEL=claude-haiku-4-5
```

`LLM_PROVIDER` is `anthropic` (the default, with `claude-haiku-4-5`), `openai`, or
`ollama`; set `LLM_MODEL` to a model of that provider and supply the matching credential
below.

Secrets — never commit these:

- `ANTHROPIC_API_KEY` — for `LLM_PROVIDER=anthropic`. For `openai` set `OPENAI_API_KEY`
  instead; for `ollama` set `OLLAMA_URL` to your Ollama server.
- `AGENT_API_KEY` — generate with `openssl rand -hex 32`. This is the `X-Api-Key` on
  `POST /api/run`. It is required: the stack runs with `APP_ENV=production`, and outside
  the `local` and `testing` environments an empty key makes the API refuse every request.
- `RUNNER_TOKEN` — generate with `openssl rand -hex 32`. The orchestrator sends it to the
  runner as a Bearer token. **This one goes in the root `.env`**, not `orchestrator/.env`:
  compose hands it to both containers. Empty disables runner authentication.

Optional:

- `LANGFUSE_PUBLIC_KEY` / `LANGFUSE_SECRET_KEY` / `LANGFUSE_BASE_URL` — LLM tracing.
  Tracing switches itself off when the keys are empty.
- `REGISTRY_DB_HOST`, `REGISTRY_DB_PORT`, `REGISTRY_DB_DATABASE`, `REGISTRY_DB_USERNAME`,
  `REGISTRY_DB_PASSWORD` — a read-only external company-registry MariaDB. **Most
  deployments leave `REGISTRY_DB_HOST` empty**, in which case the registry source is
  skipped and profiles are collected from the official site, the open web and the news.
  Configure it only if you have such a database; the expected schema is described in
  `orchestrator/app/Tools/CompanyRegistryRepository.php`.

> **Quote any secret that could contain `#`.** `phpdotenv` treats an unquoted `#` as the
> start of an inline comment and silently truncates the value at that character, so the
> effective secret is shorter than the one in the file and anyone copying the full value
> cannot authenticate. Write `AGENT_API_KEY="…"` with quotes. `openssl rand -hex 32`
> output is hex and cannot contain `#`, so a freshly generated key is safe by construction
> — but quote it anyway. ([known-limitations.md D6](./known-limitations.md#d6))

### 4.3 Install PHP dependencies onto the host filesystem

`php_orchestrator` bind-mounts `./orchestrator:/app`, so the mount **shadows** any `vendor/`
baked into the image. `vendor/` must exist on the host or the app has no dependencies:

```bash
cd ~/profiltagent
docker compose -f docker-compose.yml -f docker-compose.prod.yml build php_orchestrator
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  run --rm --no-deps -w /app php_orchestrator composer install --no-dev --optimize-autoloader
```

Ends with "Generating optimized autoload files". This is the opposite of the runner, whose
code is baked and whose mount is inert — the two services are configured differently and
the difference matters in both directions.

### 4.4 Application key

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  run --rm --no-deps php_orchestrator php artisan key:generate --force
```

### 4.5 Create the SQLite database file and open the runtime directories

`migrate` will not create the file itself. The application directory is bind-mounted, so
its files belong to the deploying user, while php-fpm serves requests as `www-data`:
without the `chmod` the web process cannot write sessions, compiled views, the log or the
database, and the admin panel answers 500 while the API still looks healthy.

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  run --rm --no-deps php_orchestrator sh -c \
  'touch database/database.sqlite && chmod a+rwx database && chmod a+rw database/database.sqlite && chmod -R a+rwX storage bootstrap/cache'
```

Locally `make prepare` (part of `make setup`) does the same. It is safe to repeat and
never changes an existing database's contents.

### 4.6 Start the stack

```bash
cd ~/profiltagent
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
docker ps --filter name=profiltagent --format '{{.Names}}\t{{.Status}}'
```

All five containers should read `Up`. To confirm the shared-network join:

```bash
docker network inspect "${SHARED_PROXY_NETWORK:-shared_proxy}" \
  --format '{{range .Containers}}{{.Name}} {{end}}' | tr ' ' '\n' | grep profiltagent
```

### 4.7 Migrate

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  exec -T php_orchestrator php artisan migrate --force
```

This creates all tables and seeds the two catalogues: 14 Lithuanian news portals into
`news_portals`, and the default profile-field set into `profile_fields`. Both are seeded
**by the migrations themselves** — there is no seeder step and `db:seed` is not part of
this deploy.

### 4.8 Create the first admin user

There is no seeded admin:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  exec -T php_orchestrator php artisan agent:create-admin
```

Or non-interactively: `agent:create-admin --email=… --password=… --name=…`.

### 4.9 Expose the admin panel (production only)

In this layout the admin panel is published as `https://profiltagent.example.com` through the
host's **existing** reverse proxy (an nginx container, `<proxy-container>` below), not
ProfiltAgent's own. This is server-side configuration, not repository configuration.

1. Find the proxy's nginx config and back it up: `cp <file> <file>.bak`
2. Read the exact TLS paths from the running config — do not guess them:
   `docker exec <proxy-container> nginx -T 2>/dev/null | grep -E 'ssl_certificate|ssl_certificate_key' | sort -u`
3. Append a `server` block for `profiltagent.example.com` on `listen 443 ssl`, with a
   certificate that covers that name, proxying to `http://profiltagent-nginx` with
   `resolver 127.0.0.11 valid=30s;` and a `set $profiltagent …; proxy_pass $profiltagent;` pair
   (the variable form keeps nginx from failing to start when the upstream container is
   briefly absent). Forward `Host`, `X-Real-IP`, `X-Forwarded-For` and
   `X-Forwarded-Proto https`. Set `client_max_body_size 20m`.
4. Validate and reload — and restore the backup if validation fails:
   `docker exec <proxy-container> nginx -t && docker exec <proxy-container> nginx -s reload`
5. Add a DNS A record for `profiltagent.example.com` pointing at the host's public IP.
6. Verify without waiting for DNS:
   `curl -sk -H 'Host: profiltagent.example.com' -I https://127.0.0.1/admin` → 200 or 302.

---

## 5. Updating an existing deployment

This is the common case. Keep the order: the migration runs **before** the stack is brought
up on the new code, because new code may write columns that only a pending migration
creates, and a write against an unmigrated database fails on the first collection run.

```bash
cd ~/profiltagent

# 1. Get the code.
git pull

# 2. Dependencies, if composer.lock moved.
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  run --rm --no-deps -w /app php_orchestrator composer install --no-dev --optimize-autoloader

# 3. Rebuild images. REQUIRED if anything under runner/ changed (§1.2);
#    harmless otherwise.
docker compose -f docker-compose.yml -f docker-compose.prod.yml build

# 4. Apply pending migrations.
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  exec -T php_orchestrator php artisan migrate --force

# 5. Bring the stack up on the new images.
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# 6. Clear cached config, routes and views. A stale config cache
#    (bootstrap/cache/config.php) masks .env entirely.
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  exec -T php_orchestrator php artisan optimize:clear

# 7. Restart the worker so it loads the new code. A long-running queue worker
#    keeps the classes it booted with.
docker compose -f docker-compose.yml -f docker-compose.prod.yml restart queue_worker
```

If `exec` in step 4 cannot reach a running container, use
`run --rm --no-deps php_orchestrator php artisan migrate --force` instead — it starts a
throwaway container against the same bind-mounted code and database.

---

## 6. Health checks

```bash
# Orchestrator through nginx — JSON, includes the runner URL it will call.
curl http://localhost/api/v1/agent/health          # local
curl http://127.0.0.1:8090/api/v1/agent/health     # production

# Runner, from inside the network (this is the path the orchestrator uses).
docker compose exec -T php_orchestrator sh -c 'curl -s http://browser_runner:8080/health'

# Runner from the host (localhost binding only).
curl http://localhost:8080/health

# Reachable by service name on the shared proxy network — the path another service
# on that network uses. Production only.
docker run --rm --network "${SHARED_PROXY_NETWORK:-shared_proxy}" curlimages/curl \
  -s -o /dev/null -w '%{http_code}\n' http://profiltagent-nginx/api/v1/agent/health
```

Locally, `make health` runs the same kind of check in one go: the orchestrator health
endpoint, the runner and SearXNG.

**`curl http://localhost/health` does not reach the application.** nginx has its own
`location /health` that returns the literal string `healthy` (`.deploy/nginx.conf`), which
shadows the orchestrator's own `/health` JSON route. Use `/api/v1/agent/health` when you
want to know whether Laravel is alive.

### One real end-to-end run

Health checks prove the processes are up, not that collection works. Run one real
company — substitute a real name and domain for the placeholders:

```bash
curl -s -X POST http://127.0.0.1:8090/api/run \
  -H 'Content-Type: application/json' \
  -H "X-Api-Key: ${AGENT_API_KEY}" \
  -d '{"company":"Acmena, UAB","domain":"acmena.example"}' | head -c 800
```

Expect JSON with `verdict`, `coverage`, `sources_used`, `profile`, `field_status`,
`field_verification` and `match_verdicts`, and a new directory under
`orchestrator/storage/app/private/runs/`. See
[api/bootstrap-api.md](./api/bootstrap-api.md) for the full contract, and
[concepts.md](./concepts.md) for what the words in the response mean.

Also worth running once after any LLM or credential change:

```bash
docker compose exec -T php_orchestrator php artisan agent:verify-llm
```

It makes a live structured-output call and builds a profile from it, so it fails loudly on
a bad key, a wrong model id, or a provider-side schema rejection.

---

## 7. Operator procedures

### 7.1 Collect one company by hand

`http://localhost/admin/request-console` (production: `https://profiltagent.example.com/admin/request-console`).
Enter the company's name and, if you have them, its code and domain; choose the fields to
collect; run; and read the result on the same page.

If an external company registry is configured, the page also shows a **Registry search**
block: find the company there and press **Fill** to prefill the form from its registry
facts. Without a registry the page shows a one-line note instead and the form is filled in
by hand.

The console runs the collector **synchronously** in the web request — no queue worker
involved — and raises its own time limit to `3 × react.coverage.ladder_budget_s`
(`REACT_LADDER_BUDGET_S`, default 120 s). A run commonly takes a minute or so.

### 7.2 Batch-enrich registry gap firms (registry required)

```bash
docker compose exec -T php_orchestrator \
  php artisan agent:enrich-firms --with-site=5 --without-site=5 --dry-run
```

This command selects its firms from the external company registry — active companies with
no description and no contacts — so **it only works with a registry connected**
(`REGISTRY_DB_*`); without one it stops with "registry query failed".

Drop `--dry-run` to execute. `--hard` picks the smallest, most obscure firms instead of the
largest. Writes a manifest to `runs/enrichment/{ts}/manifest.json`.

### 7.3 Queued collection

No admin screen queues work: the request console runs synchronously and
`agent:enrich-firms` runs in the foreground. `CollectCompanyJob` is the queued entry point
for your own code — `CollectCompanyJob::dispatch([...])` with the same target array.
Nothing happens unless `queue_worker` is running. If jobs pile up in the `jobs` table,
that is the first thing to check.

### 7.4 The field-verification pass

Per-field match verification is stored on `company_profiles.field_verification`
(see [concepts.md](./concepts.md#verified--unverified--unknown)).

**It is opt-in, per company, on demand.** Set `verify_fields: true` — as a field on
`POST /api/run`, or by ticking **"Verify the match of fields already collected"** in the
request console. It lets the source ladder continue past the coverage break so sources can
confirm values already stored. It is off by default because it spends a browser capture
and LLM calls on data the system already holds.

**Nothing schedules it, and there is no bulk verification command**
([known-limitations.md](./known-limitations.md#d4)). To give a whole set of profiles
verification records, call `POST /api/run` with `verify_fields: true` per company from
your own code; each call costs a full source ladder. API consumers should otherwise treat
`unknown` as "not verified" — see the `verify_fields` section of
[api/bootstrap-api.md](./api/bootstrap-api.md).

Two things to expect when reading verification data:

- `unknown` can be widespread and does **not** clear itself. When the first source alone
  fills every declared field, the ladder stops there and fields that only a later source
  could confirm get no record. Only a re-collection or a `verify_fields` pass clears them.
- A stored value no source re-finds stays `unknown`. That is the correct answer: the pass
  makes `unknown` *addressable*, not unconditionally clearable.

### 7.5 Logs

- Application: `orchestrator/storage/logs/` (bind-mounted, so readable from the host), and
  the **Logs** page in the admin panel.
- Containers: `docker compose logs -f`, `docker compose logs -f searxng`.
- Per-run artifacts: `orchestrator/storage/app/private/runs/{session}/{domain}/analyze/`,
  including `logs/runner.jsonl` and `logs/react_trace.jsonl`.
- LLM traces: Langfuse, when its keys are configured — one trace per `POST /api/run`.
  Traces are started and flushed by the HTTP path; of the CLI commands only
  `agent:enrich-firms` opens its own trace (one per firm).

### 7.6 Search diagnostics

**Check search health before believing "this company has no news coverage."** SearXNG
proxies public search engines, and those rate-limit: a burst of queries can get the
upstream engines suspended, after which searches return empty and look exactly like an
absence of coverage. `SEARXNG_MAX_PER_MINUTE` (default 20) is the orchestrator's own
ceiling on queries per minute, there to prevent exactly that; `0` disables it. The news
layer distinguishes "found nothing" from "could not look" in stored data — see
[concepts.md](./concepts.md#the-news-search-states) — and two commands show the cause
directly.

**Which engines answer, and which honour `site:`:**

```bash
docker compose exec -T php_orchestrator php artisan agent:search-engines
```

Sends four probe queries to SearXNG (plain, quoted, `site:`, and quoted with a `site:`
group) and prints, per probe, how many samples returned results and which engines they
came from. The news dimension is built on `site:`-scoped queries, so the command fails
when no `site:` probe returns anything, even if plain queries work. Options:
`--term=` (a word that certainly has results, default `bankas`), `--site=` (default
`delfi.lt`), `--samples=` and `--delay=` to repeat each probe, `--timeout=`.

**What one query returns, and what the pipeline does with each result:**

```bash
docker compose exec -T php_orchestrator \
  php artisan agent:search-probe '"Acmena" (site:delfi.lt OR site:15min.lt)' --company='Acmena, UAB'
```

Runs one news search exactly as given and lists every result with a verdict: `KEEP`,
`DROP not-a-portal` (the domain is not an enabled news portal) or `DROP off-topic` (with
`--company`, the title and snippet do not contain the company's name tokens). It reports
separately a query that was refused locally (no `SEARXNG_URL`, or the per-minute window is
spent) and a search that returned nothing at all. Options: `--limit=` (default 24),
`--json`.

SearXNG's own configuration, including which engines are enabled, is
`.deploy/searxng/settings.yml`.

---

## 8. Rollback

```bash
cd ~/profiltagent
git checkout <previous-commit>
docker compose -f docker-compose.yml -f docker-compose.prod.yml build
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
docker compose -f docker-compose.yml -f docker-compose.prod.yml restart queue_worker
```

Leave the database schema as it is. Rolling a migration back can discard data — the
`field_verification` migration's `down()`, for example, drops the column and with it every
stored verification record, which can only be rebuilt by re-running the source ladder. An
extra column that older code does not read is harmless.

**Do not roll back by restoring an older `database.sqlite`** unless you intend to discard
every collection run since that copy was taken.

---

## 9. Local development stack

```bash
make setup         # build the images, install PHP dependencies, prepare storage
make prepare       # only: create the SQLite file, make runtime directories writable
make up            # docker compose up -d
make down
make build         # docker compose build --progress=plain
make rebuildupy    # down -> build -> up
make status        # docker compose ps
make logs
make health        # orchestrator health endpoint, runner, SearXNG
make test          # PHP and Node test suites
```

Local, the stack binds host `:80`, so the admin panel is `http://localhost/admin` and the
API is `http://localhost/api/run`. `docker-compose.prod.yml` is not used locally.

Setup from scratch:

```bash
cp .env.example .env                              # stack settings
cp orchestrator/.env.example orchestrator/.env    # then fill in the secrets, quoted
make setup                                        # also creates the SQLite file
make up
docker compose exec -T php_orchestrator php artisan key:generate --force
docker compose exec -T php_orchestrator php artisan migrate --force
docker compose exec -T php_orchestrator php artisan agent:create-admin
```

`AGENT_API_KEY` must be set locally too: the compose stack always runs with
`APP_ENV=production`, where an empty key refuses API requests.

To look around the admin panel without collecting anything, fill an empty database with
invented demo companies, runs and news:

```bash
docker compose exec -T php_orchestrator php artisan agent:seed-demo
```

### Artisan commands

| command | purpose |
|---|---|
| `agent:create-admin` | create or update the admin user ([§4.8](#48-create-the-first-admin-user)) |
| `agent:verify-llm` | live check of the LLM provider ([§6](#one-real-end-to-end-run)) |
| `agent:search-engines` | which SearXNG engines answer and honour `site:` ([§7.6](#76-search-diagnostics)) |
| `agent:search-probe` | one search, with the pipeline's verdict per result ([§7.6](#76-search-diagnostics)) |
| `agent:enrich-firms` | batch collection over registry gap firms; needs a registry ([§7.2](#72-batch-enrich-registry-gap-firms-registry-required)) |
| `agent:seed-demo` | fill an empty database with invented demo data |

### Tests

```bash
cd orchestrator && TEST_TOKEN=$$ php vendor/bin/phpunit tests/
cd runner && npm test
```

`php vendor/bin/phpunit`, **not** `php artisan test`. `TEST_TOKEN` uniquifies
`Storage::fake()`'s root so two concurrent runs in the same tree do not delete each other's
fixtures — the loss otherwise surfaces as a puzzling missing assertion many lines later.
`make test` runs both suites with the token set.

If the PHP suite suddenly takes several times longer than usual, the cause is almost
certainly environmental, not a regression: with `REGISTRY_DB_HOST` set to a host that is
unreachable, console tests that do not bind a `CompanyRegistryRepository` mock each wait
out the connect timeout.

### A host-based run (the only place `:8000` applies)

A collection run takes far longer than PHP's default `max_execution_time`, so a naive
`php artisan serve` times out mid-run. Raise the limit explicitly:

```bash
cd orchestrator
php -d max_execution_time=300 -d memory_limit=512M -S 127.0.0.1:8000 -t public
```

This bypasses nginx and php-fpm entirely. It also bypasses the container's environment
overrides, so `SEARXNG_URL` and `RUNNER_BASE` come from `orchestrator/.env` and must point at
the **published host ports** (`http://localhost:8888`, `http://localhost:8080`), not the
service names `searxng` and `browser_runner` that `.env.example` ships with.

---

## 10. Related documents

- [overview.md](./overview.md) — what the product does, in one page.
- [known-limitations.md](./known-limitations.md) — what is still open, and what it costs.
- [concepts.md](./concepts.md) — what `verified`, `supplied`, `unanswered` and the rest mean.
- [api/bootstrap-api.md](./api/bootstrap-api.md) — the `POST /api/run` contract.
- [architecture-overview.md](./architecture-overview.md) and
  [codebase-map.md](./codebase-map.md) — how it is built and where things live.
