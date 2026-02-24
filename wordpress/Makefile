# Makefile for Levi WordPress Dev Environment

.PHONY: help start stop restart status logs wp shell

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'

start: ## Start the development environment
	ddev start
	@echo ""
	@echo "🚀 Levi WordPress is running!"
	@echo "   Website: https://levi-wordpress.ddev.site"
	@echo "   Admin:   https://levi-wordpress.ddev.site/wp-admin/"

stop: ## Stop the development environment
	ddev stop

restart: ## Restart the development environment
	ddev restart

destroy: ## Destroy the environment (keeps files)
	ddev delete -O

destroy-full: ## Destroy everything including database
	ddev delete -yO

status: ## Show environment status
	ddev status

logs: ## Show logs
	ddev logs

logs-f: ## Show logs (follow)
	ddev logs -f

wp: ## Run WP-CLI command (usage: make wp cmd="plugin list")
	ddev wp $(cmd) --allow-root

shell: ## Open shell in web container
	ddev ssh

phpmyadmin: ## Open PHPMyAdmin
	ddev launch -p

mailhog: ## Open MailHog
	ddev launch -m

open: ## Open website in browser
	ddev launch

setup: ## Initial setup (create symlink and start)
	./setup.sh

install-plugin: ## Install and activate Levi plugin
	ddev wp plugin activate levi-agent --allow-root

flush: ## Flush WordPress caches
	ddev wp cache flush --allow-root

export-db: ## Export database
	ddev export-db --file=backup.sql

import-db: ## Import database (usage: make import-db file=backup.sql)
	ddev import-db --src=$(file)
