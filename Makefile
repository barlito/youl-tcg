# Vars
stack_name=ytcg
app_container_id = $(shell docker ps --filter name="$(stack_name)_php" -q)
db_container_id = $(shell docker ps --filter name="$(stack_name)_db" -q)
prod_host=ytcg.youlz.fr
image_name=barlito/youl-tcg
db_user=postgres

# Config paths
config_cs_fixer=vendor/barlito/utils/config/.php-cs-fixer.dist.php
config_phpcs=vendor/barlito/utils/config/phpcs.xml.dist

# Include all make rules from submodule
include make/entrypoint.mk

# Tailwind est requis pour rendre base.html.twig (TailwindCssAssetCompiler lève
# une exception si var/tailwind/tailwind.built.css est absent) — nécessaire en CI
# avant les tests fonctionnels qui rendent des pages.
# Retry : au premier build, le bundle télécharge son binaire depuis GitHub
# Releases, qui 504 par intervalles (2 runs cassés le 2026-07-09) — une fois
# le binaire présent (cache CI ou var/tailwind local), plus aucun réseau.
tailwind.build:
	@for i in 1 2 3 4 5; do \
		docker exec -t $(app_container_id) bin/console tailwind:build --minify && exit 0; \
		echo "tailwind:build KO (tentative $$i/5) — retry dans 20s"; sleep 20; \
	done; echo "tailwind:build KO après 5 tentatives"; exit 1

doctrine.schema_validate.ci:
	docker exec -t $(app_container_id) bin/console doctrine:schema:validate --env=test

jwt.generate:
	castor generate-jwt-key-pair

quality: check_style
