// The datagrid scrolls inside its panel, which would clip an absolute dropdown: make them fixed
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
