# The demo site. `make up` starts it, `make install` fills it in, `make reset` throws it away.
.DEFAULT_GOAL := help

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

up: ## Start WordPress on http://localhost:8087 and set it up
	docker compose up -d
	@$(MAKE) --no-print-directory install

install: ## Install/repair the demo site (idempotent)
	docker compose run --rm cli /tools/install.sh

down: ## Stop it, keeping the database
	docker compose down

reset: ## Stop it and delete the site and its database
	docker compose down -v

logs: ## Follow the WordPress log
	docker compose logs -f wordpress

shell: ## A wp-cli shell
	docker compose run --rm cli bash

.PHONY: help up install down reset logs shell
