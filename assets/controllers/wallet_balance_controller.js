import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['amount'];

    update(event) {
        this.amountTarget.textContent = event.detail.formatted;
        this.element.removeAttribute('title');
    }
}
