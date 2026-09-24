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
- **Admin Panel:** EasyAdminBundle 5.x (pretty URLs: `/admin/card/{id}/edit`)
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
# Create the database if needed, then run migrations (dev)
make doctrine.migrate

# Same against the test database — REQUIRED after every new migration, or the functional suite breaks
make doctrine.migrate.ci

# Create new migration (drop lines for tables of unmerged branches still present in the dev DB)
make doctrine.diff

# Load fixtures — PURGES the database first, no confirmation: count what is there before running it
make doctrine.load_fixtures      # dev  (fixtures/shared + fixtures/dev)
make doctrine.load_fixtures.ci   # test (fixtures/shared + fixtures/test)

# DESTRUCTIVE: drops and recreates the dev database
make doctrine.reset_db
```

**Code Quality:**
```bash
# Fix: php-cs-fixer + rector
make fix_style

# Check: composer validate + phpcs + cs-fixer dry-run + phpstan + rector dry-run
# (`make quality` is an alias). phpmd is disabled until pdepend supports PHP 8.4 syntax
make check_style
```

**Testing:**
```bash
# Tailwind must be built before functional tests (base.html.twig needs var/tailwind/tailwind.built.css)
make tailwind.build

# Run full test suite (inside the php container)
make phpunit

# Run specific test file
docker exec $(docker ps --filter name="ytcg_php" -q) bin/phpunit tests/Path/To/SpecificTest.php
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
  - Fields: name, description, status (DRAFT/PUBLISHED), rarity (CardRarityEnum: common/uncommon/rare/legendary — 4 tiers since the epic removal, grey/green/blue/orange glows), uniqueFlag, claimedBy, alwaysHolo (forces holo whatever the slot's holoChance), visualConfigOverride (JSON, per-card override of the extension's visualConfig)
  - **Uniques 1/1**: `uniqueFlag` + `claimedBy` (ManyToOne DiscordUser, ON DELETE SET NULL). The single holder is enforced by the atomic conditional UPDATE `CardRepository::claimUnique()` (`claimed_by IS NULL`), not by a DB constraint; `findDrawablePool` excludes claimed uniques
  - An owned card (a holder with quantity > 0, or a drawn 1/1 `claimedBy`) can never go back PUBLISHED → DRAFT: `NotDepublishedWhileOwned` constraint + `CardDepublicationGuard` (edit form locks the status field, the batch draft action refuses owned cards one by one)
  - No "type" field: visual customization is the extension→card VisualConfig cascade (see below)
  - Uses VichUploaderBundle for file uploads
  - ManyToOne with Extension

- **Extension**: Card sets/expansions
  - Fields: name, slug (Gedmo, updatable: renaming an extension changes its public URLs), description, status, imageName, logoName (`extension_logos` mapping, shown in the CSS card frame; falls back to the name as text), visualConfig (JSON, set-level card visual defaults — including the shared foilTexture library pick; NO extension-level foil/mask uploads: masks must match each card's artwork, so they are per-card only), upcoming (« next universe » teaser: at most ONE extension flagged — saving a flagged one from the admin clears the others; shows as a blurred tile on the homepage and /univers while the extension is still DRAFT — published ones already have their own tile)
  - A universe whose cards players own (quantity > 0 or a drawn 1/1) can never go back PUBLISHED → DRAFT: same `NotDepublishedWhileOwned` constraint, via `ExtensionDepublicationGuard` (edit form locks the status field)
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

- **UserCard**: User card inventory (composite key: DiscordUser + Card). `quantity` = ALL copies, holo included; `holoQuantity` is a SUBSET of it. Total copies = `quantity`, never `quantity + holoQuantity` (same rule on `BoosterOpeningCard`) — this mistake already caused 3 bugs
- **UserBooster**: User unopened booster inventory (composite key: DiscordUser + Booster)

**Audit Entities (booster opening):**
- **BoosterClaim**: one row per daily free claim; the daily quota (2/day, reset midnight Europe/Paris) is a COUNT since midnight — no mutable counter anywhere
- **BoosterOpening** + **BoosterOpeningCard**: opening history with the RNG seed; duplicates aggregated per card (composite PK). Replaying a seed only reproduces the draw if the drawable pool is unchanged (cards published/unpublished, uniques claimed since) — there is no pool snapshot

**Redeem Codes (`BoosterCode` + `BoosterCodeRedemption`):**
- **BoosterCode**: code (canonical, uppercase, dash-free — `BoosterCodeGenerator` builds 12 chars out of an I/O/0/1-free alphabet with `random_int`), booster, quantity, maxUses (null = unlimited), uses, expiresAt (stored UTC), disabled, batchLabel. A "unique" code is just `maxUses = 1`; batches are a shared free-form label, not an entity
- **BoosterCodeRedemption**: audit row, unique `(code, user)` → one redemption per player whatever maxUses says
- **BoosterCodeRedeemService::redeem()**: own distribution channel — no `BoosterClaim`, so the daily quota is neither checked nor consumed, and `claimable = false` boosters are reachable. `FOR UPDATE` on the code row, then the `BoosterCodeRedemptionAttempt` DTO is validated **inside the transaction** (the locked row is what the rules read); a violation becomes a `BoosterCodeRefusedException` carrying a `BoosterCodeRefusalEnum`
- **Refusal rules**: `RedeemableBoosterCode` constraint (+ validator), ordered unknown/revoked → expired → already redeemed → exhausted → booster not available yet (unpublished extension or no published card — refused *before* consuming a use). Only the first violation is raised, so the most specific reason wins
- Player entry: `redeemCode` LiveAction on the hub, rate limited by the `booster_code_redeem` limiter — 10/hour/player, and **only unknown codes are charged** (a success or any other refusal proves the player holds a real code). Admin: batch generation + CSV export (`/admin/booster-codes/batch`), read-only CRUDs, batch revoke/restore

**P2P trades (`TradeOffer` + `TradeOfferLine`, `src/Service/Trade/`):**
- An offer = copies of the proposer (`OFFERED`) against copies of the receiver (`REQUESTED`); statuses pending/accepted/refused/cancelled/invalidated. Published catalogue only (card + extension published): refused at creation, offer invalidated at acceptance if a card got unpublished
- **Reservation**: copies engaged by their proposer in a PENDING offer cannot be engaged elsewhere (ledger recomputed from the lines by `TradeOfferRepository::sumReservedQuantities()`, no mutable counter). The requested side is NEVER reserved (the receiver consented to nothing); it is re-checked at acceptance (the receiver's own outgoing offers do reserve). Recycling is stricter: a card offered by the player or requested from them in a PENDING offer is not recyclable at all (`TradeOfferRepository::findEngagedCardIds()`, re-read by `RecycleService` under lock; shown locked on /recyclage)
- **Locking**: `UserCardRepository::lockForDebit()` (create) / `lockForCredit()` (accept, missing taker rows created at 0) — the shared FOR UPDATE of openings and recycling; players in discordId order, then card ids. Accept credits the locked rows directly (a second, refreshing lock pass would clobber the pending debits), then moves 1/1 `claimedBy` through a conditional UPDATE. `TradeOfferLine` stores quantities **per finish** (normal/holo), unlike `UserCard` where `holoQuantity` is a subset of `quantity`
- Proposer lost the copies → offer `INVALIDATED` (status committed even though the acceptance fails); receiver cannot cover → the offer stays PENDING
- UI: `/echanges` (`TradeInbox`: received with two-step accept, sent, history), `/echanges/nouveau[/{discordId}]` (`TradeComposer`: universe strip filter `?univers=`, name search, two columns on desktop / tabs on mobile, sticky recap bar), pending-offer badge in the header
- **Masking rule (whole trade screen)**: a card the reader does not own is rendered as a card back (`parts/_masked_card.html.twig` with `rarity`, included **without context**) labelled « Carte inconnue », rarity kept (an offer is judged on it). Decided server-side: masked entries carry `card: null` (`TradeComposer` entries, `TradeLineView`). The composer addresses cards by an opaque per-viewer HMAC token (`kernel.secret`), never the uuid — selection props and action params included; masked cards sort by token, not name, and ignore the name search (no oracle)

**Opening streak (`StreakReward` + `OpeningStreakService`/`StreakRewardService`):**
- Streak = consecutive Europe/Paris days with ≥1 `BoosterOpening` — computed by QUERY (`BoosterOpeningRepository::findDistinctOpeningDays`, native SQL `AT TIME ZONE`, capped at 3650 distinct days: a shorter window would shift the series identity of long streaks and re-grant milestones), consecutive run walked in PHP (`OpeningStreakService` → `OpeningStreak` DTO). A series ending yesterday still counts (today's opening keeps it alive: `isAtRisk()`)
- **Series identity** = Paris day the run started; milestones every 7 days (7/14/21…). `StreakReward` is unique on `(user, series_started_on, milestone)`; granting is `INSERT … ON CONFLICT DO NOTHING` (raw SQL — a caught unique violation would close the EntityManager), triggered post-commit in `BoosterOpeningService::open()` (the streak only changes on an opening; all reached milestones are (re)inserted, so a missed grant self-heals)
- **Spending**: `StreakRewardService::chooseBooster()` — `FOR UPDATE` on the reward row, claim-like guards (claimable + published extension), credits `UserBooster` via `UserInventoryService`. Own distribution channel: no `BoosterClaim`, daily quota untouched
- Hub UI: flame chip in the hero (always visible — running / at-risk / empty states) + "Récompense de streak" banner surfacing the oldest pending reward with one button per claimable booster (`chooseStreakReward` LiveAction)

**Feature flags (`FeatureFlag` + `FeatureFlags`):**
- `FeatureEnum` (`trades`, `recycling`); one `FeatureFlag` row per case (PK `name`), created OFF by the migration — a missing row is OFF. Test and dev fixtures switch both ON; "OFF" tests flip the flag with `tests/FeatureFlagTrait.php`
- `App\Service\Feature\FeatureFlags::isEnabled()/assertEnabled()`: one query per request (memoized, `ResetInterface`)
- `#[RequiresFeature(FeatureEnum::X)]` (class or method, repeatable) → `RequiresFeatureListener` on `kernel.controller_arguments` answers 404, sub-requests included. Put it on the page controller AND the Live Component class: `/_components/...` actions and re-renders bypass the page controller
- Services guard their own entry points too (`RecycleService::recycle()` → `RecyclingClosedException`); templates use `feature_enabled('recycling')` (unknown name = error). Audit data and admin deletion guards are never flag-dependent
- Admin: « Fonctionnalités » (`FeatureFlagCrudController`, edit only)

### Booster Opening Flow (`src/Service/Booster/`)

1. **BoosterClaimService::claim()** — asks the quota policy (`BoosterClaimQuotaInterface` / `DailyBoosterClaimQuota`) for remaining claims, credits `UserBooster` via UserInventoryService, persists a `BoosterClaim`. In dev only, the `UnlimitedBoosterClaimQuota` decorator (`#[When(env: 'dev')]`) lifts the limit unless `BOOSTER_DAILY_LIMIT_ENABLED=true` is set in `.env.dev` — prod code carries no bypass
2. **BoosterOpeningService::open()** — one transaction: pessimistic-locked inventory debit, seeded `CardDrawer::draw()` (per-slot weighted rarity roll, uniform pick in the tier, fallback to the nearest tier with cards, holo roll), duplicate aggregation, `UserCard` credit (`UserInventoryService::addCards()`), audit persistence
3. **RandomService** (`src/Service/Random/`) — seedable `Random\Randomizer` wrapper; the seed is stored on `BoosterOpening`
4. **BoosterAvailabilityService** — single source of truth for "can this booster be claimed / opened / drawn" (claimable, published extension, drawable pool). Every distribution channel must go through it rather than re-implementing the guards
5. UI: `/boosters` is the hub (`BoosterHub` Live Component — claim, redeem code, streak reward); opening happens on the dedicated page `/boosters/{id}/open` (`BoosterOpening` Live Component + three.js pack `pack_opening_3d_controller.js`, 2D fallback when WebGL/GLTF is unavailable). An owner can open a booster even if its extension was unpublished since (accepted product rule)

**Concurrency rules:** every write on inventory rows (`user_booster`, `user_card`) happens under a pessimistic lock (`FOR UPDATE`) inside the transaction, locks taken in a deterministic order (by card id) to avoid deadlocks. A plain find-modify-write on `user_card` is a lost update: two concurrent transactions read N, both write N+1. Refusals thrown inside `wrapInTransaction` close the EntityManager — never write after a caught refusal in the same request.

**Common Traits:**
- `IdUuidTrait` (from barlito/utils **>= 2.0.1 only**): UUID primary keys. Older versions typed `$id` as `?string` against the UuidType column, making Doctrine flag `id` as changed on every hydrated entity (each flush rewrote every loaded row and trashed updated_at) — fixed upstream in barlito/utils#13.
- `TimestampableEntity` (Gedmo): createdAt/updatedAt timestamps
- `HasVisualConfigTrait` (`src/Entity/Traits/`): shared VisualConfig accessors of Card and Extension

**Time zones:** everything is stored in UTC; Europe/Paris is applied only at business boundaries (daily quota reset, streak days, admin display). Doctrine binds datetimes WITHOUT converting their time zone → convert to UTC at the repository boundary.

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
- **BaseController**: Homepage with 3 random published cards (cached daily, key `daycards`), ticker (packs opened, cards pulled), upcoming-universe teaser
  - Routes: `/` (homepage), `/boosters` (hub), `/extensions` (301 → `/univers`)
  - Uses custom `CardRepository::findRandomCardId()` with RANDOM() DQL function
- **BoosterController**: `/boosters/{id}/open` (route `booster_open`) — dedicated opening page, redirects to the hub when the player owns none
- **CollectionController**: `/collection/{slug?}` (route `collection`) — the player's own profile + collection: completion strip (`CompletionStripBuilder`, `parts/_completion_strip.html.twig`), full published catalogue with missing cards face down, filters all/owned/missing. Legacy `?extension=<uuid>` → 301 to the slug. `/joueur/{own discordId}` redirects here
- **OpeningHistoryController**: `/mes-ouvertures` (route `opening_history`) — paginated opening history + « Ma chance » luck stats (`OpeningLuckStatsProvider`)
- **LogoutController**: `/logout`
- **UniverseController**: `/univers` (index of published extensions with per-universe completion) + `/univers/{slug}` (pokédex page: banner carousel, full description, personal completion, set grid with unowned cards masked behind the card back, related boosters using the hub's claimable-or-owned visibility rule, unique 1/1 drop status without revealing the holder)
- **LeaderboardController**: `/classement` (route `leaderboard`) + `/joueur/{discordId}/{slug?}` (route `leaderboard_player` — discordId, not username: usernames are not unique; your own id redirects to `/collection`). `LeaderboardService` (`src/Service/Leaderboard/`) ranks every player by global completion from one grouped query per metric (never one query per player), deterministic tiebreaks (total copies → username → discordId). Every public counter is computed on the PUBLISHED catalogue (card AND extension published), otherwise it diverges from its denominator
- **Profile comparison** (`ProfileComparisonService`, `ProfileCardStateEnum`): the visited profile is crossed with the visitor's collection over the whole published catalogue — 4 states `common` / `profile-only` / `visitor-only` / `missing-both`. A card renders in clear ONLY if the visitor owns it too, otherwise the shared `parts/_masked_card.html.twig` card back (also used by the universe page and the collection) with zero name/artwork leak in the DOM. Exception by product decision: a 1/1 reveals WHO holds it (chip), never its name or artwork

**Admin (`src/Controller/Admin/`):**
- **DashboardController**: EasyAdmin dashboard entry
- **CardCrudController**: Card management with custom ImageField, live preview (`CardPreviewController`, `/admin/card-preview/{id}`), batch publish/draft actions
- **ExtensionCrudController** / **ExtensionBannerCrudController**: Extension (upcoming flag uniqueness handled in persist/update) and universe banners
- **BoosterCrudController**: Booster management — rarityRates edited as a form collection (`BoosterSlotType` + `RarityWeightsType`), validated by the ValidRarityRates constraint
- **AbstractGuardedCrudController**: base for CRUDs whose deletion must be refused with an explicit message when rows still reference the entity (count references BEFORE trying: a failed flush closes the EntityManager). **AbstractReadOnlyCrudController**: base of the read-only economy screens (DiscordUser, BoosterClaim, BoosterOpening, BoosterCode, BoosterCodeRedemption)
- Custom admin pages: `/admin/cards/batch` (bulk card import, PNG/JPEG/WebP), `/admin/booster-codes/batch` (+ `/export` CSV), `/admin/guide` (French admin handbook). Custom routes under `/admin` go through the EA dashboard, which injects its routeParams as request ATTRIBUTES (read them as controller arguments, not `$request->query`); any `linkToCrudAction` action needs `#[AdminRoute]`
- An `AssociationField` pointing at a composite-key entity (UserCard, BoosterOpeningCard) 500s — use a virtual field + `setTemplatePath()`. EA filters target the Doctrine property (`uniqueFlag`), not the virtual field (`unique`)
- Access: Requires ROLE_ADMIN

### Frontend Architecture

**3D Card Rendering System:**
- **CSS**: `assets/styles/cards/` — `base.css` (3D core: perspective, shine/glare/foil layers), `holo.css` + `holo-presets.css` (holo recipes per rarity/preset, layers off outside `.interacting` for grid perf), `frame.css` (CSS card frame: name, extension logo, YOUL watermark, rarity icon — sized in `cqi` container units)
  - Rarity glows via `--card-glow` set on `.card[data-rarity=…]`: an override must target `.card` itself, not an ancestor
  - Selectors on dynamic classes / `[data-rarity]` live in plain CSS, never in a Tailwind `@layer` (purged)
- **JavaScript (Stimulus controllers in `assets/controllers/`):**
  - `card_controller.js`: lifecycle glue around `assets/lib/card_tilt.js` (pointer tracking with in-house springs, one style write per frame — no anime.js); attached via `data-controller="card"`, so dynamically rendered cards (Live Components) work too
  - `booster_opening_controller.js` (reveal sequence, rarest-last climax) + `pack_opening_3d_controller.js` (three.js GLTF pack, `public/models/pack-wide.gltf`)
- **Design system « Violet Arcade »**: tokens in `assets/styles/theme.css` (+ Tailwind palette), helpers `.btn-arcade`, `.eyebrow`, `.chip-mono`; rarity colors `--rarity-*`

**Asset Pipeline:**
- Uses Symfony AssetMapper (no Webpack/build step)
- Importmap manages dependencies
- Tailwind CSS for styling (carousels are the in-house `carousel` Stimulus controller). The CSS is COMPILED (symfonycasts/tailwind-bundle, binary pinned to v3.4.17): run `make tailwind.build` after any template/style change introducing utility classes, or the new classes simply don't exist
- Switching branches empties `assets/vendor/` (gitignored) → `bin/console importmap:install`

### File Uploads

**Uploaded content vs static assets — the split matters in prod:**
- `public/uploads/` = Vich-uploaded content ONLY. It is the ONLY path mounted as a volume in prod (`ytcg_uploads_data`); anything written elsewhere under `public/` is lost on the next deploy.
- `public/images/` = static, git-tracked, baked into the Docker image (holo/poke textures used by `holo-presets.css` and `FoilTextureEnum`, `card-back.svg`, `default_card.png`, `default_extension.png`). NEVER mount a volume over it: the volume would shadow the image content forever (a stale volume is exactly what made the cosmos textures 404 in prod).

**VichUploaderBundle Mappings (all under `/public/uploads/`, served at `/uploads/{mapping}/{filename}`):**
- `cards`: Main card artwork
- `masks`: Foil/holo masks
- `foils`: Foil textures (per-card uploads only — the shared library uses the static `FoilTextureEnum` textures instead)
- `boosters`: Booster images
- `extensions`: Extension images
- `extension_logos`: Extension logos (card frame)
- `banners`: Universe page hero banners (ExtensionBanner)

Only `/admin/cards/batch` validates the uploaded file type today; the CRUD upload fields have no `Assert\Image` yet (see security backlog).

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
- `OAUTH_DISCORD_CLIENT_ID` / `OAUTH_DISCORD_CLIENT_SECRET`: only required by the (unused) `knpu_oauth2_client` config — OAuth is handled by the external IdP
- `REFRESH_TOKEN_URL`: Discord OAuth2 refresh endpoint
- `LOGOUT_URL`: External logout redirect URL
- `APP_VERSION`: displayed version (set at image build)

**Docker Volumes:**
- `ytcg_db_data`: PostgreSQL data persistence
- `ytcg_caddy_data`: Caddy certificates
- `ytcg_caddy_config`: Caddy configuration
- `ytcg_uploads_data`: Vich uploads (`/app/public/uploads`) — the only app-content volume; `public/images` stays image-baked

## Development Guidelines

### Adding New Card Types or Extensions

1. Create entity via `make:entity` or manually extend existing
2. Add status enum if needed (follow CardStatusEnum pattern)
3. Create migration: `make doctrine.diff`
4. Run migration: `make doctrine.migrate` then `make doctrine.migrate.ci` (test DB)
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

- Unit tests in `tests/Unit/`, service/repository tests against the real DB in `tests/Integration/` (KernelTestCase), HTTP + Live Component tests in `tests/Functional/`
- The test database is **PostgreSQL** (`dbname_suffix: _test`), NOT SQLite: migrate it (`make doctrine.migrate.ci`) after every migration and reload its fixtures (`make doctrine.load_fixtures.ci`) before concluding a branch is green — CI always does both
- Some functional tests depend on the CONTENT of `fixtures/test` (e.g. Bleach must stay a universe without published cards): editing it is a contract change
- Tests only run inside the `ytcg_php` container mounted on the main checkout → no parallel work in git worktrees
- PHPUnit 12 (`#[DataProvider]` attributes), configured in `phpunit.xml.dist`
- A green local run proves nothing when `composer.lock` changed: the container's `vendor/` is not reinstalled — CI installs from scratch

### Code Quality Standards

- PSR-12 coding standard via PHP CS Fixer
- PHPStan level 8, **zero baseline** (never reintroduce one)
- Declare strict types: `declare(strict_types=1);`
- Use typed properties and return types
- Enum over constants for fixed value sets
- Code comments in English (user-facing strings in French); no multi-line explanatory comment blocks — short one-liners stating a non-obvious constraint only
- Dev-only behaviour = a `#[When(env: 'dev')]` decorator + a var in `.env.dev`, never a flag read by prod code. `#[When]` does not exclude routes: dev routes are declared under `when@dev` in `config/routes.yaml`

## Important Notes

- **Never commit** JWT keys or .env files
- **Never modify** roles manually in database; roles are managed by JWT sync
- **Always run** `make quality` before committing. The pre-commit hook (`.claude/settings.json`) only fires when the Bash command STARTS with `git commit` — never chain `git add && git commit`
- `config/reference.php` is regenerated by Symfony on composer runs: commit it only alongside a `composer.lock` change
- Dependabot bumps can drift `symfony/*` to 8.x (it ignores `extra.symfony.require: 7.4.*`); fix with `composer update --with-all-dependencies` inside the container (Flex active)
- Dev tooling: `app:dev:forge-jwt [discordId]` (dev only) forges an auth cookie for browser checks; `/dev/card-effects` and `/dev/card-frames` are visual playgrounds
- **Use Castor** for project-specific tasks, **Make** for generic PHP tasks
- **Docker Swarm** is used (not docker-compose), use `docker stack` or Make rules
- **AssetMapper** handles frontend assets; no npm build needed
- **Custom ImageField** required for VichUploader in EasyAdmin; don't use default ImageField
