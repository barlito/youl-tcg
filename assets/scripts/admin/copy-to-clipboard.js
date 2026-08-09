/**
 * Copy-to-clipboard for any admin element carrying data-copy-value.
 *
 * Plain delegated listener rather than a Stimulus controller: the admin pages
 * load EasyAdmin's own Stimulus application, not the app's, so a controller
 * dropped in assets/controllers would never boot here.
 */
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-copy-value]');

    if (null === button) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    copy(button.dataset.copyValue).then((copied) => feedback(button, copied));
});

async function copy(text) {
    // navigator.clipboard only exists in a secure context: keep the legacy
    // path so the button still works over plain http (local, tunnels…)
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch {
            return false;
        }
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();

    let copied = false;
    try {
        copied = document.execCommand('copy');
    } catch {
        copied = false;
    }

    textarea.remove();

    return copied;
}

function feedback(button, copied) {
    if (button.dataset.copyBusy) {
        return;
    }

    const original = button.innerHTML;
    button.dataset.copyBusy = '1';
    button.innerHTML = copied
        ? '<i class="fa fa-check text-success"></i>'
        : '<i class="fa fa-xmark text-danger"></i>';

    window.setTimeout(() => {
        button.innerHTML = original;
        delete button.dataset.copyBusy;
    }, 1200);
}
