# Makefile for Multi-Tenant Demo

# Variables
DOCKER_COMPOSE = docker compose
PHP_CONTAINER = php
DATABASE_CONTAINER = database
TEST_DATABASE_BASE_URL ?= postgresql://app:!ChangeMe!@database:5432/app?serverVersion=18&charset=utf8
PHP_TEST_OPTIONS = -d memory_limit=512M -d zend.exception_ignore_args=1
STORAGE_COMPOSE = $(DOCKER_COMPOSE) -f compose.yaml -f compose.override.yaml -f compose.storage.yaml
PHP_CS_FIXER_IMAGE = ghcr.io/php-cs-fixer/php-cs-fixer:3.95.24-php8.3@sha256:7033fc432deb3dc29531da39a1fbd09c10bb0e7d61b33ce354b7a25f96486087

# Colors for output
GREEN = \033[0;32m
YELLOW = \033[0;33m
RED = \033[0;31m
NC = \033[0m # No Color

.PHONY: help build start stop restart logs shell db-shell install setup-bundle migrate fixtures test-database schema-validate test quality clean destroy-local-data dev-setup tenant-switch tenant-list tenant-info

help: ## Show this help message
	@echo "$(GREEN)Multi-Tenant Demo - Available commands:$(NC)"
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  $(YELLOW)%-15s$(NC) %s\n", $$1, $$2}' $(MAKEFILE_LIST)

build: ## Build Docker containers
	@echo "$(GREEN)Building Docker containers...$(NC)"
	$(DOCKER_COMPOSE) build --no-cache

start: ## Start the application
	@echo "$(GREEN)Starting the application...$(NC)"
	$(DOCKER_COMPOSE) up -d
	@echo "$(GREEN)Application started! Visit https://localhost$(NC)"

stop: ## Stop the application
	@echo "$(YELLOW)Stopping the application...$(NC)"
	$(DOCKER_COMPOSE) down

restart: stop start ## Restart the application

logs: ## Show application logs
	$(DOCKER_COMPOSE) logs -f

shell: ## Access PHP container shell
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) sh

db-shell: ## Access database shell
	$(DOCKER_COMPOSE) exec $(DATABASE_CONTAINER) psql -U app -d app

install: ## Restore the exact dependencies recorded in composer.lock
	@echo "$(GREEN)Restoring locked Composer dependencies...$(NC)"
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) composer install --prefer-dist --no-progress --no-interaction

setup-bundle: ## Setup multi-tenant bundle configuration
	@echo "$(GREEN)Setting up multi-tenant bundle...$(NC)"
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) php bin/console cache:clear

migrate: ## Run database migrations
	@echo "$(GREEN)Running database migrations...$(NC)"
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) php bin/console doctrine:migrations:migrate --no-interaction

test-database: ## Create and migrate the isolated test database
	@echo "$(GREEN)Preparing the test database...$(NC)"
	$(DOCKER_COMPOSE) exec -T -e DATABASE_URL="$(TEST_DATABASE_BASE_URL)" $(PHP_CONTAINER) php bin/console --env=test doctrine:database:create --if-not-exists
	$(DOCKER_COMPOSE) exec -T -e DATABASE_URL="$(TEST_DATABASE_BASE_URL)" $(PHP_CONTAINER) php bin/console --env=test doctrine:migrations:migrate --no-interaction

fixtures: ## Load deterministic demo fixtures into the isolated test database
	$(DOCKER_COMPOSE) exec -T -e DATABASE_URL="$(TEST_DATABASE_BASE_URL)" $(PHP_CONTAINER) php bin/console --env=test app:create-sample-data

schema-validate: ## Validate Doctrine mappings against the test database
	$(DOCKER_COMPOSE) exec -T -e DATABASE_URL="$(TEST_DATABASE_BASE_URL)" $(PHP_CONTAINER) php bin/console --env=test doctrine:schema:validate

test: ## Run tests against the isolated test database
	@echo "$(GREEN)Running tests...$(NC)"
	$(DOCKER_COMPOSE) exec -T -e DATABASE_URL="$(TEST_DATABASE_BASE_URL)" $(PHP_CONTAINER) php $(PHP_TEST_OPTIONS) bin/phpunit

.PHONY: storage-certificates storage-start storage-status storage-test storage-stop

storage-certificates: ## Generate local TLS material without printing private keys
	$(STORAGE_COMPOSE) run --rm --no-deps --entrypoint php -v "$(CURDIR)/var:/app/var" $(PHP_CONTAINER) tools/storage-certificates.php

storage-start: storage-certificates ## Start local private MinIO and the application
	$(STORAGE_COMPOSE) up -d --wait --wait-timeout 60 minio database $(PHP_CONTAINER)
	$(STORAGE_COMPOSE) run --rm --no-deps storage-provision

storage-status: ## Show local object storage readiness
	$(STORAGE_COMPOSE) ps minio

storage-test: ## Prove RC11 object storage against real MinIO (no skips)
	$(STORAGE_COMPOSE) exec -T -e DATABASE_URL="$(TEST_DATABASE_BASE_URL)" $(PHP_CONTAINER) php $(PHP_TEST_OPTIONS) bin/phpunit tests/Integration/ObjectStorageTest.php tests/Integration/ObjectStorageMessengerTest.php

storage-stop: ## Stop the local stack and preserve its data volumes
	$(STORAGE_COMPOSE) down

.PHONY: phpstan cs-check composer-check

composer-check: ## Accept only the documented exact-RC warning and audit the lock
	$(DOCKER_COMPOSE) exec -T $(PHP_CONTAINER) sh tools/validate-composer.sh
	$(DOCKER_COMPOSE) exec -T $(PHP_CONTAINER) composer audit --locked

phpstan: ## Run maximum-level PHPStan with the measured pre-RC11 baseline
	$(DOCKER_COMPOSE) exec -T $(PHP_CONTAINER) vendor/bin/phpstan analyse --no-progress --memory-limit=512M

cs-check: ## Check formatting of the RC11 integration surface
	docker run --rm --network none -v "$(CURDIR):/code:ro" $(PHP_CS_FIXER_IMAGE) fix --dry-run --diff --using-cache=no

quality: test-database fixtures schema-validate test phpstan cs-check composer-check ## Run all declared quality checks

clean: ## Stop and remove project containers without deleting data
	@echo "$(YELLOW)Stopping and removing project containers...$(NC)"
	$(DOCKER_COMPOSE) down --remove-orphans

destroy-local-data: ## Irreversibly remove this project's containers and volumes (CONFIRM=destroy)
	@test "$(CONFIRM)" = "destroy" || (echo "$(RED)Refusing to delete local data. Re-run with CONFIRM=destroy.$(NC)" && exit 1)
	@echo "$(RED)Removing this project's containers and volumes...$(NC)"
	$(DOCKER_COMPOSE) down -v --remove-orphans

# Development helpers
dev-setup: start install setup-bundle migrate ## Restore and prepare the development environment
	@echo "$(GREEN)Development environment ready!$(NC)"

tenant-switch: ## Switch tenant context (interactive)
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) php bin/console app:tenant:switch

tenant-list: ## List all tenants
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) php bin/console app:tenant:list

tenant-info: ## Show current tenant info
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) php bin/console app:tenant:info
