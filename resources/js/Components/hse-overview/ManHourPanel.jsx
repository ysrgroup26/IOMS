import { cn } from '@/lib/utils';
import { accentFor } from './accents';
import { Clock, Activity, Info } from 'lucide-react';

/**
 * v2.109.0 -- MAN-HOURS AND THE SAFETY KPI FOUNDATION.
 *
 * ZERO AND "NOT RECORDED" ARE DIFFERENT FACTS, AND THIS PANEL EXISTS TO
 * KEEP THEM APART. `DashboardStatsService::sumManHours()` returns `null`
 * -- not 0 -- when no Man-Hour rows exist for a period, precisely so a
 * system nobody has logged hours into cannot be rendered as a site that
 * worked zero hours. A null prints an em dash in grey with "Belum
 * tercatat" underneath; a real 0 prints 0.
 *
 * LTI / LTIFR / TRIR ARE ABSENT, AND THAT IS THE CORRECT OUTPUT. Both
 * rates divide recordable or lost-time cases by exposure hours. IOMS
 * records neither lost-time days nor a statutory recordability
 * classification, and its man-hours cover employees rather than everyone
 * on site, so every input to those formulas would have to be invented.
 * `safetyKpi.lost_time_metrics_available` has been false since v1.11.6
 * and this panel says plainly what is missing instead of printing a
 * confident number -- a rate from a partial denominator is worse than no
 * rate, because it gets quoted.
 */
function Metric({ icon: Icon, label, value, unit, note, accent = 'blue' }) {
    const unavailable = value === null || value === undefined;
    const tone = accentFor(unavailable ? 'slate' : accent);

    return (
        <div className="rounded-xl border border-graphite-200/70 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-center gap-2">
                <span className={cn('inline-flex h-7 w-7 items-center justify-center rounded-lg ring-1', tone.icon)}>
                    <Icon className="h-3.5 w-3.5" />
                </span>
                <p className="min-w-0 flex-1 truncate text-[11px] font-semibold text-graphite-600 dark:text-slate-300">{label}</p>
            </div>

            <p className="mt-2 flex items-baseline gap-1">
                <span className={cn('text-[20px] font-bold leading-none tracking-tight', tone.value)}>
                    {unavailable ? '-' : Number(value).toLocaleString('en-US')}
                </span>
                {!unavailable && unit && (
                    <span className="text-[11px] font-medium text-graphite-400 dark:text-slate-500">{unit}</span>
                )}
            </p>

            <p className="mt-1 text-[10.5px] leading-snug text-graphite-400 dark:text-slate-500">
                {unavailable ? 'Belum tercatat' : note}
            </p>
        </div>
    );
}

export default function ManHourPanel({ manHours, safetyKpi }) {
    const ratesAvailable = safetyKpi?.lost_time_metrics_available === true;

    return (
        <section className="rounded-2xl border border-graphite-200/80 bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04)] dark:border-slate-800 dark:bg-slate-900">
            <header>
                <h2 className="flex items-center gap-1.5 text-[13px] font-bold text-graphite-900 dark:text-slate-50">
                    <Activity className="h-4 w-4 text-emerald-500" /> Man-Hour &amp; Safety KPI Foundation
                </h2>
                <p className="mt-0.5 text-[11px] text-graphite-400 dark:text-slate-500">
                    Jam kerja tercatat dari modul Man-Hour. Rasio yang memerlukan data belum tercatat ditampilkan apa adanya.
                </p>
            </header>

            <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <Metric icon={Clock} label="Man-Hour Today" value={manHours?.today} unit="hrs" note="Hari ini" accent="blue" />
                <Metric icon={Clock} label="Man-Hour This Month" value={manHours?.this_month} unit="hrs" note="Awal bulan s/d hari ini" accent="blue" />
                <Metric icon={Clock} label="Man-Hour YTD" value={manHours?.ytd} unit="hrs" note="Awal tahun s/d hari ini" accent="blue" />
                <Metric
                    icon={Activity}
                    label="Recordable Injuries YTD"
                    value={safetyKpi?.recordable_injuries_ytd}
                    unit="cases"
                    note="Insiden kategori cedera tahun ini"
                    accent={safetyKpi?.recordable_injuries_ytd > 0 ? 'amber' : 'green'}
                />
            </div>

            {/* The honest absence, stated once rather than as three empty
                tiles pretending to be metrics. */}
            {!ratesAvailable && (
                <div className="mt-2 flex items-start gap-2 rounded-xl border border-dashed border-graphite-200 bg-graphite-50/50 px-3 py-2.5 dark:border-slate-700 dark:bg-slate-800/40">
                    <Info className="mt-px h-3.5 w-3.5 shrink-0 text-graphite-400 dark:text-slate-500" />
                    <p className="text-[11px] leading-snug text-graphite-500 dark:text-slate-400">
                        <span className="font-semibold text-graphite-700 dark:text-slate-200">LTI, LTIFR dan TRIR belum tersedia.</span>{' '}
                        IOMS belum mencatat hari hilang kerja, klasifikasi recordable, maupun jam paparan kontraktor:
                        tanpa itu rasionya hanya bisa ditebak, dan angka tebakan lebih berbahaya daripada tidak ada angka.
                    </p>
                </div>
            )}
        </section>
    );
}
