import { Controller } from '@hotwired/stimulus';

// Native <details> popover: any click (panel included, outside too) or Escape closes it
export default class extends Controller {
    connect() {
        this.onDocumentClick = (event) => {
            if (this.element.open && !this.element.querySelector('summary').contains(event.target)) {
                this.element.open = false;
            }
        };
        this.onKeydown = (event) => {
            if (event.key === 'Escape' && this.element.open) {
                this.element.open = false;
                this.element.querySelector('summary').focus();
            }
        };
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onKeydown);
    }
}
