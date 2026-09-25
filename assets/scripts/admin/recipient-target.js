/**
 * Recipient targeting (RecipientTargetType): the player picker only shows
 * when "Une sélection de joueurs" is checked, and the fields listed in
 * data-recipient-target-notify-only only when a notification is sent at all.
 * Plain script: the admin does not boot the app's Stimulus controllers.
 */
function refresh(block) {
    const checked = block.querySelector('[data-recipient-target-mode] input:checked');
    const mode = checked ? checked.value : null;

    block.querySelectorAll('[data-recipient-target-recipients]').forEach((row) => {
        row.hidden = 'selection' !== mode;
    });

    block.querySelectorAll('[data-recipient-target-notify-only]').forEach((row) => {
        row.hidden = 'none' === mode;
    });
}

document.addEventListener('change', (event) => {
    const block = event.target.closest('[data-recipient-target]');

    if (block && event.target.closest('[data-recipient-target-mode]')) {
        refresh(block);
    }
});

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-recipient-target]').forEach(refresh);
});
