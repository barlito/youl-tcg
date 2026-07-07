import { Controller } from '@hotwired/stimulus';

/* Piste horizontale scroll-snap (complétion par univers) : flèches ‹ › et
 * molette verticale redirigée en défilement horizontal — les trackpads et le
 * tactile scrollent déjà nativement, on ne touche pas à leurs gestes. */
export default class extends Controller {
    static targets = ['track'];

    connect() {
        this.#centerActiveTile();
    }

    prev() {
        this.#scrollByPage(-1);
    }

    next() {
        this.#scrollByPage(1);
    }

    wheel(event) {
        if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) {
            return; // geste déjà horizontal, laisser le natif faire
        }

        event.preventDefault();
        this.trackTarget.scrollLeft += event.deltaY;
    }

    #scrollByPage(direction) {
        this.trackTarget.scrollBy({
            left: direction * this.trackTarget.clientWidth * 0.8,
            behavior: 'smooth',
        });
    }

    /* Univers filtré → sa tuile arrive centrée (scrollLeft direct plutôt que
     * scrollIntoView, qui ferait aussi défiler la page verticalement). */
    #centerActiveTile() {
        const active = this.trackTarget.querySelector('[data-carousel-active]');

        if (!active) {
            return;
        }

        this.trackTarget.scrollLeft = active.offsetLeft - (this.trackTarget.clientWidth - active.clientWidth) / 2;
    }
}
