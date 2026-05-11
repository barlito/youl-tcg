import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        // Initialize 3D cards when controller connects
        this.initializeCards();
    }

    initializeCards() {
        // Wait for next tick to ensure DOM is fully ready
        requestAnimationFrame(() => {
            if (typeof window.initCard === 'function') {
                this.element.querySelectorAll('.card.interactive').forEach(window.initCard);
            }
        });
    }
}
