// Admin economy dashboard: a minimal Stimulus app running only the ux-chartjs
// controller, colored from the EasyAdmin light/dark theme.
import { Application } from '@hotwired/stimulus';
import ChartController from '@symfony/ux-chartjs';

// categorical order (blue, orange, aqua, yellow) + a neutral « expected » gray
const PALETTES = {
    light: { series: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100'], expected: '#b4b3ac' },
    dark: { series: ['#3987e5', '#d95926', '#199e70', '#c98500'], expected: '#6b6a64' },
};

const charts = new Set();

const isDark = () => document.body.classList.contains('ea-dark-scheme');

const cssVar = (name, fallback) => getComputedStyle(document.body).getPropertyValue(name).trim() || fallback;

function applyTheme(config) {
    const palette = isDark() ? PALETTES.dark : PALETTES.light;
    const text = cssVar('--text-muted', isDark() ? '#a1a1aa' : '#6b7280');
    const grid = cssVar('--border-color', isDark() ? '#3f3f46' : '#e5e7eb');

    for (const dataset of config.data.datasets) {
        const color = 'expected' === dataset.seriesRole ? palette.expected : palette.series[dataset.seriesSlot ?? 0];
        dataset.backgroundColor = color;
        dataset.borderColor = color;
        dataset.borderWidth = 'line' === config.type ? 2 : 0;
        dataset.borderRadius = 'bar' === config.type ? 4 : 0;
        dataset.maxBarThickness = 28;
    }

    const options = config.options;
    options.color = text;
    options.plugins ??= {};
    options.plugins.legend ??= {};
    options.plugins.legend.labels = { color: text, boxWidth: 12, boxHeight: 12 };

    for (const scale of Object.values(options.scales ?? {})) {
        scale.ticks = { ...(scale.ticks ?? {}), color: text };
        scale.grid = { ...(scale.grid ?? {}), color: grid };
        scale.border = { display: false };
    }
}

document.addEventListener('chartjs:pre-connect', (event) => applyTheme(event.detail.config));
document.addEventListener('chartjs:connect', (event) => charts.add(event.detail.chart));
document.addEventListener('chartjs:disconnect', (event) => charts.delete(event.detail.chart));

// EasyAdmin toggles the scheme with a body class: repaint on switch
new MutationObserver(() => {
    for (const chart of charts) {
        applyTheme(chart.config);
        chart.update('none');
    }
}).observe(document.body, { attributes: true, attributeFilter: ['class'] });

Application.start().register('symfony--ux-chartjs--chart', ChartController);
