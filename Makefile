# Vars
stack_name=ytcg
app_container_id = $(shell docker ps --filter name="$(stack_name)_php" -q)
db_container_id = $(shell docker ps --filter name="$(stack_name)_db" -q)
prod_host=ytcg.youlz.fr
image_name=barlito/youl-tcg
backup_path=/srv/ytcg/backups
prod_compose_file=docker-compose-prod.yml
assert_timeout=180
deploy_timeout=300

# Config paths
config_cs_fixer=vendor/barlito/utils/config/.php-cs-fixer.dist.php
config_phpcs=vendor/barlito/utils/config/phpcs.xml.dist

# Include all make rules from submodule
include make/entrypoint.mk

### Overrides — submodule rules adaptées au projet

# Rolling stack deploy, never `stack rm`: a recreated service has nothing to roll back to
deploy.prod:
	make db.backup
	make docker.deploy.prod
	make deploy.assert_image
	castor barlito:castor:wait-db-container
	make doctrine.migrate
	make smoke.test
	make deploy.prune

# Waits for convergence or rollback; a crash-looping new service never converges, assert_image reports it
docker.deploy.prod:
	docker compose -f $(prod_compose_file) pull
	@timeout $(deploy_timeout) docker stack deploy --detach=false -c $(prod_compose_file) $(stack_name) \
		|| { rc=$$?; [ $$rc -eq 124 ] && echo "⚠ $(stack_name) not converged after $(deploy_timeout)s"; [ $$rc -eq 124 ]; }

# Dump pg_dump dans le volume host $(backup_path) (monté dans le container db).
# Format -F c (custom, compressé). Skippé silencieusement si la DB n'est pas démarrée
# (cas 1er deploy).
db.backup:
	@cid="$$(docker ps --filter name='$(stack_name)_db' -q | head -1)"; \
	if [ -n "$$cid" ]; then \
		ts="$$(date +%Y%m%d-%H%M%S)"; \
		echo "🗄  Backup DB → $(backup_path)/ytcg-$$ts.dump (container $$cid)"; \
		docker exec -t "$$cid" sh -c "pg_dump -U postgres -F c -d ytcg -f /backups/ytcg-$$ts.dump"; \
	else \
		echo "ℹ  DB container introuvable, backup skippé (1er deploy ?)"; \
	fi

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

# Swarm rollbacks exit 0: require $(TAG) in the spec, no rollback, and a healthy task still up after assert_settle
assert_settle=10
deploy.assert_image:
	@expected="$(image_name):$(TAG)"; service="$(stack_name)_php"; \
	deadline=$$(( $$(date +%s) + $(assert_timeout) )); \
	while :; do \
		state="$$(docker service inspect $$service --format '{{if .UpdateStatus}}{{.UpdateStatus.State}}{{end}}')"; \
		case "$$state" in updating|rollback_started) ;; *) break;; esac; \
		[ $$(date +%s) -lt $$deadline ] || break; \
		echo "… $$service update state: $$state"; sleep 5; \
	done; \
	image="$$(docker service inspect $$service --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}')"; \
	case "$$image" in "$$expected"|"$$expected"@*) ;; *) echo "❌ $$service runs $$image instead of $$expected (update state: $${state:-none})"; exit 1;; esac; \
	case "$$state" in rollback_*|paused|updating) echo "❌ $$service update state: $$state"; exit 1;; esac; \
	stable=""; \
	while :; do \
		task="$$(docker service ps $$service --filter desired-state=running -q | head -1)"; \
		info=""; health=""; \
		if [ -n "$$task" ]; then \
			info="$$(docker inspect $$task --format '{{.Status.State}} {{.Spec.ContainerSpec.Image}}')"; \
			cid="$$(docker inspect $$task --format '{{if .Status.ContainerStatus}}{{.Status.ContainerStatus.ContainerID}}{{end}}')"; \
			[ -z "$$cid" ] || health="$$(docker inspect $$cid --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' 2>/dev/null)"; \
		fi; \
		case "$$info" in "running $$expected"|"running $$expected@"*) ok=$$health;; *) ok="";; esac; \
		if [ "$$ok" = healthy ] || [ "$$ok" = none ]; then \
			[ "$$stable" = "$$task" ] && break; \
			stable="$$task"; sleep $(assert_settle); continue; \
		fi; \
		stable=""; \
		if [ $$(date +%s) -ge $$deadline ]; then echo "❌ $$service task $${task:-none} not healthy on $$expected ($${info:-no task}, health: $${health:-unknown})"; exit 1; fi; \
		echo "… $$service task $${task:-none}: $${info:-no task}, health: $${health:-unknown}"; sleep 5; \
	done; \
	echo "✓ $$service runs $$expected, task $$task healthy (update state: $${state:-none})"

# Removes the stack's stopped containers (old tasks); prune never touches running ones
deploy.prune:
	docker container prune -f --filter label=com.docker.stack.namespace=$(stack_name)

# Smoke test : curl GET / → fail sur 4xx/5xx (le 302 vers l'IdP prouve que l'app répond).
smoke.test:
	@echo "🩺 Smoke test https://$(prod_host)/..."
	@curl -fsS -o /dev/null -w "  HTTP %{http_code}\n" https://$(prod_host)/ || (echo "❌ Smoke test KO" && exit 1)
	@echo "✓ Smoke test OK"

quality: check_style
