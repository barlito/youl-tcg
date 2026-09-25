import { Controller } from '@hotwired/stimulus';

/**
 * Opens the Mercure subscription once per page (mounted in the layout for a
 * logged-in player) and redispatches every message as a `live-updates:<type>`
 * DOM event on window, payload in `detail`. EventSource handles transient
 * reconnections itself; a refused connection (expired cookie, hub restart)
 * closes it for good, so we retry with a capped backoff.
 */
export default class extends Controller {
    static values = {
        url: String,
    };

    connect() {
        this.retryDelay = 2000;
        this.open();
    }

    disconnect() {
        clearTimeout(this.retryTimer);
        this.source?.close();
        this.source = null;
    }

    open() {
        if (!this.urlValue || typeof EventSource === 'undefined') {
            return;
        }

        this.source = new EventSource(this.urlValue, { withCredentials: true });
        this.source.onopen = () => {
            this.retryDelay = 2000;
        };
        this.source.onmessage = (event) => this.dispatchMessage(event.data);
        this.source.onerror = () => {
            if (this.source?.readyState !== EventSource.CLOSED) {
                return;
            }

            this.source = null;
            this.retryTimer = setTimeout(() => this.open(), this.retryDelay);
            this.retryDelay = Math.min(this.retryDelay * 2, 60000);
        };
    }

    dispatchMessage(data) {
        let message;
        try {
            message = JSON.parse(data);
        } catch {
            return;
        }

        if (typeof message?.type !== 'string') {
            return;
        }

        window.dispatchEvent(new CustomEvent(`live-updates:${message.type}`, { detail: message.payload ?? {} }));
    }
}
