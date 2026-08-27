import ApexCharts from 'apexcharts';

/**
 * Every chart on the dashboard.
 *
 * Data arrives inside a `<script type="application/json">` in the element
 * it belongs to, rather than in a data- attribute: locale codes, theme
 * names and server strings contain quotes and slashes, and one unescaped
 * apostrophe in an attribute would break the page silently.
 */

const PALETTE = ['#465fff', '#12b76a', '#f79009', '#f04438', '#7a5af8', '#06aed4', '#98a2b3'];

function payload(element) {
    const script = element.querySelector('script[type="application/json"]');

    if (!script) {
        return null;
    }

    try {
        return JSON.parse(script.textContent);
    } catch (error) {
        console.error('[dashboard] unreadable chart payload', error);

        return null;
    }
}

function isDark() {
    return document.documentElement.classList.contains('dark');
}

export function renderDonuts() {
    document.querySelectorAll('[data-donut]').forEach((element) => {
        const counts = payload(element);

        if (!counts || Object.keys(counts).length === 0) {
            return;
        }

        new ApexCharts(element, {
            chart: { type: 'donut', height: 200, fontFamily: 'inherit' },
            labels: Object.keys(counts),
            series: Object.values(counts),
            colors: PALETTE,
            legend: { show: false },
            dataLabels: { enabled: false },
            stroke: { width: 0 },
            tooltip: { theme: isDark() ? 'dark' : 'light' },
            plotOptions: { pie: { donut: { size: '70%' } } },
        }).render();
    });
}

export function renderSparklines() {
    document.querySelectorAll('[data-spark]').forEach((element) => {
        const series = payload(element);

        if (!series || series.length === 0) {
            return;
        }

        new ApexCharts(element, {
            chart: { type: 'area', height: 60, sparkline: { enabled: true }, fontFamily: 'inherit' },
            series: [{ name: 'value', data: series }],
            colors: [PALETTE[0]],
            stroke: { curve: 'smooth', width: 2 },
            fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0 } },
            tooltip: { theme: isDark() ? 'dark' : 'light', x: { show: false } },
        }).render();
    });
}
