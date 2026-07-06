# Guide — configurer une carte pour un bel effet holo

Ce guide explique, côté **back-office** (EasyAdmin), comment donner à une carte un
rendu holographique réussi, et **comment fabriquer un foil et un mask** qui rendent
bien. Pour la doc technique du bundle (fichiers à copier, contrat CSS/JS), voir
[`card-effect.md`](./card-effect.md).

---

## 1. Les leviers à ta disposition

Une carte combine quatre choses :

| Levier | Où | Rôle |
|--------|-----|------|
| **Artwork** | upload `image` de la Card | l'illustration plein cadre |
| **Preset holo** (`holoEffect`) | config visuelle Card / Extension | la *recette* d'effet (shine, basic, cosmos, trainer) |
| **Foil** | upload `foil` de la Card / Extension | la texture qui « brille » sous l'effet |
| **Mask** | upload `mask` de la Card / Extension | **où** l'effet apparaît sur la carte |

Plus, optionnel : `glow` (halo coloré), `borderColor` (liseré), `cssClass`.

### La cascade de résolution

Tout est résolu **Carte → Extension → défaut** (`CardVisualResolver`) :

- **foil / mask** : l'upload de la *carte* s'il existe, sinon celui de l'*extension*, sinon le foil par défaut du preset.
- **preset / glow / border** : l'override de la *carte*, sinon la config de l'*extension*, sinon (pour le holo) la recette **par rareté** (`data-rarity`).

> Conséquence pratique : configure le **preset + foil + glow au niveau de
> l'Extension** une fois, et toutes ses cartes en héritent. Tu ne surcharges au
> niveau de la Carte que les exceptions.

---

## 2. Recette express (le minimum pour un bel effet)

1. Upload l'**artwork** de la carte.
2. Choisis un **preset** (`holoEffect`) adapté — voir le tableau du
   [card-effect.md](./card-effect.md#holo-presets-holo-presetscss).
3. C'est tout : **chaque preset embarque déjà un foil par défaut** (galaxy, burst,
   geometric…). L'effet marche sans rien uploader.

Pour aller plus loin et avoir un rendu **unique** à la carte : fournis **ton propre
foil** (et éventuellement un **mask**). C'est l'objet des sections suivantes.

Visualise tout sur `/dev/card-effects` (survole, et clique pour zoomer).

---

## 3. L'artwork

- **Ratio** : `--card-aspect = 0.718` (portrait). Vise **734 × 1024 px** (ou
  750 × 1045), c'est le ratio carte Pokémon. L'image est affichée en `object-fit:
  cover` : tout débord est rogné, donc garde le sujet centré.
- **Plein cadre** : nos cartes n'ont **pas** de cadre type Pokémon (pas de fenêtre
  d'artwork + zone de texte). L'illustration occupe toute la carte → les presets
  appliquent l'effet sur **toute la surface** (c'est pour ça qu'on a retiré les
  `clip-path` de cadre du portage).
- **Contraste / lisibilité** : les recettes holo sont en `color-dodge` (additif).
  Un artwork **déjà très clair / blanc** sature vite (« crame »). Les zones
  sombres/medium rendent le mieux. Un artwork sombre et coloré (néon, espace,
  metal) = terrain idéal.

---

## 4. Le FOIL — la texture qui brille

### C'est quoi

Le foil est une **image de texture** posée comme calque dans le `.card__shine`, et
mélangée en `color-dodge` / `difference` / `hard-light` selon le preset. Règle
mentale du `color-dodge` :

- **noir = invisible** (n'éclaircit rien),
- **clair = brille fort**,
- les couleurs du foil **teintent** la brillance.

Donc un foil = un **motif clair sur fond noir** : ce sont les parties claires qui
deviennent la zone holographique. Pense « négatif » : ce que tu peins en blanc va
scintiller, le reste reste l'artwork.

### Comment l'auteur (specs)

| Critère | Reco |
|--------|------|
| **Dimensions** | même ratio que la carte (≈ 734 × 1024) ; un motif répétable peut être plus petit |
| **Format** | `PNG`, `WEBP` ou `JPG` (le foil n'a pas besoin d'alpha — le fond est **noir**, pas transparent) |
| **Fond** | **noir pur** (`#000`) pour les zones sans brillance |
| **Motif** | clair / coloré là où ça doit briller (rayures, paillettes, faisceaux, étoiles…) |
| **Couleur** | grayscale = brillance neutre prismatique ; coloré = teinte la brillance |

### Bons motifs selon l'intention

- **Rayures fines verticales/diagonales** → holo « lignes » classique.
- **Paillettes éparses** (points blancs sur noir) → glitter (cf. `glitter.png`).
- **Faisceau / burst radial** → effet V / starburst.
- **Nébuleuse + étoiles** → cosmos/galaxy.

> Repars des textures pokeholo dans `public/images/holo/poke/` (glitter,
> trainerbg, cosmos-*…) comme base/référence pour fabriquer les tiennes.

### Où l'uploader

Champ **foil** de la Card (spécifique à cette carte) ou de l'Extension (partagé).
Il écrase le foil par défaut du preset.

---

## 5. Le MASK — où l'effet apparaît

### C'est quoi

Le mask est appliqué en **`mask-image`** sur les calques holo. Il ne « brille »
pas : il **découpe** la zone où l'effet est visible. Règle mentale du masque CSS :

- **opaque (blanc, alpha = 1) = effet VISIBLE**,
- **transparent (alpha = 0) = effet CACHÉ** (l'artwork pur ressort).

Dès qu'un mask est présent, la carte reçoit la classe `masked` et le holo est
clippé à la zone opaque.

### Comment l'auteur (specs)

| Critère | Reco |
|--------|------|
| **Dimensions** | **même cadrage que l'artwork** (≈ 734 × 1024) — le mask se superpose pixel à pixel |
| **Format** | **PNG avec transparence** (l'alpha porte le masque) — ou silhouette blanche sur fond transparent |
| **Peint en blanc/opaque** | les zones qui doivent être holographiques (ex. l'armure, le logo, le ciel…) |
| **Laissé transparent** | les zones qui doivent rester l'artwork brut (ex. le visage) |

### Exemple : holo « ciblé »

Tu veux que seul le **fond néon** d'une carte scintille, pas le personnage :
peins le fond en **blanc opaque**, détoure le personnage en **transparent**, upload
en PNG comme **mask**. Le holo n'apparaîtra que derrière le perso.

---

## 6. Classic vs Reverse, et le toggle sur une même carte

> *« Pour une même carte, puis-je avoir un holo classique sur l'artwork ET un
> reverse au clic d'un bouton ? »*

- **Holo classique** : l'effet est sur l'**artwork** (zone du mask peinte en blanc
  sur le sujet/l'artwork).
- **Reverse holo** : l'effet est **partout SAUF l'artwork** — c'est exactement le
  **masque inversé** (blanc ↔ transparent).

Une carte ne stocke **qu'un seul** mask + un seul foil + un seul preset → par
défaut c'est **l'un ou l'autre**. Mais ce n'est **pas** une limite technique : on
peut câbler un **bouton qui bascule** la classe `holo--*` et/ou la variable
`--mask` côté JS (c'est exactement ce que fait le playground `/dev/card-effects`).

Pour l'implémenter proprement il faudrait :
1. stocker **deux assets** (le mask et son inverse), ou générer l'inverse au build ;
2. un petit contrôleur Stimulus qui, au clic, swappe `--mask` (mask ↔ inverse) et/ou
   la classe de preset.

👉 Ce n'est pas fait aujourd'hui — dis-le si tu veux que ce soit une vraie feature
(« mode holo » togglable). C'est ~une demi-journée.

---

## 7. Étapes concrètes dans l'admin

### Au niveau d'une **Extension** (recommandé — s'applique à toutes ses cartes)
1. `Extensions → éditer`.
2. **Holo preset** : choisis le preset par défaut du set (dropdown).
3. (Option) **glow** / **borderColor** : couleur d'ambiance/liseré.
4. (Option) upload un **foil** / **mask** par défaut du set.

### Au niveau d'une **Card** (exception / one-shot)
1. `Cards → éditer`.
2. Upload **image** (artwork), et si besoin **foil** / **mask** propres.
3. **visualConfigOverride** (JSON) pour surcharger l'extension, ex :
   ```json
   { "holoEffect": "cosmos", "glow": "#7c3aed" }
   ```
   Les clés possibles : `holoEffect`, `glow`, `borderColor`, `cssClass`.

---

## 8. FAQ

**Les cartes rares et légendaires ont-elles un effet par défaut ?**
Non — les recettes « par rareté » ont été supprimées : l'holo passe uniquement
par les **presets** (`holoEffect`). La rareté ne pilote plus que le **glow**
(halo de couleur). Une carte tirée holo sans preset configuré retombe sur le
preset `basic`.

**L'effet s'affiche au repos, c'est moche.**
Par défaut l'effet n'apparaît **qu'au survol** (et au zoom). Seule la carte avec la
classe `holo` garde un voile au repos (utilisé par la révélation de booster).

**Mon foil « crame » tout en blanc.**
Artwork trop clair + `color-dodge`. Assombris le fond du foil (vers le noir),
baisse les zones claires, ou choisis un preset moins agressif (`shine`).

**Rien ne brille avec mon foil.**
Ton foil est probablement trop sombre/uniforme : le `color-dodge` n'éclaircit que
les zones **claires**. Augmente le contraste du motif (blanc franc sur noir).
