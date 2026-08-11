# Fixtures dev

Les fixtures de `fixtures/` rejouent un catalogue complet en local :

```bash
docker exec $(docker ps --filter name="ytcg_php" -q) bin/console hautelook:fixtures:load -n
```

⚠️ La commande **purge la base** avant de charger. En dev c'est le but (reset propre) ;
ne la lance jamais contre une base dont tu veux garder le contenu.

## D'où viennent les données

Les cartes sont générées à partir des images réellement présentes dans
`public/uploads/cards`, par `bin/dev/generate-fixtures.php` :

```bash
docker exec $(docker ps --filter name="ytcg_php" -q) php bin/dev/generate-fixtures.php
```

Le script réécrit `Extension.yaml`, `Card.yaml`, `Booster.yaml`, `UserCard.yaml`,
`UserBooster.yaml` et `ExtensionBanner.yaml`. Relance-le après avoir déposé de
nouvelles images, puis recharge les fixtures.

`DiscordUser.yaml` n'est pas généré : il est tenu à la main (vrais discordId).

## Ce que le script déduit du nom de fichier

| déduction | règle |
|---|---|
| univers | premier mot-clé trouvé dans le nom (`_cyberpunk`, `_kda`, `division`, `legion`…), sinon « Origines » |
| nom | nom de fichier sans les marqueurs d'univers, `alt` → « (Alt) », `evo` → « (Évolution) » |
| rareté | `alt`/`evo` → rare, épithète (`_le_`, `_la_`, `_the_`) → uncommon, `empereur`/`god` → legendary, sinon common |
| masque | fichier `<nom-de-carte>-mask*` trouvé dans `public/uploads/masks` |

Les tables de correspondance (univers, mots-clés, noms propres) sont en haut du
script : c'est là qu'on corrige un classement qui tombe à côté.

## Contenu de démo

- 3 cartes 1/1 : une détenue par le Collectionneur, une par Barlito, une encore libre ;
- l'univers le plus petit reste en brouillon avec le drapeau `upcoming` (teaser « prochain univers ») ;
- le dernier booster publié est `claimable: false` (distribution par code / streak) ;
- les collections sont réparties par index de carte, sans aléatoire : le profil du
  Collectionneur montre les états de la grille comparée (en commun, seulement lui,
  seulement toi, manquante aux deux) vus par Barlito.
