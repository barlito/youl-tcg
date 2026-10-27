import { Controller } from '@hotwired/stimulus';

/**
 * « Contenu du set » — the aside of the opening page. Pure client-side view of
 * the extension's cards: name search (debounced; a masked card has an empty
 * data-name, so it never matches), « Manquantes » filter, « x / y trouvées »
 * counter and a scroll-to-top affordance. It owns no server state: the reveal
 * controller unmasks freshly won tiles and fires `opening:unmasked`.
 */

const DEBOUNCE_MS = 150;
const SCROLL_TOP_AT = 240; // px scrolled before the "back to top" button shows

export default class extends Controller {
    static targets = ['search', 'missing', 'grid', 'tile', 'found', 'empty', 'scroll', 'top'];

    connect() {
        this.missingOnly = false;
    }

    disconnect() {
        clearTimeout(this.debounce);
    }

    filter() {
        clearTimeout(this.debounce);
        this.debounce = setTimeout(() => this._apply(), DEBOUNCE_MS);
    }

    toggleMissing() {
        this.missingOnly = !this.missingOnly;
        this.missingTarget.classList.toggle('on', this.missingOnly);
        this.missingTarget.setAttribute('aria-pressed', String(this.missingOnly));
        this._apply();
    }

    refresh() {
        if (this.hasFoundTarget) {
            this.foundTarget.textContent = `${this.tileTargets.filter((tile) => !tile.classList.contains('is-masked')).length}`;
        }
        this._apply();
    }

    _apply() {
        const query = this.hasSearchTarget ? this.searchTarget.value.trim().toLowerCase() : '';
        let visible = 0;
        this.tileTargets.forEach((tile) => {
            const masked = tile.classList.contains('is-masked');
            const match = (query === '' || (tile.dataset.name || '').includes(query))
                && (!this.missingOnly || masked);
            tile.hidden = !match;
            if (match) {
                visible++;
            }
        });
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = visible !== 0;
            this.emptyTarget.textContent = this.missingOnly && query === ''
                ? 'Rien ne manque : set complet !'
                : 'Aucune de tes cartes ne correspond.';
        }
    }

    onScroll() {
        if (this.hasTopTarget && this.hasScrollTarget) {
            this.topTarget.hidden = this.scrollTarget.scrollTop < SCROLL_TOP_AT;
        }
    }

    scrollTop() {
        this.scrollTarget?.scrollTo({ top: 0, behavior: 'smooth' });
    }
}
