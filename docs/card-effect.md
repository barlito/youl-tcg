# Card effect — reusable 3D tilt + holo bundle

The interactive card (3D pointer tilt + foil / shine / glare / holo) is built to be
**lifted out of this app and reused anywhere** — no Symfony, no Stimulus and no build
step are required. Grab three files, use the markup, done.

## What to copy

| File | Role | Dependencies |
|------|------|--------------|
| `assets/lib/card_tilt.js` | The driver: pointer tracking, spring-damped motion, writes the CSS variables. | none (vanilla ES module) |
| `assets/styles/cards/base.css` | The 3D structure (perspective, rotator, faces, shine/glare boxes). | none (defines its own tokens) |
| `assets/styles/cards/holo.css` | The foil / shine / glare / glitter recipes, per rarity (the fallback when a card has no preset). | reads the vars above; `--rarity-*` colours have built-in fallbacks |
| `assets/styles/cards/holo-presets.css` | Named holo presets — a **faithful port of poke-holo.simey.me** (one recipe per card type), overriding the rarity recipe. | same base.css tokens + the pokeholo raster textures in `public/images/holo/poke/` |

`base.css` already defines `--card-aspect`, `--card-radius`, `--card-edge`,
`--card-back` and the `--sunpillar-*` rainbow palette, so the two CSS files are
self-contained.

## Markup

```html
<div class="card interactive" data-rarity="rare"
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

- `data-rarity` ∈ `common | uncommon | rare | legendary` only drives the
  ambient **glow** colour (halo) — it no longer selects any holo recipe.
- The holo only shows **on hover** (and while zoomed) — never at rest (product
  decision). The `holo` class only marks a holo copy semantically (it drives
  the `holo--basic` fallback), it adds no at-rest veil.
- Add `masked` + `--mask: url(...)` to clip the holo to a mask region.
- Add a `holo--<preset>` class (see below) to light the holo layers: presets
  are the **only** holo rendering path (the per-rarity recipes and their
  `--holo-*` tuning knobs were removed). A card rendered holo without a preset
  falls back to `holo--basic` (CardComponent).
- **Click a card to zoom** it to the centre of the viewport (`card_tilt.js`
  popover); click again, click outside or press `Esc` to close. Disabled inside
  the booster opening (which owns its own click handling).

## Holo presets (`holo-presets.css`)

A preset is a single class on `.card` that **fully replaces** the shine/glare
recipe with a verbatim port of one poke-holo.simey.me card type (see the mapping
in the header of `holo-presets.css`). Each preset ships its own default foil
texture; uploading a foil on the card/extension overrides it (`--foil`).

| Class | `holoEffect` value | pokeholo recipe | Default foil (`/images/holo/poke/`) |
|-------|--------------------|-----------------|-------------------------------------|
| `holo--shine` | `shine` | amazing-rare | — (glitter only) |
| `holo--basic` | `basic` | regular-holo | — (linear sunpillar bars) |
| `holo--cosmos` | `cosmos` | cosmos-holo | `cosmos-bottom/middle/top.png` (galaxy) |
| `holo--trainer` | `trainer` | v-full-art + trainer-full-art | `trainerbg.png` |

> The set was trimmed from 9 presets to these 4 (migration
> `Version20260706120000` remaps old configs: reverse→basic, rainbow/secret→shine,
> vmax/vstar→trainer).

Adaptation vs upstream: the artwork-window `clip-path`s are dropped (our cards
are full-art, like pokeholo's v-/trainer-full-art), and the per-energy-type and
trainer-gallery special-cases are removed.

```html
<div class="card interactive holo holo--cosmos" data-rarity="rare"> … </div>
```

Live preview + a side-by-side gallery of every preset: `/dev/card-effects`
(dev-only playground, `CardEffectsDemoController` + `card_playground_controller.js`).

## Bundled holo textures (`public/images/holo/poke/`)

The raster textures pulled from the pokeholo repo, used as the default foils /
glitter / grain of the presets above. They are versioned with the bundle so the
presets render out of the box; a per-card/extension foil upload overrides them.

| File | Use |
|------|-----|
| `glitter.png` | sparkle layer (`shine`) |
| `grain.webp` | film grain |
| `illusion.png` / `illusion-mask.png` | demo foil/mask of the `/dev/card-effects` playground |
| `cosmos-bottom/middle/top.png` | the 3 galaxy layers of `cosmos` |
| `trainerbg.png` | `trainer` foil |

A **per-card or per-extension** foil/mask uploaded in the BO (Vich, stored in
`public/uploads/foils|masks/`, git-ignored) overrides the preset default via the
inline `--foil` / `--mask` vars emitted by `CardComponent`.

### Foil zoom (`foilSize` / `--imgsize`)

The rendered size of the foil layer is driven by the `--imgsize` custom
property (consumed by the presets that draw `--foil`: `shine` and `trainer`):
`cover` stretches the texture over the whole card, `N%` tiles it as a repeated
motif of N % of the card width. Defaults: `cover` everywhere (shared `.card`
default), except the trainer preset on a non-masked card (20 % tiling,
declared as a **parent-level default** on `.card.holo--trainer:not(.masked)`
so the inline value can always win). The BO exposes it as the `foilSize`
slider / JSON key (`10`–`100`, `100` = cover, resolved card → extension); any
new preset that renders `--foil` should size that layer with `var(--imgsize)`
to stay compatible with the slider.

### Adding a library foil texture (`FoilTextureEnum`)

Drop the file in `public/images/holo/poke/`, add a case to
`App\Enum\Card\FoilTextureEnum` (`url()` + `label()`) — the BO selects pick it
up automatically. Sizing guidelines (based on the bundled set — ancient and
geometric are 300×300, trainerbg 208×208, vmaxbg 600×400):

- **~300×300 px, square** is the sweet spot: big enough to stay sharp in
  `cover` on a card, small enough to tile nicely at low `foilSize` values;
- **seamless/tileable** if possible — with the `foilSize` slider any texture
  can be tiled, and visible seams break the effect;
- keep it light (**< 100 KB**, PNG/WebP/JPEG): the texture loads on every
  card of a grid.

> For the practical, step-by-step guide on configuring a card and **authoring a
> good foil and mask**, see [`docs/card-setup-guide.md`](./card-setup-guide.md).

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

The pokeholo presets are self-contained — to retune a preset, edit its recipe in
`holo-presets.css`. (The old per-rarity `--holo-*` tuning knobs were removed
along with the rarity recipes.)

## How this maps to the back office (this app only)

The visual keys (`glow`, `borderColor`, `cssClass`, `holoEffect`,
`foilTexture`, `foilSize`) live in the
`VisualConfig` JSON edited in EasyAdmin, resolved **card → extension** by
`CardVisualResolver` (a holo card without preset falls back to `holo--basic`),
and emitted as the inline vars / classes above by `CardComponent`. Foil/mask **textures** are Vich uploads on the Card/Extension
and become `--foil` / `--mask`. So a non-technical admin tunes the foil layers and
the holo strength without touching CSS.

`holoEffect` is backed by the `CardEffectEnum`. On the **Extension** form it is a
dedicated *Holo preset* dropdown (merged into the visual-config JSON); on the
**Card** form it is set through the `holoEffect` key of the JSON override
(`{"holoEffect": "cosmos"}`). The cascade is the usual card override → extension →
none (pure rarity recipe).
