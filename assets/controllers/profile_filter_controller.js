import { Controller } from '@hotwired/stimulus';

/**
 * Filters the compared collection of a player profile. Each tile carries its
 * comparison state (common / profile-only / visitor-only / missing-both /
 * mystery); this controller only hides tiles and the universes left empty — the
 * whole catalogue is rendered once, no server round-trip.
 */
export default class extends Controller {
    static targets = ['tile', 'universe', 'button'];

    select(event) {
        this.apply(event.currentTarget.dataset.state);
    }

    apply(state) {
        this.buttonTargets.forEach((button) => {
            const active = button.dataset.state === state;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        this.tileTargets.forEach((tile) => {
            tile.hidden = state !== 'all' && tile.dataset.state !== state;
        });

        this.universeTargets.forEach((universe) => {
            universe.hidden = universe.querySelectorAll('[data-profile-filter-target="tile"]:not([hidden])').length === 0;
        });
    }
}
