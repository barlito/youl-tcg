import { Controller } from '@hotwired/stimulus';
import anime from '../scripts/card/lib/anime.es.js';

/**
 * Sequential reveal of the cards drawn from a booster.
 *
 * Cards start face-down (`.card.facedown`, rotator at rotateY(180deg)) and
 * flip one by one with a stagger. Clicking anywhere in the grid skips the
 * animation and reveals everything. Once revealed, the interactive 3D
 * card_controller takes over (pointer-events are re-enabled by removing
 * `.facedown`).
 */
export default class extends Controller {
    static targets = ['card'];

    connect() {
        this.finished = false;

        const rotators = this.cardTargets.map((wrapper) => wrapper.querySelector('.card__rotator'));
        rotators.forEach((rotator) => rotator.style.setProperty('transform', 'rotateY(180deg)'));

        this.timeline = anime.timeline({
            autoplay: false,
            complete: () => { this.finished = true; },
        });

        this.cardTargets.forEach((wrapper, index) => {
            this.timeline.add({
                targets: wrapper.querySelector('.card__rotator'),
                rotateY: [180, 0],
                scale: [
                    { value: 1.08, duration: 250, easing: 'easeOutQuad' },
                    { value: 1, duration: 350, easing: 'easeOutQuad' },
                ],
                duration: 700,
                easing: 'easeOutBack',
                begin: () => this.#flipStarted(wrapper),
                complete: () => this.#reveal(wrapper),
            }, 400 + index * 650);
        });

        this.timeline.play();
    }

    disconnect() {
        if (this.timeline) {
            this.timeline.pause();
        }
    }

    skip() {
        if (this.finished) {
            return;
        }

        this.finished = true;
        this.timeline.pause();
        this.cardTargets.forEach((wrapper) => {
            const rotator = wrapper.querySelector('.card__rotator');
            rotator.style.removeProperty('transform');
            this.#flipStarted(wrapper);
            this.#reveal(wrapper);
        });
    }

    #flipStarted(wrapper) {
        // Half-way through the flip the front becomes visible: drop the
        // facedown rotation class now, the inline transform drives the rest.
        wrapper.querySelector('.card').classList.remove('facedown');
    }

    #reveal(wrapper) {
        const card = wrapper.querySelector('.card');
        card.classList.add('revealed');

        const rotator = wrapper.querySelector('.card__rotator');
        rotator.style.removeProperty('transform');
    }
}
