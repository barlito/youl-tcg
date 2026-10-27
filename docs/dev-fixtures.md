# Fixtures dev

| dossier | chargé en | contenu |
|---|---|---|
| `fixtures/shared` | dev + test | les joueurs fictifs (+ date d'inscription), `CoinSettings` |
| `fixtures/dev` | dev | le catalogue de la prod + un historique simulé prêt à tester chaque feature |
| `fixtures/test` | test (et CI) | un jeu minimal et stable, écrit à la main |

`fixtures/test` est un contrat des tests fonctionnels (un univers sans carte publiée,
un booster non réclamable, une 1/1 attribuée…) : toute modification = suite complète.

## Recharger l'environnement local

```bash
make doctrine.load_fixtures   # PURGE la base dev sans confirmation (pg_dump avant si besoin)
castor dev:sync-assets        # images des cartes / univers / packs / bannières → public/uploads/
```

⚠️ `load_fixtures` **purge la base** avant de charger. Sauvegarde d'abord ce que tu veux garder :
`docker exec $(docker ps --filter name=ytcg_db -q) pg_dump -U postgres -d ytcg -Fc > ../backups/<nom>.dump`.

Les dates sont relatives au moment du chargement (`ParisClockProvider`, jours de Paris convertis
en UTC, jamais dans le futur) : la série en cours, les quotas du jour et « aujourd'hui » restent
vrais quel que soit le jour où tu recharges. Recharge simplement si les données ont vieilli.

## Images : `castor dev:sync-assets`

Les fichiers Vich ne sont pas versionnés. La tâche Castor (côté hôte, dev only) copie dans
`public/uploads/<mapping>/` les images référencées par `fixtures/dev-assets.json`
(mapping → nom publié → chemin dans le dépôt d'assets), depuis un clone **en lecture seule** de
[`barlito/youlz-assets`](https://github.com/barlito/youlz-assets) :

- chemin lu dans `YOULZ_ASSETS_DIR` (variable d'environnement, sinon `.env.dev.local`, sinon `.env.dev`
  qui vaut `${HOME}/YoulzAssets`) ;
- idempotente : une copie à jour est sautée, `--force` recopie tout ;
- ~470 fichiers (~700 Mo) : artworks et masks des 212 cartes, couvertures, logos, bannières et packs.

Les univers Cyberpunk, Magic, Psychedelic et L'album des Youlz n'ont ni couverture, ni logo, ni bannière,
ni pack dans YoulzAssets : leur couverture dev est l'artwork d'une de leurs cartes, pas de logo (le cadre
affiche le nom), leurs boosters retombent sur la couverture. Bleach n'a pas d'image de pack non plus.

## D'où viennent les données

Générées par `tools/dev-fixtures/` (python3 sur l'hôte, sortie déterministe) :

```bash
PROD_STATS_SNAPSHOT=/chemin/hors-repo/stats.json python3 -I tools/dev-fixtures/scenario.py fixtures/dev
```

- **Catalogue** (`Extension`, `Card`, `Booster`, `ExtensionBanner`) : manifests `YTCG/*/manifest.json` de
  YoulzAssets (noms, descriptions, fichiers) croisés avec le snapshot `GET /api/admin/stats?period=30` de la
  prod (scope « Stats » ; le fichier contient de vrais joueurs : il reste HORS du dépôt, seul son catalogue
  est lu) : statut, rareté, 1/1, `alwaysHolo`, `visualConfig` / override par carte, boosters à l'identique
  (taux par slot, `holoChance`, `uniqueChance`, réclamable, achetable, prix, nom). Les descriptions des
  univers Cyberpunk, Magic, Psychedelic et L'album des Youlz sont inventées (l'API ne les expose pas), celles
  de leurs cartes sont vides. « Sea of Thieves » (univers refusé de YoulzAssets) sert d'univers à venir
  (DRAFT + `upcoming`).
- **Historique** : une simulation chronologique sur 75 jours (ouvertures tirées selon les taux réels, claims,
  codes, achats, échanges, marché, recyclage, fusion, wishlist, séries) qui vérifie à chaque étape les
  règles de CLAUDE.md (`quantity` inclut `holoQuantity`, copies engagées via le ledger, une seule
  opération de recyclage par jour et sélection minimale, récompense d'univers seulement sur les cartes
  tirées par le joueur…) et produit l'état final cohérent (`Collection.yaml`). Aucun joueur de la prod.
- Les `offerId` / `listingId` des notifications sont des références Alice remplacées par l'uuid au chargement
  (`NotificationPayloadProcessor`, dev only).

## Scénarios prêts à tester (joueur principal Barlito, tous les flags ON)

| scénario | où le voir |
|---|---|
| packs en stock dans plusieurs univers, 2 claims du jour disponibles, achat du jour disponible | `/boosters` |
| code assigné à Barlito (2 packs Psychedelic) + notification ; code multi-usage valide ; code expiré, épuisé, révoqué | cloche, `/boosters?code=…`, `/admin` → Codes |
| série de 16 jours validée aujourd'hui + palier 14 jours à choisir | accueil, `/boosters` |
| Psychedelic 9/10 (manque « Velo », 3 packs Psychedelic en stock → la récompense part au tirage) ; Velo en vente par Juju (l'acheter ne déclenche pas la récompense) | `/univers/psychedelic`, `/marche` |
| Cosmonaut complété et récompensé (500 YLC) | `/univers/cosmonaut`, cloche |
| Sea of Thieves à venir | accueil, `/univers` |
| 1/1 tirées : Veli Kenpachi (Juju), Demon King Julian (Barlito) ; libres : Babou Linette « Queen », Benj le manager | `/univers/bleach`, `/univers/magic`, `/univers/cyberpunk`, `/univers/kda` |
| offre REÇUE de Benj (dont « Benj Cyberpsycho » holo masquée), offre ENVOYÉE à Juju (carte demandée masquée), historique accepté (reveal `newForTaker` via la notif « Farph a accepté »), refusé, annulé | `/echanges` |
| marché : 6 annonces d'autres joueurs (dont une carte manquante, une sur la wishlist, 2 holo), 2 annonces de Barlito (2/3), une vente conclue, un achat réussi, un achat échoué (solde insuffisant), une annonce retirée | `/marche`, `/marche/ma-boutique` |
| wishlist : 2 souhaits directs (dont une carte cédée par échange), Bleach et Psychedelic surveillés, 3 alertes | `/wishlist`, cloche |
| recyclage : plus de 100 doublons, aucun recyclage aujourd'hui ; cartes engagées marquées | `/recyclage` |
| fusion : Monde-Ruche (14 normales) et Niveau 24 (12) fusionnables, Spatio-gare à 10 dont 1 en vente | `/fusion` |
| notifications personnelles de chaque type, lues et non lues, broadcast « 1/1 » lu et annonce du jour non lue | cloche |
| historique d'ouvertures sur 75 jours, « Ma chance », achats de boosters, récompenses, tableau de bord | `/mes-ouvertures`, `/admin` |
