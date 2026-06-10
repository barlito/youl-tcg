import { Controller } from '@hotwired/stimulus';

/**
 * Live countdown to a target datetime (ISO 8601 in `data-countdown-target-value`).
 * Reloads the page once the target is reached so quota-dependent UI refreshes.
 */
export default class extends Controller {
    static values = {
        target: String
    }

    connect() {
        this.updateCountdown();
        this.interval = setInterval(() => this.updateCountdown(), 1000);
    }

    disconnect() {
        if (this.interval) {
            clearInterval(this.interval);
        }
    }

    updateCountdown() {
        const now = new Date();
        const target = new Date(this.targetValue);
        const diff = target - now;

        if (diff <= 0) {
            window.location.reload();
            return;
        }

        const hours = Math.floor(diff / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((diff % (1000 * 60)) / 1000);

        this.element.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    }
}
