import { Controller } from '@hotwired/stimulus';

/**
 * Dev-only playground for the holo effect presets (/dev/card-effects).
 *
 * Wires a control panel (selects + toggles) to a live demo card: it swaps the
 * `holo--*` / `data-rarity` classes and the `masked`/`holo` toggles on the
 * card element so every change is reflected instantly, without a page reload.
 * (The --holo-* tuning knobs died with the per-rarity recipes: holo rendering
 * is presets-only now, data-rarity only drives the glow.)
 *
 * Targets:
 *   - card            : the demo .card element
 *   - effect / rarity : <select>
 *   - foil / mask     : <input type="checkbox">
 */
export default class extends Controller {
    static targets = [
        'card', 'effect', 'rarity', 'foil', 'mask',
        'libraryFoil', 'frame', 'nameColor', 'frameGradient', 'frameLayer', 'nameLayer',
    ];

    connect() {
        // derive every preset class from the select options, so new presets
        // are cleared correctly when switching
        this.effectClasses = [...this.effectTarget.options]
            .map((option) => option.value)
            .filter((value) => value !== '')
            .map((value) => `holo--${value}`);
        this.apply();
    }

    apply() {
        const card = this.cardTarget;

        // --- rarity (glow only) ---
        card.dataset.rarity = this.rarityTarget.value;

        // --- effect preset class ---
        this.effectClasses.forEach((cls) => card.classList.remove(cls));
        const effect = this.effectTarget.value;
        if (effect) {
            card.classList.add(`holo--${effect}`);
        }

        // --- foil / mask toggles ---
        card.classList.toggle('masked', this.maskTarget.checked);
        this.toggleVar(card, '--mask', this.maskTarget.checked);
        // uploaded (demo) foil wins over a library texture, like in the resolver
        if (this.foilTarget.checked) {
            this.toggleVar(card, '--foil', true);
        } else if (this.hasLibraryFoilTarget && this.libraryFoilTarget.value) {
            card.style.setProperty('--foil', `url('${this.libraryFoilTarget.value}')`);
        } else {
            card.style.removeProperty('--foil');
        }

        // --- frame (name + gradient border), same wiring as CardComponent ---
        const framed = this.hasFrameTarget && this.frameTarget.checked;
        if (this.hasFrameLayerTarget) {
            this.frameLayerTarget.hidden = !framed;
        }
        if (this.hasNameLayerTarget) {
            this.nameLayerTarget.hidden = !framed;
        }
        if (framed) {
            card.style.setProperty('--card-name-color', this.nameColorTarget.value || '#fff');
            card.style.setProperty('--card-frame', this.frameGradientTarget.value || 'linear-gradient(160deg, #6ea8dc, #10182b 45%, #0b0712 60%, #7a1d1d)');
        } else {
            card.style.removeProperty('--card-name-color');
            card.style.removeProperty('--card-frame');
        }

        // no at-rest veil anywhere (product decision): effects only on hover
    }

    /**
     * --mask / --foil reference demo textures shipped in /public/images.
     * Removing the var lets the card fall back to no texture.
     */
    toggleVar(card, name, enabled) {
        if (enabled) {
            const url = name === '--mask'
                ? card.dataset.demoMask
                : card.dataset.demoFoil;
            if (url) {
                card.style.setProperty(name, `url('${url}')`);
            }
        } else {
            card.style.removeProperty(name);
        }
    }
}
