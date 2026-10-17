import { Controller } from '@hotwired/stimulus';

// Brings the history entry opened by a notification link (or a fresh acceptance) into view.
export default class extends Controller {
    connect() {
        this.element.scrollIntoView({ block: 'center' });
    }
}
