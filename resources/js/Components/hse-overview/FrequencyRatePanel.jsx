import { cn } from '@/lib/utils';
import { TrendingUp, Info } from 'lucide-react';

/**
 * v2.116.0 -- INCIDENT FREQUENCY RATES, WITH THEIR ARITHMETIC ON SCREEN.
 *
 * TRIR and LTIFR are not counts, which is the whole reason they are not
 * in the Accident Classification panel beside this one. Each divides a
 * number of CASES by exposure HOURS and multiplies by a convention, and
 * every one of those three inputs is a decision somebody can disagree
 * with -- so each is printed next to the rate rather than left implied.
 * A rate whose numerator and denominator are invisible is a number that
 * gets quoted and cannot be checked.
 *
 * ZERO HOURS MEANS NO ANSWER, NOT A ZERO RATE. Dividing by nothing is
 * undefined, and "TRIR 0.00" on a site that has not logged a single hour
 * is the most dangerous output this panel could produce: it reads as a
 * perfect safety record. When the denominator is missing the rates are
 * shown as unavailable with the reason, which is the same rule
 * `sumManHours()` already applies by answering null rather than 0.
 *
 * WHAT THE DENOMINATOR ACTUALLY COVERS is stated under the rates, not
 * buried in a tooltip. IOMS counts man-hours logged against people in the
 * workforce register; hours worked by anyone not in that register, or
 * simply never logged, are not in it. That is the one reservation from
 * ADR 044 that is still true, and a reader comparing this figure against
 * a contractor's own return needs to know it before they do.
 */
function Rate({ rate, available }) {
    const unavailable = !available || rate.value === null || rate.value === undefined;

    return (
        <div className="rounded-xl border border-graphite-200/60 p-3 dark:border-slate-800">
            <div className="flex items-baseline justify-between gap-2">
                <span className="text-[11px] font-bold tracking-wide text-graphite-700 dark:text-slate-200">{rate.code}</span>
                <span className="text-[10px] text-graphite-400 dark:text-slate-500">
                    &times;{rate.multiplier.toLocaleString('en-US')}
                </span>
            </div>

            <p className={cn(
                'mt-1.5 text-[26px] font-bold leading-none tracking-tight',
                unavailable ? 'text-graphite-400 dark:text-slate-500' : 'text-navy-700 dark:text-slate-100'
            )}>
                {unavailable ? '-' : rate.value.toFixed(2)}
            </p>

            <p className="mt-1 text-[10.5px] leading-tight text-graphite-500 dark:text-slate-400">
                {rate.label}
            </p>

            {/* The numerator, always -- it is a real count whether or not
                the rate could be divided out. */}
            <p className="mt-1.5 border-t border-graphite-100 pt-1.5 text-[10px] leading-snug text-graphite-400 dark:border-slate-800 dark:text-slate-500">
                {rate.cases} {rate.cases === 1 ? 'case' : 'cases'} ({rate.cases_label})
            </p>
        </div>
    );
}

export default function FrequencyRatePanel({ rates }) {
    const available = rates?.available === true;
    const manHours = rates?.man_hours;

    return (
        <section className="flex h-full flex-col rounded-2xl border border-graphite-200/70 bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04)] dark:border-slate-800 dark:bg-slate-900">
            <header className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <h2 className="flex items-center gap-1.5 text-[13px] font-bold text-graphite-900 dark:text-slate-50">
                        <TrendingUp className="h-4 w-4 text-blue-500" /> Incident Frequency Rates
                    </h2>
                    <p className="mt-0.5 text-[11px] text-graphite-400 dark:text-slate-500">
                        Kasus dibagi jam paparan, bukan jumlah kasus.
                    </p>
                </div>

                <span className="shrink-0 rounded-md bg-graphite-100 px-2 py-0.5 text-[10px] font-semibold text-graphite-500 dark:bg-slate-800 dark:text-slate-400">
                    {rates?.period_label}
                </span>
            </header>

            <div className="mt-3 grid grid-cols-2 gap-2">
                {(rates?.rates ?? []).map((rate) => (
                    <Rate key={rate.key} rate={rate} available={available} />
                ))}
            </div>

            {available ? (
                <p className="mt-3 border-t border-graphite-100 pt-2.5 text-[10.5px] leading-snug text-graphite-400 dark:border-slate-800 dark:text-slate-500">
                    Pembagi:{' '}
                    <span className="font-semibold text-graphite-600 dark:text-slate-300">
                        {Number(manHours).toLocaleString('en-US')} man-hours
                    </span>{' '}
                    tercatat tahun ini. Jam kerja yang tidak dicatat, atau pekerja yang tidak terdaftar di data karyawan,
                    tidak termasuk di dalamnya.
                </p>
            ) : (
                <div className="mt-3 flex items-start gap-2 rounded-xl border border-dashed border-graphite-200 bg-graphite-50/50 px-3 py-2.5 dark:border-slate-700 dark:bg-slate-800/40">
                    <Info className="mt-px h-3.5 w-3.5 shrink-0 text-graphite-400 dark:text-slate-500" />
                    <p className="text-[11px] leading-snug text-graphite-500 dark:text-slate-400">
                        <span className="font-semibold text-graphite-700 dark:text-slate-200">Rasio belum dapat dihitung.</span>{' '}
                        {rates?.unavailable_reason}
                    </p>
                </div>
            )}
        </section>
    );
}
