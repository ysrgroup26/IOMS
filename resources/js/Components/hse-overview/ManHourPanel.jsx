import { cn } from '@/lib/utils';
import { accentFor } from './accents';
import { Clock, Activity } from 'lucide-react';

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
 * v2.116.0 -- THE RATES MOVED OUT, AND SO DID THE APOLOGY FOR THEM.
 * This panel used to carry a paragraph explaining why LTIFR and TRIR
 * could not be shown. They now have a panel of their own
 * (`FrequencyRatePanel`) that either shows them with their arithmetic or
 * says exactly which input is missing, so the note here would be a second
 * answer to a question already answered one card away. What stays is the
 * EXPOSURE BASE those rates are divided by, which is what this panel has
 * always actually been.
 *
 * `Recordable Injuries YTD` is counted from the recorded classification
 * rather than from the incident CATEGORY. Those are different sets -- a
 * first-aid case is an injury and is not recordable -- and the old count
 * quietly included every first-aid case under a recordable label.
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
                <p className="min-w-0 flex-1 text-[11px] font-semibold leading-tight text-graphite-600 dark:text-slate-300">{label}</p>
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
    return (
        <section className="rounded-2xl border border-graphite-200/80 bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04)] dark:border-slate-800 dark:bg-slate-900">
            <header>
                <h2 className="flex items-center gap-1.5 text-[13px] font-bold text-graphite-900 dark:text-slate-50">
                    <Activity className="h-4 w-4 text-emerald-500" /> Man-Hour &amp; Exposure
                </h2>
                <p className="mt-0.5 text-[11px] text-graphite-400 dark:text-slate-500">
                    Jam kerja tercatat dari modul Man-Hour. Nol dan belum tercatat ditampilkan berbeda.
                </p>
            </header>

            <div className="mt-3 grid grid-cols-2 gap-2">
                <Metric icon={Clock} label="Man-Hour Today" value={manHours?.today} unit="hrs" note="Hari ini" accent="blue" />
                <Metric icon={Clock} label="Man-Hour This Month" value={manHours?.this_month} unit="hrs" note="Awal bulan s/d hari ini" accent="blue" />
                <Metric icon={Clock} label="Man-Hour YTD" value={manHours?.ytd} unit="hrs" note="Awal tahun s/d hari ini" accent="blue" />
                <Metric
                    icon={Activity}
                    label="Recordable Injuries YTD"
                    value={safetyKpi?.recordable_injuries_ytd}
                    unit="cases"
                    note="FAT + LTI + RWC + MTC tahun ini"
                    accent={safetyKpi?.recordable_injuries_ytd > 0 ? 'amber' : 'green'}
                />
            </div>

            <p className="mt-2.5 text-[10.5px] leading-snug text-graphite-400 dark:text-slate-500">
                Jam kerja ini adalah pembagi untuk TRIR dan LTIFR di panel sebelah.
            </p>

        </section>
    );
}
