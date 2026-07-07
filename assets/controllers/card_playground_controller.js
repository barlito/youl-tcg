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
    static targets = ['card', 'effect', 'rarity', 'foil', 'mask'];

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
        this.toggleVar(card, '--foil', this.foilTarget.checked);

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
