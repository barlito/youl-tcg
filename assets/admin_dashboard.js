// Admin economy dashboard: a minimal Stimulus app running only the ux-chartjs
// controller, colored from the Violet Arcade admin theme (dark only).
import { Application } from '@hotwired/stimulus';
import ChartController from '@symfony/ux-chartjs';

// categorical order (violet, magenta, green, yellow) + a neutral « expected » gray;
// each one keeps >= 3:1 against the card surface #160e22
const PALETTE = { series: ['#a435f0', '#ff3db0', '#5be584', '#ffd24a'], expected: '#6a5a8a' };
const TEXT = '#b9a8cf';
const GRID = '#a435f022';
const MONO = { family: "'JetBrains Mono', ui-monospace, monospace", size: 11 };

function applyTheme(config) {
    for (const dataset of config.data.datasets) {
        const color = 'expected' === dataset.seriesRole ? PALETTE.expected : PALETTE.series[dataset.seriesSlot ?? 0];
        dataset.backgroundColor = color;
        dataset.borderColor = color;
        dataset.borderWidth = 'line' === (dataset.type ?? config.type) ? 2 : 0;
        dataset.borderRadius = 0;
        dataset.maxBarThickness = 28;
    }

    const options = config.options;
    options.color = TEXT;
    options.plugins ??= {};
    options.plugins.legend ??= {};
    options.plugins.legend.labels = { color: TEXT, boxWidth: 12, boxHeight: 12, font: MONO };

    for (const scale of Object.values(options.scales ?? {})) {
        scale.ticks = { ...(scale.ticks ?? {}), color: TEXT };
        scale.grid = { ...(scale.grid ?? {}), color: GRID };
        scale.border = { display: false };
    }
}

document.addEventListener('chartjs:pre-connect', (event) => applyTheme(event.detail.config));

Application.start().register('symfony--ux-chartjs--chart', ChartController);
