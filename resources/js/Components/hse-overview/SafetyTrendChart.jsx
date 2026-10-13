import { useMemo, useState } from 'react';
import '@/lib/chartSetup';
import { Line } from 'react-chartjs-2';
import { cn } from '@/lib/utils';
import { SERIES_COLORS } from './accents';
import { TrendingUp, LineChart } from 'lucide-react';

/**
 * v2.109.0 -- TWELVE MONTHS OF HSE ACTIVITY.
 *
 * Four real series -- incidents, observations, CAPA, permits -- counted
 * per month by the controller from each module's own operational date.
 *
 * THE RANGE SELECTOR SLICES DATA THAT IS ALREADY HERE. 3/6/12 months are
 * tails of the same twelve the server sent, so changing range costs no
 * request and can never show a range the data does not cover. It is not a
 * date picker, because the Overview has no query behind one.
 *
 * NO FORECAST, NO TARGET LINE, NO PERCENTAGE. The chart draws the counts
 * and nothing else. A target would be a number nobody set, and a
 * period-over-period percentage from four counts is the kind of figure
 * that gets quoted in a safety meeting after the dashboard that invented
 * it has been forgotten.
 */
const RANGES = [
    { key: 3, label: '3M' },
    { key: 6, label: '6M' },
    { key: 12, label: '12M' },
];

/** `2026-01` -> `Jan`, with the year when a window crosses one. */
function formatMonth(key, showYear) {
    const [year, month] = key.split('-').map(Number);
    const date = new Date(year, month - 1, 1);
    return date.toLocaleDateString('en-US', showYear ? { month: 'short', year: '2-digit' } : { month: 'short' });
}

export default function SafetyTrendChart({ trend }) {
    const [months, setMonths] = useState(12);

    const hasData = trend?.has_data === true;
    const allLabels = trend?.labels ?? [];
    const series = trend?.series ?? [];

    const data = useMemo(() => {
        const labels = allLabels.slice(-months);
        const crossesYears = new Set(labels.map((l) => l.slice(0, 4))).size > 1;

        return {
            labels: labels.map((l) => formatMonth(l, crossesYears)),
            datasets: series.map((s, index) => ({
                label: s.label,
                data: s.data.slice(-months),
                borderColor: SERIES_COLORS[s.key] ?? '#64748b',
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 4,
                /*
                 * v2.116.0 -- LESS SMOOTHING, ON PURPOSE.
                 *
                 * These are monthly COUNTS, so the only real values are
                 * the twelve points. A high tension draws a curve that
                 * passes through invented intermediate values and, on a
                 * series that goes 0, 2, 0, overshoots below zero -- a
                 * safety chart implying a negative number of incidents.
                 * 0.2 keeps the line readable without asserting anything
                 * between two months.
                 */
                tension: 0.2,
                fill: index === 0,
                backgroundColor: index === 0 ? 'rgba(239, 68, 68, 0.07)' : (SERIES_COLORS[s.key] ?? '#64748b'),
            })),
        };
    }, [allLabels, series, months]);

    const options = useMemo(() => ({
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    boxWidth: 8,
                    boxHeight: 8,
                    usePointStyle: true,
                    pointStyle: 'circle',
                    padding: 14,
                    font: { size: 11 },
                    color: '#64748b',
                },
            },
            tooltip: {
                backgroundColor: '#0f172a',
                padding: 10,
                cornerRadius: 8,
                titleFont: { size: 11 },
                bodyFont: { size: 11 },
            },
        },
        scales: {
            x: {
                grid: { display: false },
                border: { display: false },
                ticks: { font: { size: 10 }, color: '#94a3b8' },
            },
            y: {
                beginAtZero: true,
                border: { display: false },
                grid: { color: 'rgba(148,163,184,0.16)' },
                // Counts are whole records; a y-axis reading 0.5 incidents
                // is not a thing that can happen.
                ticks: { font: { size: 10 }, color: '#94a3b8', precision: 0, stepSize: 1 },
            },
        },
    }), []);

    return (
        <section className="flex h-full flex-col rounded-2xl border border-graphite-200/80 bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04)] dark:border-slate-800 dark:bg-slate-900">
            <header className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <h2 className="flex items-center gap-1.5 text-[13px] font-bold text-graphite-900 dark:text-slate-50">
                        <TrendingUp className="h-4 w-4 text-blue-500" /> HSE Activity Trend
                    </h2>
                    <p className="mt-0.5 text-[11px] text-graphite-400 dark:text-slate-500">
                        Jumlah catatan per bulan, dihitung dari tanggal operasional masing-masing modul.
                    </p>
                </div>

                {hasData && (
                    <div className="flex shrink-0 items-center gap-0.5 rounded-lg bg-graphite-100 p-0.5 dark:bg-slate-800">
                        {RANGES.map((r) => (
                            <button
                                key={r.key}
                                type="button"
                                onClick={() => setMonths(r.key)}
                                className={cn(
                                    'rounded-md px-2 py-1 text-[11px] font-semibold transition-colors',
                                    months === r.key
                                        ? 'bg-white text-graphite-900 shadow-sm dark:bg-slate-700 dark:text-slate-50'
                                        : 'text-graphite-500 hover:text-graphite-700 dark:text-slate-400'
                                )}
                            >
                                {r.label}
                            </button>
                        ))}
                    </div>
                )}
            </header>

            {hasData ? (
                <div className="mt-3 h-[220px] min-h-0 flex-1">
                    <Line data={data} options={options} />
                </div>
            ) : (
                /* An empty system is told so, rather than shown twelve
                   months of flat zero -- which reads as "nothing went
                   wrong" when it actually means "nothing was recorded". */
                <div className="mt-3 flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed border-graphite-200 px-4 py-8 text-center dark:border-slate-700">
                    <LineChart className="h-6 w-6 text-graphite-300 dark:text-slate-600" />
                    <p className="mt-2 text-[12px] font-semibold text-graphite-700 dark:text-slate-200">Belum ada data tren</p>
                    <p className="mt-1 max-w-xs text-[11px] leading-snug text-graphite-400 dark:text-slate-500">
                        Grafik akan terisi setelah ada insiden, observasi, CAPA, atau izin kerja yang tercatat
                        dalam 12 bulan terakhir.
                    </p>
                </div>
            )}
        </section>
    );
}
