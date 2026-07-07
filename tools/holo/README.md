# Holo mask generator

Generates per-card holo **masks** from an artwork, in a self-contained Docker
image (`rembg` background removal). A mask defines **where** the foil shows; the
foil pattern itself is separate (procedural / shared, see `public/images/holo`).

## Build (once)

```bash
docker build -t ytcg-holo tools/holo
```

## Use — batch par extension (recommandé)

```bash
# tout-en-un : rembg chaque carte publiée SANS mask de l'extension, écrit les
# masks dans public/images/masks/ et met à jour card.image_mask_name en base
# (les masks existants ne sont jamais écrasés — idempotent)
castor holo:masks <extension-slug> [--variant background|subject] [--blur 1.5]
```

`background` (défaut) = reverse holo, le personnage reste net et le fond brille ;
`subject` = l'inverse.

## Use — une carte à la main

```bash
# from the project root; paths are relative to the mounted /work
# (accepte plusieurs artworks — une seule session rembg pour tout le lot)
docker run --rm -v "$PWD:/work" ytcg-holo public/images/cards/<artwork>.png
```

Outputs next to the artwork:

| File | Effect |
|------|--------|
| `<name>-mask-subject.png` | holo on the subject (e.g. the character) |
| `<name>-mask-background.png` | reverse holo — holo everywhere **but** the subject |

Options: `--out DIR`, `--blur N` (edge feather px, default 1.5), `--only subject|background`.

White (opaque) = where the foil shows, transparent = matte. Upload the chosen
mask as the card's *Holo mask* in the BO; the engine clips the foil to it.

> Only run this on **your own** artwork. Masks/foils derived from third-party
> card scans (e.g. Pokémon) are not yours to ship.
