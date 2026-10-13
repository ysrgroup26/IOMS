import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { ShieldAlert, Info } from 'lucide-react';

/**
 * v2.116.0 -- ACCIDENT CLASSIFICATION: HOW MANY CASES OF EACH KIND.
 *
 * FIVE COUNTS, ORDERED BY CONSEQUENCE, AND THEY ARE COUNTS. This panel
 * deliberately shows no rate and no percentage. A classification and a
 * frequency rate are different measures with different denominators, and
 * a reader who sees them in one block of numbers will eventually quote
 * one as the other -- which is why the Incident Frequency Rates panel is
 * a separate card beside this one rather than four more tiles in it.
 *
 * EVERY VALUE IS A CASE COUNT FROM `incidents.injury_severity`, the field
 * the person filing the report selected. IOMS does not infer a
 * classification: an incident with no severity recorded is counted in
 * none of the five and is reported underneath as unclassified, because a
 * gap in the data is the reason a count may look lower than it should and
 * hiding it is how a dashboard becomes reassuring instead of true.
 *
 * COLOUR IS CONSEQUENCE, NOT DECORATION. Red for a fatality and a lost
 * time injury, amber for restricted work and medical treatment, blue for
 * first aid -- and every one of them greys out at zero, so a row of
 * zeroes cannot be read as a row of findings.
 */
const CLASS_TONE = {
    fat: { on: 'bg-red-50 text-red-700 ring-red-100 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/20', value: 'text-red-700 dark:text-red-300' },
    lti: { on: 'bg-red-50 text-red-700 ring-red-100 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/20', value: 'text-red-700 dark:text-red-300' },
    rwc: { on: 'bg-amber-50 text-amber-700 ring-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20', value: 'text-amber-700 dark:text-amber-300' },
    mtc: { on: 'bg-amber-50 text-amber-700 ring-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20', value: 'text-amber-700 dark:text-amber-300' },
    fac: { on: 'bg-blue-50 text-blue-700 ring-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20', value: 'text-blue-700 dark:text-blue-300' },
};

const NEUTRAL = {
    on: 'bg-graphite-100 text-graphite-500 ring-graphite-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700',
    value: 'text-graphite-400 dark:text-slate-500',
};

export default function AccidentClassificationPanel({ classification }) {
    const classes = classification?.classes ?? [];
    const unclassified = classification?.unclassified ?? 0;

    return (
        <section className="flex h-full flex-col rounded-2xl border border-graphite-200/70 bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04)] dark:border-slate-800 dark:bg-slate-900">
            <header className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <h2 className="flex items-center gap-1.5 text-[13px] font-bold text-graphite-900 dark:text-slate-50">
                        <ShieldAlert className="h-4 w-4 text-red-500" /> Accident Classification
                    </h2>
                    <p className="mt-0.5 text-[11px] text-graphite-400 dark:text-slate-500">
                        Jumlah kasus menurut klasifikasi cedera yang dicatat pelapor.
                    </p>
                </div>

                <span className="shrink-0 rounded-md bg-graphite-100 px-2 py-0.5 text-[10px] font-semibold text-graphite-500 dark:bg-slate-800 dark:text-slate-400">
                    {classification?.period_label}
                </span>
            </header>

            <div className="mt-3 grid grid-cols-5 gap-2">
                {classes.map((item) => {
                    const tone = item.value > 0 ? (CLASS_TONE[item.key] ?? NEUTRAL) : NEUTRAL;

                    return (
                        <div
                            key={item.key}
                            title={`${item.label} - ${item.value} case(s)`}
                            className="rounded-xl border border-graphite-200/60 p-2.5 text-center dark:border-slate-800"
                        >
                            <span className={cn('inline-flex rounded-md px-1.5 py-0.5 text-[10px] font-bold tracking-wide ring-1', tone.on)}>
                                {item.code}
                            </span>
                            <p className={cn('mt-1.5 text-[22px] font-bold leading-none tracking-tight', tone.value)}>
                                {item.value}
                            </p>
                            <p className="mt-1 text-[10px] leading-tight text-graphite-400 dark:text-slate-500">
                                {item.short_label ?? item.label}
                            </p>
                        </div>
                    );
                })}
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-graphite-100 pt-2.5 text-[11px] dark:border-slate-800">
                <span className="text-graphite-500 dark:text-slate-400">
                    Recordable (FAT + LTI + RWC + MTC):{' '}
                    <span className="font-semibold text-graphite-800 dark:text-slate-100">{classification?.recordable ?? 0}</span>
                </span>
                <span className="text-graphite-500 dark:text-slate-400">
                    Total cases:{' '}
                    <span className="font-semibold text-graphite-800 dark:text-slate-100">{classification?.total_cases ?? 0}</span>
                </span>
            </div>

            {unclassified > 0 && (
                <Link
                    href={route('incidents.index', { category: 'injury' })}
                    className="mt-2 flex items-start gap-2 rounded-xl border border-dashed border-amber-200 bg-amber-50/50 px-3 py-2 transition-colors hover:bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/5"
                >
                    <Info className="mt-px h-3.5 w-3.5 shrink-0 text-amber-500" />
                    <p className="text-[11px] leading-snug text-graphite-600 dark:text-slate-300">
                        <span className="font-semibold text-graphite-800 dark:text-slate-100">{unclassified} laporan cedera belum diklasifikasi.</span>{' '}
                        Selama klasifikasinya kosong, kasus itu tidak masuk hitungan mana pun di atas maupun ke dalam rasio di sebelah.
                    </p>
                </Link>
            )}
        </section>
    );
}
