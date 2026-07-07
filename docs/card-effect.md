# Card effect — reusable 3D tilt + holo bundle

The interactive card (3D pointer tilt + foil / shine / glare / holo) is built to be
**lifted out of this app and reused anywhere** — no Symfony, no Stimulus and no build
step are required. Grab three files, use the markup, done.

## What to copy

| File | Role | Dependencies |
|------|------|--------------|
| `assets/lib/card_tilt.js` | The driver: pointer tracking, spring-damped motion, writes the CSS variables. | none (vanilla ES module) |
| `assets/styles/cards/base.css` | The 3D structure (perspective, rotator, faces, shine/glare boxes). | none (defines its own tokens) |
| `assets/styles/cards/holo.css` | The foil / shine / glare / glitter recipes, per rarity. | reads the vars above; `--rarity-*` colours have built-in fallbacks |

`base.css` already defines `--card-aspect`, `--card-radius`, `--card-edge`,
`--card-back` and the `--sunpillar-*` rainbow palette, so the two CSS files are
self-contained.

## Markup

```html
<div class="card interactive" data-rarity="epic"
     style="--foil: url('/foils/my-foil.png')">  <!-- optional foil texture -->
  <div class="card__translater">
    <div class="card__rotator">
      <div class="card__front">
        <img src="/cards/my-card.png" alt="">
        <div class="card__shine"></div>
        <div class="card__glare"></div>
      </div>
    </div>
  </div>
</div>
```

- `data-rarity` ∈ `common | uncommon | rare | epic | legendary` selects the recipe.
- Add `holo` to the class list for a card that should keep a soft holo veil **at
  rest** (not only on hover).
- Add `masked` + `--mask: url(...)` to clip the foil to a holo mask (cosmos-style).

## Wiring the JS

Non-framework app:

```js
import { initCardTilts } from './card_tilt.js';
const teardown = initCardTilts();          // wires every .card.interactive
// later, if you remove the cards: teardown();
```

Single element / dynamic card:

```js
import { CardTilt } from './card_tilt.js';
const tilt = new CardTilt(cardEl);
// tilt.destroy() when the element goes away
```

(In this project the Stimulus `card_controller.js` just does `new CardTilt(this.element)`
on connect and `destroy()` on disconnect.)

## The CSS API

`CardTilt` writes these custom properties on the card root every frame; the CSS
reads them. This is the entire contract between JS and CSS:

| Variable | Meaning |
|----------|---------|
| `--pointer-x` / `--pointer-y` | pointer position over the card (`%`) |
| `--pointer-from-center` | `0` at the centre → `1` at the edges |
| `--pointer-from-top` / `--pointer-from-left` | normalised pointer (`0`–`1`) |
| `--background-x` / `--background-y` | damped gradient position (`%`) |
| `--card-opacity` | `0` idle → `1` active — fades the layers in/out |

It also sets `transform: rotateX()/rotateY()` on `.card__rotator` and toggles the
`interacting` class on the root while animating (the CSS hides the heavy layers at
rest — important for a grid of many cards).

## Tuning the look (per card / per set)

Three knobs drive the holo, defaulted per rarity in `holo.css` and **overridable
inline** (inline always wins):

| Variable | Range | Effect |
|----------|-------|--------|
| `--holo-intensity` | 0–1 | overall foil opacity |
| `--holo-saturation` | 0–3 | rainbow vividness (keep ≤ 1 for a subtle, artwork-first look) |
| `--holo-glitter` | 0–2 | sparkle density (epic / legendary) — the recommended way to differentiate rarities |

```html
<div class="card interactive" data-rarity="epic"
     style="--holo-intensity:.5; --holo-saturation:.9; --holo-glitter:.4"> … </div>
```

Design rule baked into the defaults: rarities differ by **glitter density**, never by
saturation/brightness — an epic stays as readable as a rare, just more sparkly. The
`brightness()` on the foil is kept **below 1** on purpose: `color-dodge` is additive
and would otherwise blow bright artwork out to white.

## How this maps to the back office (this app only)

The same three knobs (+ `glow`, `borderColor`, `cssClass`) live in the
`VisualConfig` JSON edited in EasyAdmin, resolved **card → extension → rarity
default** by `CardVisualResolver`, and emitted as the inline vars above by
`CardComponent`. Foil/mask **textures** are Vich uploads on the Card/Extension and
become `--foil` / `--mask`. So a non-technical admin tunes the foil layers and the
holo strength without touching CSS.
