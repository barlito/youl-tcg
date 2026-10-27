import { Controller } from '@hotwired/stimulus';

// Button + panel disclosure (account menu, mobile « Plus » sheet): Escape, outside click, arrow keys
export default class extends Controller {
    static targets = ['button', 'panel', 'backdrop'];

    connect() {
        this.onDocumentClick = (event) => {
            if (this.isOpen() && !this.element.contains(event.target)) {
                this.close();
            }
        };
        document.addEventListener('click', this.onDocumentClick);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
    }

    toggle(event) {
        event.preventDefault();
        this.isOpen() ? this.close() : this.open();
    }

    open() {
        this.panelTarget.hidden = false;
        if (this.hasBackdropTarget) {
            this.backdropTarget.hidden = false;
        }
        this.buttonTarget.setAttribute('aria-expanded', 'true');
    }

    close({ focusButton = false } = {}) {
        if (!this.isOpen()) {
            return;
        }
        this.panelTarget.hidden = true;
        if (this.hasBackdropTarget) {
            this.backdropTarget.hidden = true;
        }
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        if (focusButton) {
            this.buttonTarget.focus();
        }
    }

    dismiss() {
        this.close();
    }

    keydown(event) {
        if (!this.isOpen()) {
            if (event.key === 'ArrowDown' && event.target === this.buttonTarget) {
                event.preventDefault();
                this.open();
                this.focusItem(0);
            }

            return;
        }

        const items = this.items();
        const index = items.indexOf(document.activeElement);

        switch (event.key) {
            case 'Escape':
                event.preventDefault();
                this.close({ focusButton: true });
                break;
            case 'ArrowDown':
                event.preventDefault();
                this.focusItem(index + 1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                this.focusItem(index <= 0 ? items.length - 1 : index - 1);
                break;
            case 'Home':
                if (index >= 0) {
                    event.preventDefault();
                    this.focusItem(0);
                }
                break;
            case 'End':
                if (index >= 0) {
                    event.preventDefault();
                    this.focusItem(items.length - 1);
                }
                break;
            default:
        }
    }

    focusout(event) {
        if (event.relatedTarget && !this.element.contains(event.relatedTarget)) {
            this.close();
        }
    }

    items() {
        return [...this.panelTarget.querySelectorAll('a[href], button:not([disabled])')];
    }

    focusItem(index) {
        const items = this.items();
        if (items.length > 0) {
            items[(index + items.length) % items.length].focus();
        }
    }

    isOpen() {
        return !this.panelTarget.hidden;
    }
}
