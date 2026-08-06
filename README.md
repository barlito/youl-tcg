# Youl TCG

[![CI](https://github.com/barlito/youl-tcg/actions/workflows/entrypoint.yaml/badge.svg?branch=master)](https://github.com/barlito/youl-tcg/actions/workflows/entrypoint.yaml)

Trading card game web app for the Youl community. Log in with Discord, claim free daily boosters, open them with an animated reveal, and build your collection across card extensions — with interactive 3D cards (holo, foil, rarity glows) rendered in pure CSS/JS.

## At a glance

| Domain | What it does |
|--------|--------------|
| **Cards & extensions** | Cards (artwork + foil mask + holo) grouped into extensions/sets, each with its own visual config cascade (`Card` override → `Extension` defaults → rarity defaults) |
| **Rarities** | 4 tiers — common / uncommon / rare / legendary — with per-tier glows; unique 1/1 cards and always-holo flags |
| **Boosters** | Per-slot weighted rarity rates + holo chance (JSON `rarityRates`); drop rates shown to players; claimable or event-only distribution |
| **Daily claims** | 2 free boosters/day (reset midnight Europe/Paris), quota computed by counting `BoosterClaim` rows — no mutable counters |
| **Opening engine** | Single-transaction open: pessimistic-locked inventory debit, seeded RNG draw (reproducible from the stored seed), audit trail (`BoosterOpening` + cards) |
| **Universe pages** | `/univers` pokédex: per-set completion, unowned cards masked behind the card back, banner carousels, 1/1 drop status |
| **Admin** | EasyAdmin panel: card/extension/booster CRUD, live card preview, batch operations |

## Stack

- **Symfony 7.4 LTS** (PHP 8.4) — Live Components + Stimulus, AssetMapper (no build step), Tailwind
- **FrankenPHP** — Caddy-based server, multi-stage Docker image (`frankenphp_dev` / `frankenphp_prod`)
- **PostgreSQL 18** — Doctrine ORM 3, UUID PKs, custom `RANDOM()` DQL function
- **Auth** — Discord OAuth2 + JWT cookie (lexik/jwt), users auto-created from the JWT payload
- **Docker Swarm** — stack `ytcg`, behind [traefik-base](https://github.com/barlito/traefik-base)
- **Make + Castor** — task automation via the [php-make-rules](https://github.com/barlito/php-make-rules) submodule

## Quick start

```bash
git clone git@github.com:barlito/youl-tcg.git
cd youl-tcg && git submodule update --init --recursive

# Deploy the dev stack (requires traefik-base running)
make docker.deploy

# Generate the JWT keypair (required for auth)
castor generate-jwt-key-pair

# Database
make doctrine.migrate
make doctrine.load_fixtures
```

App: `ytcg.local.barlito.fr` — Adminer: `ytcg-adminer.local.barlito.fr` (prod: `ytcg.barlito.fr`).

Discord OAuth needs `OAUTH_DISCORD_CLIENT_ID` / `OAUTH_DISCORD_CLIENT_SECRET` in your env (see `.env` for the full list: `DATABASE_URL`, `JWT_*`, `APP_SECRET`, …).

## Requirements

- Docker (Swarm mode)
- Make + [Castor](https://castor.jolicode.com/)
- [barlito/traefik-base](https://github.com/barlito/traefik-base) running

## Commands

| Command | Description |
|---------|-------------|
| `make docker.deploy` | Deploy the dev stack |
| `make docker.bash` | Shell into the PHP container |
| `make deploy.prod` | Prod deploy (deploy → DB backup → migrate → smoke test) |
| `make doctrine.migrate` | Run migrations |
| `make doctrine.diff` | Generate a migration from entity changes |
| `make doctrine.load_fixtures` | Load Alice fixtures |
| `make db.backup` | `pg_dump` into the backup volume |
| `make quality` | All checks: composer validate, phpcs, cs-fixer, PHPStan (level 8, zero baseline), Rector |
| `make phpunit` | Test suite |
| `make tailwind.build` | Build Tailwind CSS (needed before rendering pages) |
| `castor generate-jwt-key-pair` | Generate the JWT keypair |
| `castor holo:masks --slug=<ext>` | Auto-generate holo masks for an extension's cards (`tools/holo`) |

## Tests

PHPUnit 12 — `tests/Unit`, `tests/Integration`, `tests/Functional` (browser-kit + dama/doctrine-test-bundle). The booster opening engine (quota, weighted draw, holo rolls, seeded reproducibility) is fully covered at the service level.

```bash
make phpunit
```

## CI/CD

`entrypoint.yaml` runs on every push and fans out to `code-quality.yaml` (cs-fixer / phpcs / PHPStan / Rector) and `test.yaml` (full stack in CI: deploy, migrate, fixtures, Tailwind, PHPUnit). `release.yaml` builds and pushes images on GitHub Release; `deploy.yaml` / `rollback.yaml` are manual; Trivy scans run weekly (`security.yaml`).

## Docs

- [`docs/card-setup-guide.md`](docs/card-setup-guide.md) — adding cards, masks and foils
- [`docs/card-effect.md`](docs/card-effect.md) — the 3D/holo card effect internals
- [`docs/prod-uploads-migration.md`](docs/prod-uploads-migration.md) — uploads volume in prod

## Related

- [barlito/ytcg-game-client](https://github.com/barlito/ytcg-game-client) — game client
- [barlito/youl-tcg-showcase](https://github.com/barlito/youl-tcg-showcase) — showcase site
- [barlito/youl-coin-api](https://github.com/barlito/youl-coin-api) — YoulCoin currency API
- [barlito/php-starter](https://github.com/barlito/php-starter) — the Symfony starter this project is built from
- [barlito/traefik-base](https://github.com/barlito/traefik-base) — Traefik proxy stack
