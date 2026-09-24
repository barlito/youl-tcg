import { Controller } from '@hotwired/stimulus';

/**
 * Generic toast stack, mounted once in the layout. Anything can show a toast
 * by dispatching `toast:show` on window with `{ message, link?, title? }`;
 * realtime `live-updates:toast` / `live-updates:notification` events are
 * wired to the same action (`silent` payloads are skipped).
 */
export default class extends Controller {
    static values = {
        duration: { type: Number, default: 6000 },
    };

    show(event) {
        const { message, title, link, silent } = event.detail ?? {};
        if (!message || silent) {
            return;
        }

        const toast = document.createElement(link ? 'a' : 'div');
        toast.className = 'toast';
        toast.setAttribute('role', 'status');
        toast.dataset.testid = 'toast';
        if (link) {
            // internal links only: a payload must never send the player elsewhere
            toast.href = link.startsWith('/') && !link.startsWith('//') ? link : '/';
        }

        if (title) {
            const heading = document.createElement('span');
            heading.className = 'toast__title';
            heading.textContent = title;
            toast.append(heading);
        }

        const body = document.createElement('span');
        body.className = 'toast__message';
        body.textContent = message;
        toast.append(body);

        this.element.append(toast);
        requestAnimationFrame(() => toast.classList.add('toast--visible'));

        setTimeout(() => {
            toast.classList.remove('toast--visible');
            setTimeout(() => toast.remove(), 300);
        }, this.durationValue);
    }
}
