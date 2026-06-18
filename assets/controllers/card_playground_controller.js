import { Controller } from '@hotwired/stimulus';

/**
 * Dev-only playground for the holo effect presets (/dev/card-effects).
 *
 * Wires a control panel (range sliders + selects + toggles) to a live demo
 * card: it writes the inline --holo-* CSS vars and swaps the `holo--*` /
 * `data-rarity` classes and the `masked`/`holo` toggles on the card element so
 * every change is reflected instantly, without a page reload.
 *
 * Targets:
 *   - card      : the demo .card element
 *   - intensity / saturation / glitter : <input type="range">
 *   - effect / rarity : <select>
 *   - foil / mask     : <input type="checkbox">
 *   - and matching `*Out` value-readout spans (optional)
 */
export default class extends Controller {
    static targets = [
        'card',
        'intensity', 'saturation', 'glitter',
        'intensityOut', 'saturationOut', 'glitterOut',
        'effect', 'rarity', 'foil', 'mask',
    ];

    connect() {
        // derive every preset class from the select options, so new presets
        // (vmax / vstar / trainer / …) are cleared correctly when switching
        this.effectClasses = [...this.effectTarget.options]
            .map((option) => option.value)
            .filter((value) => value !== '')
            .map((value) => `holo--${value}`);
        this.apply();
    }

    apply() {
        const card = this.cardTarget;

        // --- knobs (inline vars win over the rarity defaults in holo.css) ---
        this.setVar(card, '--holo-intensity', this.intensityTarget, this.hasIntensityOutTarget ? this.intensityOutTarget : null);
        this.setVar(card, '--holo-saturation', this.saturationTarget, this.hasSaturationOutTarget ? this.saturationOutTarget : null);
        this.setVar(card, '--holo-glitter', this.glitterTarget, this.hasGlitterOutTarget ? this.glitterOutTarget : null);

        // --- rarity ---
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

        // keep the permanent holo veil on so effects are visible at rest too
        card.classList.add('holo');
    }

    setVar(card, name, input, out) {
        card.style.setProperty(name, input.value);
        if (out) {
            out.textContent = Number(input.value).toFixed(2);
        }
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
