import { Controller } from '@hotwired/stimulus';
import { CardTilt } from '../lib/card_tilt.js';

/**
 * Stimulus glue for the card 3D tilt + holo effect.
 *
 * Auto-attached to any `.card.interactive` element via `data-controller="card"`.
 * All the actual logic lives in the framework-agnostic `CardTilt` module
 * (assets/lib/card_tilt.js) so the effect can be reused outside Symfony/Stimulus
 * by importing that module directly — this controller is only the lifecycle glue.
 */
export default class extends Controller {
    connect() {
        this.tilt = new CardTilt(this.element);
    }

    disconnect() {
        this.tilt?.destroy();
    }
}
