# API du jeu de duel (contrat ytcg ↔ ytcg-game)

Youl TCG porte la collection, les tags, les terrains et les decks ; le jeu porte les coûts, puissances, effets et la règle de courbe. ytcg ne vérifie **que la possession** : 12 cartes distinctes, publiées (carte ET univers), possédées (`quantity > 0`, copies holo comprises), aucune n'étant un terrain, plus un terrain possédé optionnel. Le serveur de jeu revalide ses propres règles au moment de rejoindre une partie.

Tout est derrière le feature flag `duel` (admin « Fonctionnalités ») : coupé, **chaque route répond `404 {"error": "Introuvable."}`**.

Réponses toujours en JSON, messages d'erreur en français (affichables tels quels au joueur). Les identifiants de cartes sont les uuid ytcg (ceux de `data/cards` et `data/locations`), en minuscules.

## API joueur (client du jeu)

Authentification : le cookie `jwt` de ytcg (même domaine, envoyé tout seul par `fetch(..., { credentials: 'same-origin' })`).

- Pas de cookie, ou cookie expiré/invalide → `401 {"error": "Session expirée : reconnecte-toi sur Youl TCG.", "loginUrl": "<url de rafraîchissement du jeton>"}` (jamais de redirection : le client renvoie le joueur sur `loginUrl`).
- Les écritures (`POST`, `PUT`) exigent `Content-Type: application/json` (sinon `415`), corps = objet JSON (sinon `400 {"error": "Corps JSON invalide : objet attendu."}`).
- Méthode inconnue → `405 {"error": "Méthode non autorisée."}`.

### `GET /api/duel/collection`

Les cartes publiées que le joueur possède, triées par univers puis nom.

```json
{
  "cards": [
    {
      "id": "01a101ad-6d3d-7282-af6f-0801ae890671",
      "name": "Benj \"Big Boss\"",
      "extension": "kda",
      "rarity": "legendary",
      "unique": false,
      "terrain": false,
      "tags": ["character:benj"],
      "quantity": 3,
      "holoQuantity": 1
    }
  ]
}
```

- `quantity` = toutes les copies, holo comprises ; `holoQuantity` en est un sous-ensemble (cosmétique).
- `tags` ne contient jamais `universe:…` : l'univers est implicite, c'est `extension` (le slug ytcg).
- `terrain: true` = carte lieu, jamais jouable, seulement en champ `terrain` d'un deck.

### `GET /api/duel/decks`

```json
{ "decks": [ <deck>, … ], "maxDecks": 10, "deckSize": 12 }
```

Format d'un `<deck>` (aussi renvoyé par `POST` et `PUT`) :

```json
{
  "id": "01a1…",
  "name": "Linettes",
  "cards": ["<12 uuid triés>"],
  "terrain": "01a101ad-2e2c-7289-800e-82b1aeb87e5a",
  "valid": true,
  "missingCards": [],
  "issues": [],
  "createdAt": "2026-10-08T10:00:00+02:00",
  "updatedAt": "2026-10-08T10:00:00+02:00"
}
```

`valid`, `missingCards` et `issues` sont **recalculés à chaque lecture** contre la collection du moment : une carte vendue, échangée ou recyclée rend le deck incomplet (`valid: false`, son uuid dans `missingCards`, une phrase dans `issues`). Rien n'est bloqué côté économie. Les cartes d'un deck forment un ensemble (pas d'ordre : le jeu mélange).

### `POST /api/duel/decks` → `201 <deck>`

```json
{ "name": "Linettes", "cards": ["<12 uuid>"], "terrain": "<uuid> | null (facultatif)" }
```

### `PUT /api/duel/decks/{id}` → `200 <deck>`

Remplacement complet, même corps que `POST` (`terrain` absent = aucun terrain).

### `DELETE /api/duel/decks/{id}` → `204`

### Erreurs des decks

| Code | Quand | Corps |
|---|---|---|
| `404` | deck inconnu, id mal formé ou deck d'un autre joueur (même réponse : l'existence ne fuit pas) | `{"error": "Deck introuvable."}` |
| `422` | règle de possession violée | `{"error": "Deck invalide.", "violations": {"<champ>": ["message", …]}}` |
| `409` | 10 decks déjà enregistrés (création) | `{"error": "Tu as déjà 10 decks : supprimes-en un avant d'en créer un nouveau."}` |

Clés de `violations` : `name` (vide, plus de 40 caractères), `cards` (pas une liste, pas exactement 12 entrées), `cards[i]` (index dans la liste envoyée : identifiant invalide, doublon, « Carte introuvable dans ta collection. », carte qui est un terrain), `terrain` (identifiant invalide, « Terrain introuvable dans ta collection. », carte qui n'est pas un terrain). Une carte inconnue, non publiée ou non possédée donne le même message.

## API serveur (serveur de jeu Colyseus)

Authentification : `Authorization: Bearer <DUEL_SERVER_TOKEN>` (secret partagé, variable d'environnement de ytcg ; vide = tout refusé). Sans jeton ou jeton faux → `401 {"error": "Jeton serveur manquant ou invalide."}`. Le cookie joueur n'ouvre pas cette API.

### `GET /api/duel/server/decks/{id}?player=<discordId>`

Le deck tel qu'il doit être joué, vérifié contre la collection du joueur **à cet instant** (à appeler au moment où le joueur rejoint la partie, avec le `discordId` lu dans son JWT).

| Code | Quand | Corps |
|---|---|---|
| `200` | deck du joueur, entièrement possédé | `{"id": "…", "name": "…", "cards": ["<12 uuid>"], "terrain": "<uuid>" \| null}` |
| `409` | deck incomplet | `{"error": "Deck incomplet : il ne correspond plus à la collection du joueur.", "missingCards": ["<uuid>"], "issues": ["…"]}` |
| `404` | deck inconnu, joueur inconnu ou deck d'un autre joueur | `{"error": "Deck introuvable pour ce joueur."}` |
| `400` | `player` absent | `{"error": "Paramètre player (discordId) requis."}` |
