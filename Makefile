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

# On the host, because they need PHP and nothing else — no WordPress, no database, no container.
test: ## Run the plugin's tests
	composer install --no-interaction --quiet
	vendor/bin/phpunit --colors=never

# `bho-ladder/` has to be the top folder inside the archive, or WordPress installs the plugin into a
# directory named after the zip and the next update lands beside it instead of over it. The version
# comes out of the plugin header, so the file is named after what it contains.
zip: ## Build the installable bho-ladder-<version>.zip from the committed files
	@version=$$(sed -n 's/^ \* Version: *//p' bho-ladder/bho-ladder.php | tr -d ' \r'); \
	rm -f bho-ladder-*.zip; \
	git archive --format=zip --prefix=bho-ladder/ -o "bho-ladder-$$version.zip" HEAD:bho-ladder; \
	echo "bho-ladder-$$version.zip"; \
	unzip -l "bho-ladder-$$version.zip" | tail -1

.PHONY: help up install down reset logs shell test zip
