# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Youl TCG** is a Trading Card Game (TCG) application built with Symfony 7.4 LTS. Users authenticate via Discord OAuth2, claim free daily boosters, open them with an animated card reveal and collect trading cards organized into extensions. The application features advanced 3D interactive card rendering with JavaScript-based animations.

## Development Environment

### Stack & Tools
- **Framework:** Symfony 7.4 LTS (PHP 8.4)
- **Database:** PostgreSQL 18
- **Web Server:** FrankenPHP (Caddy-based, non-root user `www`)
- **Authentication:** JWT (cookie-based) + Discord OAuth2
- **Admin Panel:** EasyAdminBundle 4.x
- **Frontend interactivity:** Stimulus + symfony/ux-live-component (AssetMapper, no build step)
- **Task Runner:** Castor + Makefile (uses barlito/php-make-rules submodule)
- **Container Orchestration:** Docker Swarm (stack name: `ytcg`)
- **Reverse Proxy:** Traefik (external traefik_traefik_proxy network, TLS terminated by Traefik)

### Common Commands

**Docker & Deployment:**
```bash
# Deploy full stack (docker-compose.yml)
make docker.deploy

# Get shell in PHP container
docker exec -it $(docker ps --filter name="ytcg_php" -q) bash

# View container logs
docker service logs ytcg_php -f
```

**Database:**
```bash
# Create database and run migrations
make db.create
make db.migration

# Run fixtures (requires hautelook/alice-bundle setup)
make db.fixtures.load

# Create new migration
make db.diff
```

**Code Quality:**
```bash
# PHP CS Fixer (uses vendor/barlito/utils/config/.php-cs-fixer.dist.php)
make cs-fix

# PHP CodeSniffer (uses vendor/barlito/utils/config/phpcs.xml.dist)
make cs-check

# Run all quality checks (composer validate, phpcs, cs-fixer, phpstan, rector)
# Note: phpmd is disabled everywhere until pdepend supports PHP 8.4 syntax
make quality
```

**Testing:**
```bash
# Run full test suite
make test

# Run specific test file
vendor/bin/phpunit tests/Path/To/SpecificTest.php

# Run tests with coverage
vendor/bin/phpunit --coverage-html coverage
```

**JWT Setup:**
```bash
# Generate JWT keypair (required for authentication)
castor generate-jwt-key-pair
```

**Symfony Commands:**
```bash
# Run any Symfony command in container
docker exec $(docker ps --filter name="ytcg_php" -q) bin/console <command>

# Clear cache
docker exec $(docker ps --filter name="ytcg_php" -q) bin/console cache:clear

# Create new entity
docker exec $(docker ps --filter name="ytcg_php" -q) bin/console make:entity

# Create new controller
docker exec $(docker ps --filter name="ytcg_php" -q) bin/console make:controller
```

## Architecture

### Domain Model

**Core Entities:**
- **Card**: Trading cards with multi-image support (main image, mask, foil)
  - Fields: name, description, status (DRAFT/PUBLISHED), rarity (CardRarityEnum: common/uncommon/rare/legendary — 4 tiers since the epic removal, grey/green/blue/orange glows), uniqueFlag, alwaysHolo (forces holo whatever the slot's holoChance), visualConfigOverride (JSON, per-card override of the extension's visualConfig)
  - No "type" field: visual customization is the extension→card VisualConfig cascade (see below)
  - Uses VichUploaderBundle for file uploads
  - ManyToOne with Extension

- **Extension**: Card sets/expansions
  - Fields: name, description, status, imageName, visualConfig (JSON, set-level card visual defaults — including the shared foilTexture library pick; NO extension-level foil/mask uploads: masks must match each card's artwork, so they are per-card only), upcoming (« next universe » teaser: at most ONE extension flagged — saving a flagged one from the admin clears the others; shows as a blurred tile on the homepage and /univers while the extension is still DRAFT — published ones already have their own tile)
  - OneToMany with Card, Booster and ExtensionBanner (universe page hero banners, position-ordered carousel)

- **Booster**: Booster packs containing cards
  - Fields: name (optional display name, falls back to the extension name via getDisplayName()), claimable (default true; false = event/code distribution only — not claimable on the hub, still openable by owners, guarded server-side in BoosterClaimService), rarityRates (JSON, one `{rarities: {rarity: weight}, holoChance: int}` entry per card slot — the slot count IS the card count, holoChance is the 0-100 % holo probability of that slot; there is NO global holoRate anymore), imageName
  - getDropRates() projects rarityRates into player-facing percentages (hub « Taux » panel)
  - ManyToOne with Extension; boosters are free (no currency in v2)

**Visual config cascade:** `CardVisualResolver` (`src/Service/Card/`) resolves `Card.visualConfigOverride` → `Extension.visualConfig` → rarity defaults into a `ResolvedCardVisual` DTO, consumed by templates through the `card_visual(card)` Twig function. Edit visuals through this cascade, never per-template.

**User System:**
- **DiscordUser**: Main user entity (implements UserInterface)
  - Primary key: `discordId` (string from Discord)
  - Discord OAuth2 integration for authentication
  - OneToMany with UserCard and UserBooster

- **UserCard**: User card inventory (composite key: DiscordUser + Card, quantity + holoQuantity)
- **UserBooster**: User unopened booster inventory (composite key: DiscordUser + Booster)

**Audit Entities (booster opening):**
- **BoosterClaim**: one row per daily free claim; the daily quota (2/day, reset midnight Europe/Paris) is a COUNT since midnight — no mutable counter anywhere
- **BoosterOpening** + **BoosterOpeningCard**: opening history with the RNG seed (reproducible draws); duplicates aggregated per card (composite PK)

**Redeem Codes (`BoosterCode` + `BoosterCodeRedemption`):**
- **BoosterCode**: code (canonical, uppercase, dash-free — `BoosterCodeGenerator` builds 12 chars out of an I/O/0/1-free alphabet with `random_int`), booster, quantity, maxUses (null = unlimited), uses, expiresAt (stored UTC), disabled, batchLabel. A "unique" code is just `maxUses = 1`; batches are a shared free-form label, not an entity
- **BoosterCodeRedemption**: audit row, unique `(code, user)` → one redemption per player whatever maxUses says
- **BoosterCodeRedeemService::redeem()**: own distribution channel — no `BoosterClaim`, so the daily quota is neither checked nor consumed, and `claimable = false` boosters are reachable. `FOR UPDATE` on the code row, then the `BoosterCodeRedemptionAttempt` DTO is validated **inside the transaction** (the locked row is what the rules read); a violation becomes a `BoosterCodeRefusedException` carrying a `BoosterCodeRefusalEnum`
- **Refusal rules**: `RedeemableBoosterCode` constraint (+ validator), ordered unknown/revoked → expired → already redeemed → exhausted → booster not available yet (unpublished extension or no published card — refused *before* consuming a use). Only the first violation is raised, so the most specific reason wins
- Player entry: `redeemCode` LiveAction on the hub, rate limited by the `booster_code_redeem` limiter — 10/hour/player, and **only unknown codes are charged** (a success or any other refusal proves the player holds a real code). Admin: batch generation + CSV export (`/admin/booster-codes/batch`), read-only CRUDs, batch revoke/restore

**Opening streak (`StreakReward` + `OpeningStreakService`/`StreakRewardService`):**
- Streak = consecutive Europe/Paris days with ≥1 `BoosterOpening` — computed by QUERY (`BoosterOpeningRepository::findDistinctOpeningDays`, native SQL `AT TIME ZONE`, capped at 3650 distinct days: a shorter window would shift the series identity of long streaks and re-grant milestones), consecutive run walked in PHP (`OpeningStreakService` → `OpeningStreak` DTO). A series ending yesterday still counts (today's opening keeps it alive: `isAtRisk()`)
- **Series identity** = Paris day the run started; milestones every 7 days (7/14/21…). `StreakReward` is unique on `(user, series_started_on, milestone)`; granting is `INSERT … ON CONFLICT DO NOTHING` (raw SQL — a caught unique violation would close the EntityManager), triggered post-commit in `BoosterOpeningService::open()` (the streak only changes on an opening; all reached milestones are (re)inserted, so a missed grant self-heals)
- **Spending**: `StreakRewardService::chooseBooster()` — `FOR UPDATE` on the reward row, claim-like guards (claimable + published extension), credits `UserBooster` via `UserInventoryService`. Own distribution channel: no `BoosterClaim`, daily quota untouched
- Hub UI: flame chip in the hero (always visible — running / at-risk / empty states) + "Récompense de streak" banner surfacing the oldest pending reward with one button per claimable booster (`chooseStreakReward` LiveAction)

### Booster Opening Flow (`src/Service/Booster/`)

1. **BoosterClaimService::claim()** — asks the quota policy (`BoosterClaimQuotaInterface` / `DailyBoosterClaimQuota`) for remaining claims, credits `UserBooster` via UserInventoryService, persists a `BoosterClaim`. In dev only, the `UnlimitedBoosterClaimQuota` decorator (`#[When(env: 'dev')]`) lifts the limit unless `BOOSTER_DAILY_LIMIT_ENABLED=true` is set in `.env.dev` — prod code carries no bypass
2. **BoosterOpeningService::open()** — one transaction: pessimistic-locked inventory debit, seeded `CardDrawer::draw()` (per-slot weighted rarity roll, uniform pick in the tier, fallback to the nearest tier with cards, holo roll), duplicate aggregation, `UserCard` credit, audit persistence
3. **RandomService** (`src/Service/Random/`) — seedable `Random\Randomizer` wrapper; the seed is stored on `BoosterOpening`
4. UI: `/boosters` is the opening hub (`BoosterHub` Live Component — claim, open, loot summary). The engine is fully tested at the service level.

**Common Traits:**
- `IdUuidTrait` (from barlito/utils **>= 2.0.1 only**): UUID primary keys. Older versions typed `$id` as `?string` against the UuidType column, making Doctrine flag `id` as changed on every hydrated entity (each flush rewrote every loaded row and trashed updated_at) — fixed upstream in barlito/utils#13.
- `TimestampableEntity` (Gedmo): createdAt/updatedAt timestamps

### Authentication Flow

1. User visits protected route without JWT
2. **JwtNotFound** listener redirects to external Discord OAuth2 flow
3. OAuth2 provider returns JWT token (set as cookie)
4. **JwtInvalid** listener auto-creates DiscordUser if not exists (pulls from JWT payload)
5. **JwtAuthenticated** listener syncs roles from JWT to database on each request
6. JWT token stored in cookie (lifetime: 900s, secure, httpOnly, samesite: lax)

**Important:** User creation happens automatically via JwtInvalid listener. Never manually create DiscordUser entities in code.

### Controllers

**Frontend (`src/Controller/`):**
- **BaseController**: Homepage with 3 random published cards (cached daily)
  - Routes: `/` (homepage), `/boosters` (opening hub), `/extensions` (301 → `/univers`)
  - Uses custom `CardRepository::findRandomCardId()` with RANDOM() DQL function
- **UniverseController**: `/univers` (index of published extensions with per-universe completion) + `/univers/{slug}` (pokédex page: banner carousel, full description, personal completion, set grid with unowned cards masked behind the card back, related boosters using the hub's claimable-or-owned visibility rule, unique 1/1 drop status without revealing the holder)
- **LeaderboardController**: `/classement` (route `leaderboard`) + `/joueur/{discordId}` (route `leaderboard_player` — discordId, not username: usernames are not unique). `LeaderboardService` (`src/Service/Leaderboard/`) ranks every player by global completion from one grouped query per metric (never one query per player), deterministic tiebreaks (total copies → username → discordId). Uniques 1/1 are a COUNT only, never named. Profile masking rule: a card of the profile renders in clear ONLY if the visitor also owns it, otherwise the shared `parts/_masked_card.html.twig` card back (also used by the universe page) with zero name leak in the DOM — a 1/1 of another player is therefore always masked. `UserCard.quantity` already includes holo copies (`holoQuantity` is a subset): total copies = SUM(quantity), never quantity + holoQuantity

**Admin (`src/Controller/Admin/`):**
- **DashboardController**: EasyAdmin dashboard entry
- **CardCrudController**: Card management with custom ImageField (rarity/type choice fields)
- **ExtensionCrudController**: Extension management
- **BoosterCrudController**: Booster management (rarityRates edited as JSON via CodeEditorField, validated by the ValidRarityRates constraint)
- Access: Requires ROLE_ADMIN

### Frontend Architecture

**3D Card Rendering System:**
- **CSS**: `assets/styles/cards/base.css` (advanced 3D CSS)
  - Hardware-accelerated transforms with perspective
  - Multi-layer effects: shine, glare, foil masks
  - Type-specific glows (water, fire, grass, etc.)
- **JavaScript (Stimulus controllers in `assets/controllers/`):**
  - `card_controller.js`: real-time mouse-tracking 3D rotation (anime.js); attached via `data-controller="card"`, so dynamically rendered cards (Live Components) work too

**Asset Pipeline:**
- Uses Symfony AssetMapper (no Webpack/build step)
- Importmap manages dependencies
- Tailwind CSS for styling (carousels are the in-house `carousel` Stimulus controller)

### File Uploads

**Uploaded content vs static assets — the split matters in prod:**
- `public/uploads/` = Vich-uploaded content ONLY. It is the ONLY path mounted as a volume in prod (`ytcg_uploads_data`); anything written elsewhere under `public/` is lost on the next deploy.
- `public/images/` = static, git-tracked, baked into the Docker image (holo/poke textures used by `holo-presets.css` and `FoilTextureEnum`, `card-back.svg`, `default_card.png`, `default_extension.png`). NEVER mount a volume over it: the volume would shadow the image content forever (a stale volume is exactly what made the cosmos textures 404 in prod).

**VichUploaderBundle Mappings (all under `/public/uploads/`, served at `/uploads/{mapping}/{filename}`):**
- `cards`: Main card artwork
- `masks`: Foil/holo masks
- `foils`: Foil textures (per-card/extension uploads — the shared library uses the static `FoilTextureEnum` textures instead)
- `boosters`: Booster images
- `extensions`: Extension images
- `banners`: Universe page hero banners (ExtensionBanner)

### Custom Doctrine Features

**Custom DQL Function:**
- `RANDOM()` function in `src/Doctrine/DBAL/FunctionNode/Random.php`
- Enables database-level randomization in queries
- Used by `CardRepository::findRandomCardId()`

### Configuration Notes

**Environment Variables (required):**
- `APP_SECRET`: kernel secret (signs Live Component checksums — an empty value 500s every page rendering one, hub and admin included)
- `DATABASE_URL`: PostgreSQL connection string
- `JWT_SECRET_KEY`: Path to JWT private key
- `JWT_PUBLIC_KEY`: Path to JWT public key
- `JWT_PASSPHRASE`: JWT key passphrase
- `JWT_COOKIE_DOMAIN`: Domain for JWT cookie
- `OAUTH_DISCORD_CLIENT_ID`: Discord OAuth2 client ID
- `OAUTH_DISCORD_CLIENT_SECRET`: Discord OAuth2 client secret
- `REFRESH_TOKEN_URL`: Discord OAuth2 refresh endpoint
- `LOGOUT_URL`: External logout redirect URL

**Docker Volumes:**
- `ytcg_db_data`: PostgreSQL data persistence
- `ytcg_caddy_data`: Caddy certificates
- `ytcg_caddy_config`: Caddy configuration
- `ytcg_uploads_data`: Vich uploads (`/app/public/uploads`) — the only app-content volume; `public/images` stays image-baked

## Development Guidelines

### Adding New Card Types or Extensions

1. Create entity via `make:entity` or manually extend existing
2. Add status enum if needed (follow CardStatusEnum pattern)
3. Create migration: `make db.diff`
4. Run migration: `make db.migration`
5. Add EasyAdmin CRUD controller in `src/Controller/Admin/`
6. Register in DashboardController menu

### Modifying Card Display

- **CSS changes**: Edit `assets/styles/cards/base.css` (3D core)
- **JavaScript interactions**: Edit `assets/controllers/card_controller.js`
- **Template structure**: Edit `templates/components/CardComponent.html.twig`
- **Card selection logic**: Modify `CardRepository::findRandomCardId()`

### Adding JWT Event Listeners

All JWT listeners must:
1. Use `#[AsEventListener(event: '...')]` attribute
2. Be placed in `src/EventListener/`
3. Follow naming pattern: Jwt{EventName}.php
4. Handle stateless authentication context

### Testing Strategy

- Unit tests in `tests/Unit/` (if applicable)
- Functional tests in `tests/Functional/`
- Test database uses SQLite memory for speed
- PHPUnit configured in `phpunit.xml.dist`

### Code Quality Standards

- PSR-12 coding standard via PHP CS Fixer
- PHPStan level 8, **zero baseline** (never reintroduce one)
- Declare strict types: `declare(strict_types=1);`
- Use typed properties and return types
- Enum over constants for fixed value sets

## Important Notes

- **Never commit** JWT keys or .env files
- **Never modify** roles manually in database; roles are managed by JWT sync
- **Always run** `make quality` before committing
- **Use Castor** for project-specific tasks, **Make** for generic PHP tasks
- **Docker Swarm** is used (not docker-compose), use `docker stack` or Make rules
- **AssetMapper** handles frontend assets; no npm build needed
- **Custom ImageField** required for VichUploader in EasyAdmin; don't use default ImageField
