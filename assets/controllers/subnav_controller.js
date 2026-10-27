import { Controller } from '@hotwired/stimulus';

// Scrolls the current tab of a horizontal sub-navigation into view (narrow screens)
export default class extends Controller {
    connect() {
        const current = this.element.querySelector('[aria-current="page"]');
        if (!current || this.element.scrollWidth <= this.element.clientWidth) {
            return;
        }
        this.element.scrollLeft = current.offsetLeft - (this.element.clientWidth - current.offsetWidth) / 2;
    }
}
