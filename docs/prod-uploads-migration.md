# Migration prod : volume `public/images` → `public/uploads`

## Contexte

Jusqu'ici le volume `ytcg_public_data` était monté sur `/app/public/images`
entier. Deux problèmes :

1. **Il masque le statique de l'image Docker.** Les textures holo
   (`/images/holo/poke/*`, dont cosmos), `card-back.svg` et les images par
   défaut sont trackées en git et présentes dans l'image, mais invisibles en
   prod : un volume nommé n'est peuplé depuis l'image qu'à sa création (ici
   mars 2025, avant l'existence de ces fichiers) et n'est jamais re-synchronisé.
2. **Ownership hérité de la v1.** Le volume a été créé à l'époque où le
   conteneur tournait en root ; depuis le passage au user `www` (UID 1000),
   les uploads échouent (`Unable to write in the directory`) tant qu'un
   `chown` manuel n'est pas passé.

Le fix : ne monter en volume **que le contenu uploadé**, déplacé dans
`public/uploads/` (nouveau volume `ytcg_uploads_data`). `public/images/`
redevient 100 % statique et vient de l'image à chaque déploiement.

**Aucune migration DB** : Vich ne stocke que des noms de fichiers, et la
bibliothèque de foils (`FoilTextureEnum`) des noms d'enum — seuls les
`uri_prefix` changent, côté config.

## Runbook (sur le serveur prod)

> Les noms réels des volumes sont préfixés par le nom de la stack.
> Vérifier d'abord :
>
> ```bash
> docker volume ls | grep -E 'public|uploads'
> # attendu avant migration : ytcg_ytcg_public_data (adapter OLD= ci-dessous si différent)
> OLD=ytcg_ytcg_public_data
> NEW=ytcg_ytcg_uploads_data
> ```

### 1. Backup complet de l'ancien volume (rien ne peut être perdu après ça)

```bash
docker run --rm -v $OLD:/src:ro -v /srv/ytcg/backups:/dst alpine \
    tar czf /dst/ytcg_public_data-avant-migration.tar.gz -C /src .
ls -lh /srv/ytcg/backups/ytcg_public_data-avant-migration.tar.gz
```

### 2. Inventaire (optionnel mais recommandé)

```bash
docker run --rm -v $OLD:/src:ro alpine ls -la /src
```

À la racine on doit trouver les six dossiers d'uploads (`cards`, `masks`,
`foils`, `boosters`, `extensions`, `banners`) et probablement des restes de
l'époque où le volume était monté sur `/app/public` entier (v1 : `index.php`,
`assets/`, …) — ces restes ne servent plus, le backup de l'étape 1 les couvre.

### 3. Déployer la nouvelle version (release taguée comme d'habitude)

Le déploiement crée `ytcg_uploads_data`, initialisé depuis l'image avec les
six sous-dossiers **déjà ownés `www`** (c'est le `mkdir` + `chown` du
Dockerfile qui garantit ça — pas de rechute du bug de droits).

⚠️ Entre ce déploiement et l'étape 4, les images uploadées (artworks, masks,
foils, boosters, bannières) sont en 404. Le site fonctionne, les textures
statiques (cosmos & co) réapparaissent immédiatement. Fenêtre = le temps de
lancer l'étape 4.

### 4. Copier les uploads de l'ancien volume vers le nouveau

```bash
docker run --rm -v $OLD:/old:ro -v $NEW:/new alpine sh -c '
    for d in cards masks foils boosters extensions banners; do
        mkdir -p /new/$d
        [ -d /old/$d ] && cp -a /old/$d/. /new/$d/
    done
    chown -R 1000:1000 /new
'
```

Ne PAS copier le reste de la racine de l'ancien volume : `holo/`,
`card-back.svg` et les `default_*.png` sont désormais servis par l'image, et
les restes v1 sont morts.

### 5. Vérifier

- Une page univers : artworks des cartes visibles, dos de carte
  (`/images/card-back.svg`) OK, cartes cosmos/holo scintillent
  (`/images/holo/poke/cosmos-*.png` ne doivent plus être en 404).
- Admin : uploader une image de test sur une carte brouillon → doit passer
  sans erreur de droits, et l'URL générée doit commencer par `/uploads/`.
- `docker service logs ytcg_php` : pas de `FileException`.

### 6. Seulement après validation : supprimer l'ancien volume

```bash
docker volume rm $OLD
```

En cas de pépin, tout est dans
`/srv/ytcg/backups/ytcg_public_data-avant-migration.tar.gz`.

### Variante zéro coupure (optionnelle)

Pour éviter la fenêtre de 404 de l'étape 3→4 : créer et peupler le nouveau
volume AVANT de déployer (mêmes commandes que l'étape 4, précédées de
`docker volume create $NEW`). Le nom doit être exactement celui que la stack
attend (`<stack>_ytcg_uploads_data`) ; comme le volume ne sera pas vide au
premier montage, Docker ne copiera pas les dossiers de l'image — le
`chown -R 1000:1000` de la commande de copie reste donc indispensable.
