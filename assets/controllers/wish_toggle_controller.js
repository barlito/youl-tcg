import { Controller } from '@hotwired/stimulus';

// Wishlist heart / universe watch of the static pages: POST toggle, then repaint.
export default class extends Controller {
    static targets = ['icon', 'label'];
    static values = { url: String, active: Boolean, onLabel: String, offLabel: String, busy: Boolean };

    async toggle(event) {
        event.preventDefault();
        event.stopPropagation();

        if (this.busyValue) {
            return;
        }

        this.busyValue = true;

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                this.#toast(data.error ?? 'Action impossible pour le moment.');

                return;
            }

            this.activeValue = Boolean(data.active);
        } catch {
            this.#toast('Action impossible pour le moment.');
        } finally {
            this.busyValue = false;
        }
    }

    activeValueChanged() {
        this.element.setAttribute('aria-pressed', this.activeValue ? 'true' : 'false');

        if (this.hasIconTarget) {
            this.iconTarget.textContent = this.activeValue ? '♥' : '♡';
        }

        if (this.hasLabelTarget && this.onLabelValue !== '') {
            this.labelTarget.textContent = ' ' + (this.activeValue ? this.onLabelValue : this.offLabelValue);
        }
    }

    #toast(message) {
        window.dispatchEvent(new CustomEvent('toast:show', { detail: { message } }));
    }
}
