/**
 * The datagrid scrolls horizontally inside its panel (admin.css): an absolute
 * dropdown would be clipped by that scroll box, a fixed one is not. Bootstrap
 * reads data-bs-popper-config (JSON) when it creates the dropdown on first click.
 */
const FIXED = JSON.stringify({ strategy: 'fixed' });

const fixDropdowns = () => {
    document.querySelectorAll('.datagrid [data-bs-toggle="dropdown"]:not([data-bs-popper-config])').forEach((toggle) => {
        toggle.setAttribute('data-bs-popper-config', FIXED);
    });
};

if ('loading' === document.readyState) {
    document.addEventListener('DOMContentLoaded', fixDropdowns);
} else {
    fixDropdowns();
}
