import { Controller } from '@hotwired/stimulus';
import { drawPackFront, PACK_FRONT_W, PACK_FRONT_H } from '../lib/pack_front.js';

/**
 * Paints a YOUL pack front into its own <canvas> — the same composite used by the
 * 3D opening pack (drawPackFront), so a boosters-grid tile looks like a real pack
 * without any WebGL. Static: drawn once on connect, then CSS scales it down.
 *
 * Values mirror the 3D pack: bgUrl (extension/booster artwork, empty → fallback),
 * fallbackUrl + logoUrl (the branded YOUL front), name + count.
 */
export default class extends Controller {
    static values = {
        bgUrl: String,
        fallbackUrl: String,
        logoUrl: String,
        name: String,
        count: Number,
    };

    async connect() {
        this.element.width = PACK_FRONT_W;
        this.element.height = PACK_FRONT_H;

        let hero = this.hasBgUrlValue && this.bgUrlValue ? await this._load(this.bgUrlValue) : null;
        const fallback = !hero;
        if (fallback && this.hasFallbackUrlValue && this.fallbackUrlValue) {
            hero = await this._load(this.fallbackUrlValue);
        }
        const logo = fallback && this.hasLogoUrlValue && this.logoUrlValue ? await this._load(this.logoUrlValue) : null;

        if (this._gone) {
            return; // disconnected while images were loading
        }

        drawPackFront(this.element.getContext('2d'), {
            hero,
            logo,
            name: this.hasNameValue ? this.nameValue : '',
            count: this.hasCountValue && this.countValue ? this.countValue : 5,
            fallback,
        });
    }

    disconnect() {
        this._gone = true;
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
