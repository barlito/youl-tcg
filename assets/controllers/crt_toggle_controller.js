import { Controller } from '@hotwired/stimulus';

/**
 * Toggles the site-wide CRT scanlines overlay (.scanlines) on/off and remembers
 * the choice in localStorage. Adds `crt-off` on <html> when disabled; the CSS
 * hides the overlay from there. Default: CRT on.
 */
const KEY = 'youl:crt';

export default class extends Controller {
    static targets = ['checkbox'];

    connect() {
        this.enabled = localStorage.getItem(KEY) !== 'off';
        this._apply();
    }

    toggle() {
        this.enabled = this.hasCheckboxTarget ? this.checkboxTarget.checked : !this.enabled;
        localStorage.setItem(KEY, this.enabled ? 'on' : 'off');
        this._apply();
    }

    _apply() {
        document.documentElement.classList.toggle('crt-off', !this.enabled);
        if (this.hasCheckboxTarget) {
            this.checkboxTarget.checked = this.enabled;
        }
    }
}
