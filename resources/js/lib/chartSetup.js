import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    LineElement,
    PointElement,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';

ChartJS.register(
    CategoryScale,
    LinearScale,
    BarElement,
    LineElement,
    PointElement,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler
);

export const CHART_COLORS = [
    '#2563eb', '#0ea5e9', '#14b8a6', '#f59e0b', '#ef4444',
    '#8b5cf6', '#ec4899', '#22c55e', '#64748b',
];

/*
 * v2.140.0 -- CHARTS AT NIGHT (ADR 082).
 *
 * Chart.js paints on a canvas, so no stylesheet reaches its labels or grid:
 * they come from ChartJS.defaults (#666 text, 10%-black lines). Those are
 * captured once as the LIGHT values and swapped for Midnight ones while
 * <html> carries `dark`; a switch redraws every open chart. Series colours
 * are never touched -- they carry meaning. Light Mode keeps Chart.js's own
 * defaults exactly, because they are restored from what was captured.
 */
const LIGHT = {
    color: ChartJS.defaults.color,
    borderColor: ChartJS.defaults.borderColor,
    tooltipBg: ChartJS.defaults.plugins.tooltip.backgroundColor,
    tooltipBorder: ChartJS.defaults.plugins.tooltip.borderColor,
    tooltipBorderWidth: ChartJS.defaults.plugins.tooltip.borderWidth,
};
const MIDNIGHT = {
    color: '#A5B3C5',
    borderColor: 'rgba(52, 70, 90, 0.7)',
    tooltipBg: 'rgba(26, 40, 57, 0.96)',
    tooltipBorder: 'rgba(150, 180, 220, 0.18)',
    tooltipBorderWidth: 1,
};

function applyChartTheme() {
    if (typeof document === 'undefined') return;
    const t = document.documentElement.classList.contains('dark') ? MIDNIGHT : LIGHT;
    ChartJS.defaults.color = t.color;
    ChartJS.defaults.borderColor = t.borderColor;
    ChartJS.defaults.plugins.tooltip.backgroundColor = t.tooltipBg;
    ChartJS.defaults.plugins.tooltip.borderColor = t.tooltipBorder;
    ChartJS.defaults.plugins.tooltip.borderWidth = t.tooltipBorderWidth;
    Object.values(ChartJS.instances).forEach((chart) => chart.update('none'));
}

if (typeof window !== 'undefined') {
    applyChartTheme();
    let wasDark = document.documentElement.classList.contains('dark');
    new MutationObserver(() => {
        const dark = document.documentElement.classList.contains('dark');
        if (dark !== wasDark) {
            wasDark = dark;
            applyChartTheme();
        }
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
}

export default ChartJS;
