import { Controller } from '@hotwired/stimulus';

/**
 * "Set Contents" — the lower, independently-scrolling zone of the opening aside.
 * Pure client-side reference catalogue of the whole extension: live search
 * (debounced), client-side sort, and a scroll-to-top affordance. It owns no
 * server state — the cards are rendered once and this controller only reorders /
 * hides the existing tiles.
 */

const RANK = { common: 0, uncommon: 1, rare: 2, epic: 3, legendary: 4 };
const DEBOUNCE_MS = 150;
const SCROLL_TOP_AT = 240; // px scrolled before the "back to top" button shows

export default class extends Controller {
    static targets = ['search', 'clear', 'sort', 'grid', 'tile', 'count', 'empty', 'scroll', 'top'];

    connect() {
        this.total = this.tileTargets.length;
        // DOM order at load == the "Numéro" / default order, captured for resets
        this.defaultOrder = this.tileTargets.slice();
    }

    disconnect() {
        clearTimeout(this.debounce);
    }

    // ----------------------------------------------------------- search
    filter() {
        // toggle the clear (✕) button immediately, debounce the actual filtering
        if (this.hasClearTarget) {
            this.clearTarget.hidden = this.searchTarget.value.trim() === '';
        }
        clearTimeout(this.debounce);
        this.debounce = setTimeout(() => this._applyFilter(), DEBOUNCE_MS);
    }

    clear() {
        this.searchTarget.value = '';
        if (this.hasClearTarget) {
            this.clearTarget.hidden = true;
        }
        this._applyFilter();
        this.searchTarget.focus();
    }

    _applyFilter() {
        const query = this.searchTarget.value.trim().toLowerCase();
        let visible = 0;
        this.tileTargets.forEach((tile) => {
            const match = query === '' || tile.dataset.name.includes(query);
            tile.hidden = !match;
            if (match) {
                visible++;
            }
        });
        if (this.hasCountTarget) {
            this.countTarget.textContent = `${visible}`;
        }
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = visible !== 0;
        }
    }

    // ----------------------------------------------------------- sort
    sort() {
        const mode = this.hasSortTarget ? this.sortTarget.value : 'default';
        let ordered;

        switch (mode) {
            case 'name-asc':
                ordered = this.tileTargets.slice().sort((a, b) => a.dataset.name.localeCompare(b.dataset.name));
                break;
            case 'name-desc':
                ordered = this.tileTargets.slice().sort((a, b) => b.dataset.name.localeCompare(a.dataset.name));
                break;
            case 'rarity':
                // rarest first, ties broken by name for a stable, readable order
                ordered = this.tileTargets.slice().sort((a, b) =>
                    (RANK[b.dataset.rarity] ?? 0) - (RANK[a.dataset.rarity] ?? 0)
                    || a.dataset.name.localeCompare(b.dataset.name));
                break;
            default:
                ordered = this.defaultOrder;
        }

        // re-append in the new order (appendChild moves nodes, no clones)
        ordered.forEach((tile) => this.gridTarget.appendChild(tile));
    }

    // ----------------------------------------------------- scroll to top
    onScroll() {
        if (this.hasTopTarget && this.hasScrollTarget) {
            this.topTarget.hidden = this.scrollTarget.scrollTop < SCROLL_TOP_AT;
        }
    }

    scrollTop() {
        this.scrollTarget?.scrollTo({ top: 0, behavior: 'smooth' });
    }
}
