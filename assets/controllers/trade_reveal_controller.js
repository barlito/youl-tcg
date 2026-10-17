import { Controller } from '@hotwired/stimulus';

// keys already played in this page: a live re-render that rebuilds the overlay must not replay it
const played = new Set();

const FLIP_MS = 560;
const STEP_MS = 650;
const CLIMAX_PAUSE_MS = 450;
const SHINY = new Set(['rare', 'legendary']);

// received cards of an accepted trade, flipped one by one rarest last, new ones stamped
export default class extends Controller {
    static targets = ['card', 'close', 'flash'];

    static values = {
        key: String,
        entry: String,
    };

    connect() {
        this.timers = [];
        this.onKeydown = (event) => {
            if (event.key === 'Escape') {
                this.closeTarget.click();
            }
        };
        document.addEventListener('keydown', this.onKeydown);
        this._dropLinkParam();

        if (played.has(this.keyValue) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            this._showAll();

            return;
        }
        played.add(this.keyValue);
        this._play();
    }

    disconnect() {
        this.timers.forEach(clearTimeout);
        document.removeEventListener('keydown', this.onKeydown);
    }

    close() {
        this.element.hidden = true;
        document.getElementById(this.entryValue)?.scrollIntoView({ block: 'center' });
    }

    _play() {
        const cards = this.cardTargets;
        const last = cards.length - 1;
        let at = 350;

        cards.forEach((card, index) => {
            if (index === last && last > 0) {
                at += CLIMAX_PAUSE_MS;
            }
            this._after(at, () => this._flip(card, index === last));
            at += STEP_MS;
        });
        this._after(at + FLIP_MS, () => this._finish());
    }

    _flip(card, isLast) {
        card.classList.add('is-revealed');
        this._after(FLIP_MS, () => {
            const { rarity } = card.dataset;
            if (isLast || SHINY.has(rarity)) {
                this._retrigger(card, 'is-pop');
            }
            if (rarity === 'legendary') {
                this._flash(card, 0.55);
            } else if (isLast && SHINY.has(rarity)) {
                this._flash(card, 0.35);
            }
            this._stamp(card);
        });
    }

    _showAll() {
        this.cardTargets.forEach((card) => {
            card.classList.add('is-revealed');
            this._stamp(card);
        });
        this._finish();
    }

    _finish() {
        this.element.classList.add('is-done');
        this.closeTarget.focus({ preventScroll: true });
    }

    _stamp(card) {
        const tag = card.querySelector('[data-new-tag]');
        if (tag && !tag.classList.contains('is-on')) {
            this._retrigger(tag, 'is-on');
        }
    }

    _flash(card, opacity) {
        this.flashTarget.style.setProperty('--reveal-color', getComputedStyle(card).getPropertyValue('--reveal-color'));
        this.flashTarget.style.setProperty('--flash', `${opacity}`);
        this.flashTarget.classList.add('is-on');
        this._after(220, () => this.flashTarget.classList.remove('is-on'));
    }

    // the notification link's ?offre= must not replay the reveal on a reload
    _dropLinkParam() {
        const url = new URL(window.location.href);
        if (url.searchParams.has('offre')) {
            url.searchParams.delete('offre');
            window.history.replaceState(window.history.state, '', url);
        }
    }

    _retrigger(node, className) {
        node.classList.remove(className);
        void node.offsetWidth;
        node.classList.add(className);
    }

    _after(ms, fn) {
        this.timers.push(setTimeout(fn, ms));
    }
}
