import { Controller } from '@hotwired/stimulus';

/**
 * Live countdown from a server-computed number of seconds
 * (`data-countdown-seconds-value`). Counting locally keeps the display
 * immune to client clock skew: the deadline is the server's, not the
 * client's. Reloads the page once so quota-dependent UI refreshes.
 */
export default class extends Controller {
    static values = {
        seconds: Number
    }

    connect() {
        this.remaining = Math.max(0, Math.floor(this.secondsValue));
        this.render();
        this.interval = setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        if (this.interval) {
            clearInterval(this.interval);
        }
    }

    tick() {
        this.remaining -= 1;

        if (this.remaining <= 0) {
            clearInterval(this.interval);
            window.location.reload();
            return;
        }

        this.render();
    }

    render() {
        const hours = Math.floor(this.remaining / 3600);
        const minutes = Math.floor((this.remaining % 3600) / 60);
        const seconds = this.remaining % 60;

        this.element.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    }
}
