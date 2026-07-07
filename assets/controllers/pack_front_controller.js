import { Controller } from '@hotwired/stimulus';
import { drawPackFront, PACK_FRONT_W, PACK_FRONT_H } from '../lib/pack_front.js';

/**
 * Paints a YOUL pack front into its own <canvas> — the same composite used by the
 * 3D opening pack (drawPackFront), so a boosters-grid tile looks like a real pack
 * without any WebGL.
 *
 * Redraws whenever a value changes: a Live Component re-render morphs the DOM in
 * place and may hand this canvas ANOTHER booster's data-* values (the tiles get
 * reused positionally) — the attributes update, but a canvas bitmap is not DOM,
 * so without the redraw the tile keeps the previous booster's artwork.
 *
 * Values mirror the 3D pack: bgUrl (extension/booster artwork, empty → fallback),
 * fallbackUrl + logoUrl (the branded YOUL front), name + count, plain (custom
 * booster image: full-bleed, no text drawn on top).
 */
export default class extends Controller {
    static values = {
        bgUrl: String,
        fallbackUrl: String,
        logoUrl: String,
        name: String,
        count: Number,
        plain: Boolean,
    };

    connect() {
        // Stimulus reuses the instance when the element is detached then
        // re-attached (e.g. a morph moving the node): re-arm the kill switch
        // or the canvas would stay frozen on its last bitmap forever.
        this._gone = false;
        this.element.width = PACK_FRONT_W;
        this.element.height = PACK_FRONT_H;
        this._redraw();
    }

    disconnect() {
        this._gone = true;
    }

    // one callback per value: any of them changing means the tile now shows a
    // different booster (or its config changed) — repaint from scratch
    bgUrlValueChanged() { this._redraw(); }
    nameValueChanged() { this._redraw(); }
    countValueChanged() { this._redraw(); }
    plainValueChanged() { this._redraw(); }

    async _redraw() {
        // collapse the burst of valueChanged callbacks a morph triggers into one paint
        const ticket = this._ticket = (this._ticket ?? 0) + 1;

        let hero = this.hasBgUrlValue && this.bgUrlValue ? await this._load(this.bgUrlValue) : null;
        const fallback = !hero;
        if (fallback && this.hasFallbackUrlValue && this.fallbackUrlValue) {
            hero = await this._load(this.fallbackUrlValue);
        }
        const logo = fallback && this.hasLogoUrlValue && this.logoUrlValue ? await this._load(this.logoUrlValue) : null;

        if (this._gone || ticket !== this._ticket) {
            return; // disconnected, or a newer redraw superseded this one
        }

        drawPackFront(this.element.getContext('2d'), {
            hero,
            logo,
            name: this.hasNameValue ? this.nameValue : '',
            count: this.hasCountValue && this.countValue ? this.countValue : 5,
            fallback,
            plain: this.hasPlainValue && this.plainValue && !fallback,
        });
    }

    _load(src) {
        return new Promise((resolve) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = () => resolve(null);
            img.src = src;
        });
    }
}
