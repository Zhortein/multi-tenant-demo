# Makefile for Multi-Tenant Demo

# Variables
DOCKER_COMPOSE = docker compose
PHP_CONTAINER = php
DATABASE_CONTAINER = database
TEST_DATABASE_BASE_URL ?= postgresql://app:!ChangeMe!@database:5432/app?serverVersion=18&charset=utf8

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
	$(DOCKER_COMPOSE) exec -T -e DATABASE_URL="$(TEST_DATABASE_BASE_URL)" $(PHP_CONTAINER) php bin/phpunit

quality: test-database fixtures schema-validate test ## Run all currently declared quality checks

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
