// The datagrid scrolls inside its panel, which would clip an absolute dropdown: make them fixed
const FIXED = JSON.stringify({ strategy: 'fixed' });

const fixDropdowns = () => {
    document.querySelectorAll('.datagrid [data-bs-toggle="dropdown"]:not([data-bs-popper-config])').forEach((toggle) => {
        toggle.setAttribute('data-bs-popper-config', FIXED);
    });
};

// a fixed menu would stay put while its row slides away: close it on a horizontal scroll of the panel
const lastScrollLeft = new WeakMap();

const closeMenusOnHorizontalScroll = (event) => {
    const panel = event.target;
    if (!(panel instanceof Element) || !panel.matches('.content-body:has(> table.datagrid)')) {
        return;
    }

    const previous = lastScrollLeft.get(panel) ?? 0;
    lastScrollLeft.set(panel, panel.scrollLeft);
    if (previous === panel.scrollLeft || undefined === window.bootstrap) {
        return;
    }

    panel.querySelectorAll('.datagrid [data-bs-toggle="dropdown"].show').forEach((toggle) => {
        window.bootstrap.Dropdown.getInstance(toggle)?.hide();
    });
};

if ('loading' === document.readyState) {
    document.addEventListener('DOMContentLoaded', fixDropdowns);
} else {
    fixDropdowns();
}

// scroll does not bubble: listen in the capture phase
document.addEventListener('scroll', closeMenusOnHorizontalScroll, { capture: true, passive: true });
