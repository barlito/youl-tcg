#!/usr/bin/env python3
"""Generate holo masks from a card artwork using rembg (background removal).

A mask says WHERE the foil shows (white/opaque) vs stays matte (transparent).
From the subject cutout we derive two masks:
  - <name>-mask-subject.png    : holo on the subject (e.g. the character)
  - <name>-mask-background.png : reverse holo — holo everywhere BUT the subject

Foils (the holographic *pattern*) are NOT produced here: rembg only segments.
Foils are generated procedurally / shared per extension (see public/images/holo).

Usage:
    make_masks.py <artwork> [--out DIR] [--blur N] [--only subject|background]
"""
import argparse
import os

from PIL import Image, ImageFilter
from rembg import new_session, remove


def make_mask(alpha: Image.Image, invert: bool = False) -> Image.Image:
    """White RGB with the region in the alpha channel (CSS alpha mask)."""
    if invert:
        alpha = alpha.point(lambda v: 255 - v)
    mask = Image.new("RGBA", alpha.size, (255, 255, 255, 0))
    mask.putalpha(alpha)
    return mask


def main() -> None:
    parser = argparse.ArgumentParser(description="Generate holo masks from an artwork (rembg).")
    parser.add_argument("artwork", help="path to the source artwork")
    parser.add_argument("--out", default=None, help="output dir (default: alongside the artwork)")
    parser.add_argument("--blur", type=float, default=1.5, help="edge feather in px (0 = hard edge)")
    parser.add_argument("--only", choices=["subject", "background"], default=None)
    args = parser.parse_args()

    source = Image.open(args.artwork).convert("RGBA")
    cutout = remove(source, session=new_session("u2net"))
    alpha = cutout.getchannel("A")
    if args.blur > 0:
        alpha = alpha.filter(ImageFilter.GaussianBlur(args.blur))

    out_dir = args.out or os.path.dirname(os.path.abspath(args.artwork))
    os.makedirs(out_dir, exist_ok=True)
    base = os.path.splitext(os.path.basename(args.artwork))[0]

    if args.only != "background":
        path = os.path.join(out_dir, f"{base}-mask-subject.png")
        make_mask(alpha).save(path)
        print("written:", path)
    if args.only != "subject":
        path = os.path.join(out_dir, f"{base}-mask-background.png")
        make_mask(alpha, invert=True).save(path)
        print("written:", path)


if __name__ == "__main__":
    main()
