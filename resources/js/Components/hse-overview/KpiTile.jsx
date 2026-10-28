import { Link } from '@inertiajs/react';
import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { accentFor } from './accents';
import { ArrowUpRight } from 'lucide-react';

/**
 * v2.109.0 -- ONE SAFETY KPI.
 *
 * A coloured icon container, a prominent value, a readable label, and an
 * optional one-line reading underneath. Navigates only when it is given a
 * destination, so a tile is never a dead click.
 *
 * GREEN IS A CLAIM, AND THE RULE IS INHERITED DELIBERATELY. The shared
 * `StatCard` learned this in v2.39.0 after a browser session on an empty
 * tenant found six KPI cards reading 0 in the healthy colour on a database
 * with no records in it. `readiness.is_operational` carries the
 * distinction, it is a globally shared Inertia prop, and this tile applies
 * exactly the same downgrade: before IOMS is monitoring anything, a zero
 * is grey, not green. Re-implemented here rather than imported because
 * StatCard's markup is shared with every other module and this redesign
 * must not change how those render.
 */
export default function KpiTile({ icon: Icon, value, label, reading, accent = 'blue', href }) {
    const { readiness } = usePage().props;
    const notYetOperational = readiness ? readiness.is_operational === false : false;
    const effective = notYetOperational && accent === 'green' ? 'slate' : accent;
    const tone = accentFor(effective);

    const body = (
        <>
            <div className="flex items-start justify-between gap-2">
                <span className={cn('inline-flex h-9 w-9 items-center justify-center rounded-xl ring-1', tone.icon)}>
                    <Icon className="h-[18px] w-[18px]" />
                </span>
                {href && (
                    <ArrowUpRight className="h-3.5 w-3.5 shrink-0 text-graphite-300 transition-colors group-hover:text-graphite-500 dark:text-slate-600" />
                )}
            </div>

            <p className={cn('mt-3 text-[26px] font-bold leading-none tracking-tight', tone.value)}>{value}</p>

            <p className="mt-1.5 text-[12px] font-semibold leading-tight text-graphite-700 dark:text-slate-200">{label}</p>

            {/* Only rendered when the caller has something true to say.
                There is no trend line here: the Overview has no historical
                comparison for these six counts, and an invented percentage
                is the one thing a safety KPI must never carry. */}
            {reading && <p className="mt-0.5 text-[11px] leading-snug text-graphite-400 dark:text-slate-500">{reading}</p>}
        </>
    );

    const shell = cn(
        'group relative flex h-full flex-col surface p-4',
        'shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-all duration-200',
        'dark:border-slate-800 dark:bg-slate-900',
        href && 'hover:-translate-y-0.5 hover:border-graphite-300 hover:shadow-[0_8px_24px_rgba(16,24,40,0.08)] dark:hover:border-slate-700'
    );

    if (!href) {
        return <div className={shell}>{body}</div>;
    }

    return <Link href={href} className={shell}>{body}</Link>;
}
