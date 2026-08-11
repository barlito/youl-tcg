import { Controller } from '@hotwired/stimulus';

/**
 * Shows one stat panel at a time out of a server-rendered set, driven by a
 * <select>. Everything is already in the DOM: switching packs costs no round
 * trip, and the section keeps a constant height however many packs exist.
 */
export default class extends Controller {
    static targets = ['select', 'panel'];

    show() {
        const selected = this.selectTarget.value;

        this.panelTargets.forEach((panel) => {
            panel.classList.toggle('hidden', panel.dataset.panelId !== selected);
        });
    }
}
