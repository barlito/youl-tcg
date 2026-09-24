import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

const DAILY_TOAST_KEY = 'ytcg:daily-boosters-toast';

/**
 * Companion of the NotificationBell live component: closes the dropdown on
 * outside click / Escape, and toasts "daily boosters available" once per
 * quota period — computed client-side from the server countdown, never stored.
 */
export default class extends Controller {
    static values = {
        open: Boolean,
        remaining: Number,
        seconds: Number,
        period: String,
        nextPeriod: String,
    };

    connect() {
        if (this.remainingValue > 0) {
            this.toastOnce(this.periodValue);
        } else if (this.secondsValue > 0) {
            // +1s margin: the server must already be in the new period on re-render
            this.resetTimer = setTimeout(() => {
                this.toastOnce(this.nextPeriodValue);
                this.withComponent((component) => component.render());
            }, (this.secondsValue + 1) * 1000);
        }
    }

    disconnect() {
        clearTimeout(this.resetTimer);
    }

    clickOutside(event) {
        if (this.openValue && !this.element.contains(event.target)) {
            this.close();
        }
    }

    close() {
        if (this.openValue) {
            this.withComponent((component) => component.action('close'));
        }
    }

    toastOnce(period) {
        if (!period || this.readStoredPeriod() === period) {
            return;
        }

        try {
            localStorage.setItem(DAILY_TOAST_KEY, period);
        } catch {
            // storage unavailable: the toast may repeat, harmless
        }

        if (window.location.pathname.startsWith('/boosters')) {
            return; // the hub already says it
        }

        // deferred: the toast stack sits after the header and may not listen yet
        setTimeout(() => window.dispatchEvent(new CustomEvent('toast:show', {
            detail: { title: 'Boosters', message: 'Tes boosters du jour sont disponibles', link: '/boosters' },
        })), 800);
    }

    readStoredPeriod() {
        try {
            return localStorage.getItem(DAILY_TOAST_KEY);
        } catch {
            return null;
        }
    }

    withComponent(callback) {
        getComponent(this.element).then(callback).catch(() => {});
    }
}
