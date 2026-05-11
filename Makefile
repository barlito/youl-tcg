# Vars
stack_name=ytcg
app_container_id = $(shell docker ps --filter name="$(stack_name)_php" -q)

# Config paths
config_cs_fixer=vendor/barlito/utils/config/.php-cs-fixer.dist.php
config_phpcs=vendor/barlito/utils/config/phpcs.xml.dist
config_phpmd=vendor/barlito/utils/config/phpmd.xml

# Include all make rules from submodule
include make/entrypoint.mk

# Project-specific rules (not in barlito/php-make-rules submodule)
phpstan:
	docker exec -t $(app_container_id) vendor/bin/phpstan analyse --memory-limit=512M

quality: check_style phpstan
