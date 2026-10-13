import { Link } from '@inertiajs/react';
import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { accentFor } from './accents';
import { ChevronRight } from 'lucide-react';

/**
 * v2.116.0 -- ONE OPERATIONAL KPI, WITH THE BREAKDOWN THAT EXPLAINS IT.
 *
 * The five cards across the top of the Overview answer "what is open
 * right now". Each shows the count, and under it the two parts that count
 * is made of -- "1 active, 0 past end time" -- because a bare number on a
 * safety dashboard invites the reader to guess what it includes.
 *
 * THE DETAIL ALWAYS PARTITIONS THE VALUE, and that is a server-side
 * guarantee (see `HseDashboardController::operationalKpis()`), not a
 * formatting convention here. A breakdown that counted a different set
 * would read as one and not be one.
 *
 * NO TREND ARROW AND NO SPARKLINE, which is the one place this card
 * departs from the approved reference. Both would have to describe how
 * this number moved, and IOMS stores no history of "how many were open
 * last month" for any of the five -- the twelve-month series this page
 * does have counts records RAISED per month, which is a different measure
 * and would be a lie drawn next to this value. v2.109.0 made the same
 * call for the same reason; an invented percentage is the one thing a
 * safety KPI must never carry.
 *
 * GREEN IS A CLAIM. Inherited from `KpiTile` and from `StatCard` before
 * it: before IOMS is monitoring anything, a zero is grey rather than
 * healthy, because a screen of green zeroes on an empty database reads as
 * an all-clear.
 */
export default function OperationalKpiCard({ icon: Icon, label, value, detail = [], href, accent = 'blue', alert = false }) {
    const { readiness } = usePage().props;
    const notYetOperational = readiness ? readiness.is_operational === false : false;

    const effective = notYetOperational ? 'slate' : accent;
    const tone = accentFor(effective);

    const body = (
        <>
            <div className="flex items-start justify-between gap-2">
                <span className={cn('inline-flex h-11 w-11 items-center justify-center rounded-2xl ring-1', tone.icon)}>
                    <Icon className="h-[21px] w-[21px]" />
                </span>

                {href && (
                    <span className="inline-flex h-6 w-6 items-center justify-center rounded-lg text-graphite-300 transition-colors group-hover:bg-graphite-50 group-hover:text-graphite-500 dark:text-slate-600 dark:group-hover:bg-slate-800">
                        <ChevronRight className="h-4 w-4" />
                    </span>
                )}
            </div>

            <p className={cn('mt-3 text-[30px] font-bold leading-none tracking-tight', tone.value)}>{value}</p>

            <p className="mt-2 text-[13px] font-semibold leading-tight text-graphite-800 dark:text-slate-100">{label}</p>

            {detail.length > 0 && (
                <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-[11px] leading-snug text-graphite-400 dark:text-slate-500">
                    {detail.map((part, index) => (
                        <span key={part.label} className="whitespace-nowrap">
                            {index > 0 && <span className="mr-1.5 text-graphite-300 dark:text-slate-700">&middot;</span>}
                            <span className={cn(
                                'font-semibold',
                                alert && index === detail.length - 1 && part.value > 0
                                    ? 'text-red-600 dark:text-red-300'
                                    : 'text-graphite-600 dark:text-slate-300'
                            )}>
                                {part.value}
                            </span>{' '}
                            {part.label}
                        </span>
                    ))}
                </p>
            )}
        </>
    );

    const shell = cn(
        'group relative flex h-full flex-col rounded-2xl border border-graphite-200/70 bg-white p-4',
        'shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-all duration-200',
        'dark:border-slate-800 dark:bg-slate-900',
        href && 'hover:-translate-y-0.5 hover:border-graphite-300 hover:shadow-[0_10px_28px_rgba(16,24,40,0.08)] dark:hover:border-slate-700'
    );

    if (!href) {
        return <div className={shell}>{body}</div>;
    }

    return <Link href={href} className={shell}>{body}</Link>;
}
