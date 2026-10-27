import { Controller } from '@hotwired/stimulus';

// Filters the trade player picker by username, client side (every player is already rendered).
export default class extends Controller {
    static targets = ['player', 'empty'];

    filter(event) {
        const needle = event.target.value.trim().toLowerCase();
        let shown = 0;

        this.playerTargets.forEach((player) => {
            const match = needle === '' || (player.dataset.name ?? '').includes(needle);
            player.hidden = !match;
            shown += match ? 1 : 0;
        });

        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = shown > 0;
        }
    }
}
