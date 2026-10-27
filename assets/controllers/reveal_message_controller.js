import { Controller } from '@hotwired/stimulus';

// A live re-render can leave its flash message above the viewport: bring it back into view.
export default class extends Controller {
    static values = { key: String };

    keyValueChanged() {
        const rect = this.element.getBoundingClientRect();
        const header = document.querySelector('.site-header')?.getBoundingClientRect().bottom ?? 0;

        if (rect.top >= Math.max(0, header) && rect.bottom <= window.innerHeight) {
            return;
        }

        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.element.scrollIntoView({ block: 'center', behavior: reduced ? 'auto' : 'smooth' });
    }
}
