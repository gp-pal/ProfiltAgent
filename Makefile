# ProfiltAgent - Lithuanian company profile collection agent
# A self-hosted agent that builds a verified company profile from the registry,
# the official site, the open web and the news.

# Project slug and display name come from the root .env (see .env.example).
-include .env
COMPOSE_PROJECT_NAME ?= profiltagent
APP_NAME ?= ProfiltAgent
RUNNER_HOST_PORT ?= 8080

.DEFAULT_GOAL := help
.PHONY: help up down logs recreate build rebuildupy build-fallback test install-deps prepare setup clean health test-llm status logs-build logs-error queue

##@ Quick Start
help: ## Display this help menu
	@awk 'BEGIN {FS = ":.*##"; printf "\n\033[1m\033[36m$(APP_NAME) - Available Commands\033[0m\n"} /^[a-zA-Z_0-9-]+:.*?##/ { printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

setup: build install-deps prepare ## >> Complete setup - build images, install dependencies, prepare storage
	@echo "\n\033[32m[*] $(APP_NAME) setup complete!\033[0m"
	@echo "\033[33m -> Run 'make up' to start services\033[0m"
	@echo "\033[33m -> Run 'make health' to check them\033[0m\n"

##@ Docker Operations
up: ## Start all services in background
	@echo "\033[34m[+] Starting $(APP_NAME) services...\033[0m"
	docker compose up -d

down: ## Stop all services
	@echo "\033[33m[-] Stopping services...\033[0m"
	docker compose down

recreate: ## Recreate containers (useful for config changes)
	@echo "\033[34m[~] Recreating containers...\033[0m"
	docker compose up -d --force-recreate

status: ## Show service status
	@echo "\033[36m[i] Service Status:\033[0m"
	docker compose ps

##@ Build & Development
build: ## Build Docker images with detailed output
	@echo "\033[34m[#] Building Docker images...\033[0m"
	docker compose build --progress=plain

rebuildupy: down build up ## Full rebuild cycle: stop -> build -> start

build-fallback: ## Build using fallback Dockerfile (for network issues)
	@echo "\033[33m[!] Building with fallback Dockerfile...\033[0m"
	docker build -f .deploy/Dockerfile.orchestrator.fallback -t $(COMPOSE_PROJECT_NAME)-orchestrator:latest --progress=plain .

install-deps: ## Install PHP dependencies (inside the orchestrator image; no host PHP needed)
	@echo "\033[34m[+] Installing dependencies...\033[0m"
	docker compose run --rm --no-deps php_orchestrator composer install

# The application directory is bind-mounted from the host, so its files belong to
# whoever cloned the repo — while php-fpm serves requests as www-data and artisan runs
# as root. Without this step the web process cannot write sessions, compiled views,
# the log or the SQLite file, and the admin panel answers 500. Runs inside the
# container (as root) so it also repairs anything Docker created root-owned. Safe to
# repeat; never touches an existing database's contents.
prepare: ## Create the SQLite file and make the runtime directories writable
	@echo "\033[34m[+] Preparing storage and database file...\033[0m"
	docker compose run --rm --no-deps php_orchestrator sh -c 'touch database/database.sqlite && chmod a+rwx database && chmod a+rw database/database.sqlite && chmod -R a+rwX storage bootstrap/cache'

##@ Testing & Analysis
test: ## Run all tests (PHP + Node.js)
	@echo "\033[34m[T] Running tests...\033[0m"
# TEST_TOKEN ($$ = this shell's PID) uniquifies Storage::fake()'s root. Without a
# token every phpunit process in this working tree shares
# storage/framework/testing/disks/<disk> and each Storage::fake() wipes it, so two
# concurrent runs delete each other's files. The `local` disk has 'throw' => false,
# so the loss surfaces dozens of lines later as a missing assertion rather than a
# write error. Nothing else here reads the token: the DB is :memory: and
# CACHE_STORE=array (phpunit.xml), and the DB/cache/view renaming lives in
# ParallelTesting callbacks that only `artisan test --parallel` registers.
	cd orchestrator && TEST_TOKEN=$$$$ php vendor/bin/phpunit tests/
	cd runner && npm test

queue: ## Run the queue worker (processes collection jobs)
	@echo "\033[34m[Q] Starting queue worker...\033[0m"
	cd orchestrator && php artisan queue:work --tries=1 --timeout=600

health: ## Check service health status
	@echo "\033[36m[H] Checking service health...\033[0m"
	@curl -fsS http://localhost/api/v1/agent/health >/dev/null && echo "[OK] Orchestrator" || echo "[X] Orchestrator not ready"
	@curl -fsS http://localhost:$(RUNNER_HOST_PORT)/health >/dev/null && echo "[OK] Runner" || echo "[X] Runner not ready"
	@docker compose exec -T searxng wget -q -O /dev/null http://localhost:8080/healthz && echo "[OK] SearXNG" || echo "[X] SearXNG not ready"

##@ LLM
test-llm: ## Verify LLM integration (social-link extraction round-trip)
	@echo "\033[35m[AI] Verifying LLM integration...\033[0m"
	cd orchestrator && php artisan agent:verify-llm

##@ Monitoring & Debugging
logs: ## Follow all service logs in real-time
	@echo "\033[36m[LOG] Following logs...\033[0m"
	docker compose logs -f

logs-build: ## Show detailed build logs with timestamps
	@echo "\033[36m[LOG] Build logs:\033[0m"
	docker compose logs --no-color --timestamps

logs-error: ## Follow recent error logs (last 100 lines)
	@echo "\033[31m[ERR] Error logs:\033[0m"
	docker compose logs -f --tail=100

##@ Cleanup
clean: ## Clean up everything (containers, images, dependencies)
	@echo "\033[31m[DEL] Cleaning up...\033[0m"
	docker compose down -v
	docker system prune -f
	rm -rf orchestrator/vendor/
	rm -rf runner/node_modules/
	rm -rf orchestrator/runs/