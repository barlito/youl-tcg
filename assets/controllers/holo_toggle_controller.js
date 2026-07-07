import { Controller } from '@hotwired/stimulus';

/**
 * Collection tile: switches a card owned in holo between its holo and normal
 * renderings, fully client-side (no server round-trip).
 *
 * The holo look only exists through the `holo--<preset>` CSS recipes
 * (holo-presets.css), so the toggle flips both the semantic `holo` marker and
 * the preset class on the tile's .card element. The preset is resolved
 * server-side (card → extension cascade, holo--basic fallback) and carried by
 * the holoClass value.
 */
export default class extends Controller {
    static targets = ['button'];

    static values = { holoClass: String };

    toggle() {
        const card = this.element.querySelector('.card');
        if (!card) {
            return;
        }

        const enabled = card.classList.toggle('holo');
        card.classList.toggle(this.holoClassValue, enabled);
        this.buttonTarget.setAttribute('aria-pressed', String(enabled));
    }
}
