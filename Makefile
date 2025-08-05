# Makefile for Multi-Tenant Demo

# Variables
DOCKER_COMPOSE = docker compose
PHP_CONTAINER = php
DATABASE_CONTAINER = database

# Colors for output
GREEN = \033[0;32m
YELLOW = \033[0;33m
RED = \033[0;31m
NC = \033[0m # No Color

.PHONY: help build start stop restart logs shell db-shell test fixtures cs-fix phpstan clean

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

install: ## Install Symfony and dependencies
	@echo "$(GREEN)Installing Symfony and dependencies...$(NC)"
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) composer create-project symfony/skeleton:"7.3.*" tmp
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) cp -r tmp/* tmp/.* . 2>/dev/null || true
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) rm -rf tmp
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) composer require webapp
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) composer require --dev symfony/debug-bundle
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) composer require doctrine/orm-pack
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) composer require symfony/maker-bundle --dev
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) composer require symfony/fixtures-bundle --dev
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) composer require "zhortein/multi-tenant-bundle:dev-develop"

setup-bundle: ## Setup multi-tenant bundle configuration
	@echo "$(GREEN)Setting up multi-tenant bundle...$(NC)"
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) php bin/console cache:clear

migrate: ## Run database migrations
	@echo "$(GREEN)Running database migrations...$(NC)"
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) php bin/console doctrine:migrations:migrate --no-interaction

fixtures: ## Load database fixtures
	@echo "$(GREEN)Loading database fixtures...$(NC)"
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) php bin/console doctrine:fixtures:load --no-interaction

test: ## Run tests
	@echo "$(GREEN)Running tests...$(NC)"
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) php bin/phpunit

cs-fix: ## Fix code style
	@echo "$(GREEN)Fixing code style...$(NC)"
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) vendor/bin/php-cs-fixer fix

phpstan: ## Run PHPStan analysis
	@echo "$(GREEN)Running PHPStan analysis...$(NC)"
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) vendor/bin/phpstan analyse

quality: cs-fix phpstan test ## Run all quality checks

clean: ## Clean up containers and volumes
	@echo "$(RED)Cleaning up containers and volumes...$(NC)"
	$(DOCKER_COMPOSE) down -v --remove-orphans
	docker system prune -f

reset: clean build start install setup-bundle migrate fixtures ## Complete reset and setup

# Development helpers
dev-setup: start install setup-bundle migrate fixtures ## Quick development setup
	@echo "$(GREEN)Development environment ready!$(NC)"

tenant-switch: ## Switch tenant context (interactive)
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) php bin/console app:tenant:switch

tenant-list: ## List all tenants
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) php bin/console app:tenant:list

tenant-info: ## Show current tenant info
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) php bin/console app:tenant:info