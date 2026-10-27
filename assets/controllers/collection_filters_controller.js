import { Controller } from '@hotwired/stimulus';

// Collection GET filter form: selects submit on change from md, wait for « Appliquer » in the mobile panel
export default class extends Controller {
    static targets = ['toggle', 'panel'];

    connect() {
        // clean urls: no empty or default parameters (?q=&rarete=&tri=univers)
        this.onFormData = (event) => {
            [...event.formData.entries()].forEach(([name, value]) => {
                if (value === '' || (name === 'tri' && value === 'univers')) {
                    event.formData.delete(name);
                }
            });
        };
        this.element.addEventListener('formdata', this.onFormData);
    }

    disconnect() {
        this.element.removeEventListener('formdata', this.onFormData);
    }

    toggle() {
        const open = !this.element.classList.contains('is-open');
        this.element.classList.toggle('is-open', open);
        this.toggleTarget.setAttribute('aria-expanded', String(open));
    }

    submit() {
        if (window.matchMedia('(min-width: 768px)').matches) {
            this.element.requestSubmit();
        }
    }
}
