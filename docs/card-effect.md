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
| `assets/styles/cards/holo-presets.css` | Named holo presets (`holo--shine/basic/reverse/cosmos/rainbow/secret`) overriding the rarity recipe. | same vars + tokens as `holo.css` |

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
- Add a `holo--<preset>` class (see below) to swap the rarity recipe for a named
  preset; it still reads the same `--holo-*` knobs and pointer vars.

## Holo presets (`holo-presets.css`)

A preset is a single class on `.card` that **overrides** the shine/glare
backgrounds of the rarity recipe. They follow the same anti-cramage rules
(brightness < 1, no per-rarity brightness escalation) and only differ by gradient
shape, glitter density and saturation:

| Class | `holoEffect` value | Look |
|-------|--------------------|------|
| `holo--shine` | `shine` | sober diagonal sheen (overlay, safest) |
| `holo--basic` | `basic` | linear sunpillar holo |
| `holo--reverse` | `reverse` | artwork-dominant subtle grain + faint sweep |
| `holo--cosmos` | `cosmos` | galaxy / starfield glitter, clipped by `--mask` when present |
| `holo--rainbow` | `rainbow` | single conic rainbow swirl anchored to the pointer |
| `holo--secret` | `secret` | denser double rainbow + tighter glitter |
| `holo--vmax` | `vmax` | angular starburst foil + steep prismatic bands |
| `holo--vstar` | `vstar` | burst foil + sparkle trame, crisper |
| `holo--trainer` | `trainer` | subtle geometric lattice + soft sweep |

```html
<div class="card interactive holo holo--cosmos" data-rarity="rare"> … </div>
```

Live preview + a side-by-side gallery of every preset: `/dev/card-effects`
(dev-only playground, `CardEffectsDemoController` + `card_playground_controller.js`).

## Bundled foil / mask textures (`public/images/holo/`)

Original, procedurally-generated SVG textures (feTurbulence + gradients) shipped
with the bundle — **no third-party assets, no licence strings attached**. Drop them
in via `--foil` (the foil layer of `basic`/`cosmos`/rarity recipes) or `--mask`:

| File | Use |
|------|-----|
| `beam.svg` | tight vertical silvery pillar (faint prismatic edges) — default foil of `basic` (rare-holo beam) |
| `burst.svg` | radial silvery starburst — default foil of `vmax` / `vstar` |
| `geometric.svg` | faint diamond lattice — default foil of `trainer` |
| `glitter.svg` | sparse white sparkles — sparkle foil (`secret`, glitter trames) |
| `galaxy.svg` | nebula + starfield — default foil of the `cosmos` preset |
| `metal.svg` | brushed-metal streaks — metallic foil |
| `rainbow.svg` | diagonal spectrum — rainbow foil |
| `holo-lines.svg` | diagonal-band **mask** (classic reverse-holo lines) — example `--mask` |

```html
<!-- via inline vars (or upload the file as the card/extension foil in the BO) -->
<div class="card interactive masked" data-rarity="rare"
     style="--foil:url('/images/holo/metal.svg'); --mask:url('/images/holo/holo-lines.svg')"> … </div>
```

Note on masks: a mask defines *where* the foil shows. `holo-lines.svg` is a generic
reusable example (striped reverse-holo); a production mask is usually authored to
match a specific card's artwork. The per-card foils/masks uploaded in the BO live in
`public/images/foils|masks/` (Vich, git-ignored); these shipped textures are kept
separate in `public/images/holo/` so they're versioned with the bundle.

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

The same three knobs (+ `glow`, `borderColor`, `cssClass`, `holoEffect`) live in
the `VisualConfig` JSON edited in EasyAdmin, resolved **card → extension → rarity
default** by `CardVisualResolver`, and emitted as the inline vars / classes above
by `CardComponent`. Foil/mask **textures** are Vich uploads on the Card/Extension
and become `--foil` / `--mask`. So a non-technical admin tunes the foil layers and
the holo strength without touching CSS.

`holoEffect` is backed by the `CardEffectEnum`. On the **Extension** form it is a
dedicated *Holo preset* dropdown (merged into the visual-config JSON); on the
**Card** form it is set through the `holoEffect` key of the JSON override
(`{"holoEffect": "cosmos"}`). The cascade is the usual card override → extension →
none (pure rarity recipe).
