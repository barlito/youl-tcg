import { Controller } from '@hotwired/stimulus';

/**
 * Filters the compared collection of a player profile. Each tile carries its
 * comparison state (common / profile-only / visitor-only / missing-both); this
 * controller only hides tiles — the whole catalogue is rendered once, no server
 * round-trip. Universe headings follow the filter so a "10 cartes" title never
 * sits above a single tile.
 */
export default class extends Controller {
    static targets = ['tile', 'universe', 'button', 'count', 'counters', 'empty', 'status'];

    select(event) {
        this.apply(event.currentTarget.dataset.state);
    }

    apply(state) {
        const filtering = state !== 'all';

        this.buttonTargets.forEach((button) => {
            const active = button.dataset.state === state;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        this.tileTargets.forEach((tile) => {
            tile.hidden = filtering && tile.dataset.state !== state;
        });

        let total = 0;
        this.universeTargets.forEach((universe) => {
            const visible = universe.querySelectorAll('[data-profile-filter-target="tile"]:not([hidden])').length;
            universe.hidden = visible === 0;
            total += visible;

            const count = universe.querySelector('[data-profile-filter-target="count"]');
            if (count) {
                const all = Number(count.dataset.total);
                count.textContent = filtering ? `${visible} sur ${all}` : `${all} carte${all > 1 ? 's' : ''}`;
            }

            universe.querySelector('[data-profile-filter-target="counters"]')?.classList.toggle('opacity-40', filtering);
        });

        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = total > 0;
        }
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = `${total} carte${total > 1 ? 's' : ''} affichée${total > 1 ? 's' : ''}.`;
        }
    }
}
