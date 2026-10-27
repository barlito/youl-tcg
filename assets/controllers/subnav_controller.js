import { Controller } from '@hotwired/stimulus';

// Scrolls the current tab of a horizontal sub-navigation into view (narrow screens)
export default class extends Controller {
    connect() {
        const current = this.element.querySelector('[aria-current="page"]');
        if (!current || this.element.scrollWidth <= this.element.clientWidth) {
            return;
        }
        // offsetLeft is relative to the offsetParent, not to this (unpositioned) scroller
        const left = current.getBoundingClientRect().left - this.element.getBoundingClientRect().left + this.element.scrollLeft;
        this.element.scrollLeft = left - (this.element.clientWidth - current.offsetWidth) / 2;
    }
}
