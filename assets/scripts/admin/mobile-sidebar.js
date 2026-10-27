// EasyAdmin closes the mobile sidebar on a backdrop click only: Escape does the same and gives focus back to the toggler
document.addEventListener('keydown', (event) => {
    if ('Escape' !== event.key || !document.body.classList.contains('ea-mobile-sidebar-visible')) {
        return;
    }

    const backdrop = document.querySelector('body > .modal-backdrop');
    if (backdrop) {
        backdrop.click();
    } else {
        document.body.classList.remove('ea-mobile-sidebar-visible');
    }
    document.querySelector('#navigation-toggler')?.focus();
});
