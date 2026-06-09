# Youl TCG

A Trading Card Game web app set in the Youls universe. Users authenticate
via Discord SSO, open booster packs, build a collection, and trade cards
with other players.

[![Starter workflow](https://github.com/barlito/php-starter/actions/workflows/symfony_starter.yaml/badge.svg?branch=master)](https://github.com/barlito/php-starter/actions/workflows/symfony_starter.yaml)

## Stack

- **Backend:** Symfony 7.4 (PHP 8.4), Doctrine ORM 3, PostgreSQL 18
- **Frontend:** AssetMapper (no npm build), Tailwind, Flowbite, Stimulus,
  Symfony UX Live Components
- **Auth:** Discord OAuth2 + JWT cookie (via [Lexik JWT Bundle](https://github.com/lexik/LexikJWTAuthenticationBundle))
- **Admin:** EasyAdmin 4
- **Runtime:** FrankenPHP behind Traefik, deployed as a Docker Swarm stack
  (`ytcg`) using [barlito/traefik-base](https://github.com/barlito/traefik-base)

## Requirements

- [Castor](https://castor.jolicode.com/)
- Docker (Swarm-capable host)
- [barlito/traefik-base](https://github.com/barlito/traefik-base) deployed
  so the `traefik_traefik_proxy` external network exists

## Getting started

```bash
# 1. Bring up the stack (php, db, adminer)
make docker.deploy

# 2. Install dependencies (run inside the container)
docker exec -it $(docker ps --filter name=ytcg_php -q) composer install

# 3. Generate JWT keys
castor generate-jwt-key-pair

# 4. Create the DB and run migrations
make db.create
make db.migration

# 5. Load fixtures (dev only)
make db.fixtures.load
```

The app is then served on `https://ytcg.local.barlito.fr` (configure your
hosts file or DNS).

## Development workflow

```bash
# Code quality (cs-fix + cs-check + phpmd + phpstan)
make quality

# Tests
make test

# Create a new Doctrine migration after entity changes
make db.diff
```

See [CLAUDE.md](./CLAUDE.md) for the full developer guide (architecture
deep dive, domain model, conventions, gotchas).

## Project layout

```
src/
├─ Entity/                  Doctrine entities (anemic by design)
├─ Enum/                    Status, rarity, type enums
├─ Repository/              Doctrine repositories
├─ Domain/                  Pure-PHP business logic (testable, no Doctrine)
├─ Application/             Orchestrators that touch the DB and events
├─ Controller/              HTTP entry points
├─ Twig/Components/         Live + Twig Components
├─ EventListener/           JWT auth flow listeners
└─ Service/                 Cross-cutting utilities
```

## Repository conventions

- Conventional Commits (`feat:`, `fix:`, `chore:`, `refactor:`, etc.)
- PSR-12 via PHP CS Fixer (config in `vendor/barlito/utils`)
- PHPStan level 6 (config in `phpstan.dist.neon`)
- Migrations are committed; never edit a migration already on master
