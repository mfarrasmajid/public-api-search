# Shortcuts for the local stack. Every target is a thin wrapper around
# docker compose - nothing here is required, it just saves typing.

COMPOSE := docker compose

.DEFAULT_GOAL := help
.PHONY: help up down restart logs ps build shell tinker migrate fresh seed reindex \
        index-status score test test-backend test-crawler lint crawl health openapi \
        psql search clean

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

up: ## Start the phase-1 stack (postgres, opensearch, redis, backend, nginx, frontend)
	$(COMPOSE) up -d
	@echo "UI:   http://localhost:$${APP_PORT:-8080}"
	@echo "API:  http://localhost:$${APP_PORT:-8080}/api/search?q=weather"

up-all: ## Start everything including crawler, workers and dashboards
	$(COMPOSE) --profile crawler --profile workers --profile tools up -d

down: ## Stop all containers (data volumes survive)
	$(COMPOSE) --profile crawler --profile workers --profile tools down

restart: ## Restart the backend container
	$(COMPOSE) restart backend

build: ## Rebuild images
	$(COMPOSE) build

ps: ## Show container status
	$(COMPOSE) ps

logs: ## Tail logs of every service
	$(COMPOSE) logs -f --tail=100

shell: ## Shell inside the backend container
	$(COMPOSE) exec backend bash

tinker: ## Laravel REPL
	$(COMPOSE) exec backend php artisan tinker

# --- data ------------------------------------------------------------------
migrate: ## Run pending migrations
	$(COMPOSE) exec backend php artisan migrate --force

fresh: ## Drop everything, migrate, seed and reindex (destructive)
	$(COMPOSE) exec backend php artisan migrate:fresh --seed --force
	$(COMPOSE) exec backend php artisan search:reindex

seed: ## Load the starter dataset (idempotent)
	$(COMPOSE) exec backend php artisan db:seed --force

reindex: ## Rebuild the OpenSearch index from PostgreSQL
	$(COMPOSE) exec backend php artisan search:reindex

index-status: ## Show cluster health and document count
	$(COMPOSE) exec backend php artisan search:status

score: ## Recompute quality scores and reindex
	$(COMPOSE) exec backend php artisan apis:score --reindex

psql: ## Open a psql session
	$(COMPOSE) exec postgres psql -U $${DB_USERNAME:-api_discovery} -d $${DB_DATABASE:-api_discovery}

# --- crawler (profile: crawler) -------------------------------------------
crawl: ## Crawl the public-apis directory (SOURCE=public-apis LIMIT=200)
	$(COMPOSE) --profile crawler exec crawler python -m crawler crawl $${SOURCE:-public-apis} --limit $${LIMIT:-200}

openapi: ## Discover OpenAPI specs and extract endpoints (LIMIT=20)
	$(COMPOSE) --profile crawler exec crawler python -m crawler openapi --limit $${LIMIT:-20}

health: ## Run health checks on the least recently checked APIs (LIMIT=50)
	$(COMPOSE) --profile crawler exec crawler python -m crawler health --limit $${LIMIT:-50}

# --- quality ---------------------------------------------------------------
test: test-backend test-crawler ## Run every test suite

test-backend: ## PHPUnit
	$(COMPOSE) exec backend php artisan test

test-crawler: ## pytest
	$(COMPOSE) --profile crawler exec crawler pytest -q

lint: ## Ruff on the crawler
	$(COMPOSE) --profile crawler exec crawler ruff check src tests

# --- misc ------------------------------------------------------------------
search: ## Query the API from the CLI (Q="weather indonesia")
	@curl -s "http://localhost:$${APP_PORT:-8080}/api/search?q=$${Q:-weather}" | head -c 2000; echo

clean: ## Stop containers AND delete volumes (destroys all data)
	$(COMPOSE) --profile crawler --profile workers --profile tools down -v
