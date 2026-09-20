import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import StatusBadge from '@/Components/shared/StatusBadge';

/**
 * v2.73.0 -- THE LINEAGE OF A RECORD, SHOWN AS A CHAIN.
 *
 * HSE work is not a set of forms, it is a sequence: an event is reported,
 * somebody investigates it, the investigation raises corrective actions,
 * the actions are verified, the whole thing closes. Every one of those is
 * a separate record with its own number -- which is correct, and which is
 * exactly why a person looking at any one of them needs to see where it
 * sits in the chain.
 *
 *   INC-2026-00001  ->  INV-2026-00001  ->  3 corrective actions
 *   Initial Report      Investigation        2 still open
 *   Reported            In Progress
 *
 * WHY THIS EXISTS AS A COMPONENT rather than markup on one page: it is
 * rendered from BOTH ends. The incident shows it looking forward, the
 * investigation shows it looking back, and the two must agree -- a chain
 * that renders differently depending on which link you are standing on is
 * worse than no chain, because it makes people doubt the numbers.
 *
 * WHAT IT IS NOT: a workflow diagram. It shows records that EXIST, never
 * a future step as a greyed-out placeholder. A stage that has not
 * happened is rendered as an absence with a plain explanation and,
 * where the viewer is allowed to start it, an action -- not as a ghost of
 * a record implying one is on its way.
 *
 * `current` marks the link the viewer is already on, so the chain orients
 * rather than invites a pointless navigation back to the same page.
 */
export default function RecordChain({ steps = [], className }) {
    const visible = steps.filter(Boolean);

    if (visible.length === 0) {
        return null;
    }

    return (
        <nav
            aria-label="Record chain"
            className={cn(
                'flex flex-wrap items-stretch gap-x-1 gap-y-2 rounded-xl border border-steel-200/70 bg-gradient-to-br from-steel-100/70 via-white to-white p-2 shadow-panel',
                'dark:border-slate-800 dark:from-slate-900 dark:via-slate-900 dark:to-slate-900',
                className
            )}
        >
            {visible.map((step, index) => (
                <div key={step.key ?? index} className="flex min-w-0 items-stretch">
                    {index > 0 && (
                        <ChevronRight
                            className="mx-0.5 h-4 w-4 shrink-0 self-center text-graphite-300 dark:text-slate-600"
                            aria-hidden="true"
                        />
                    )}
                    <ChainLink {...step} />
                </div>
            ))}
        </nav>
    );
}

function ChainLink({ label, reference, status, meta, href, current, muted, action }) {
    const body = (
        <>
            <span className="block text-[10px] font-semibold uppercase tracking-wide text-graphite-400 dark:text-slate-500">
                {label}
            </span>

            {reference ? (
                <span className="mt-0.5 block truncate font-mono text-[13px] font-semibold tabular-nums text-navy-900 dark:text-slate-100">
                    {reference}
                </span>
            ) : (
                // The absence, stated plainly. Deliberately not a dashed
                // placeholder box shaped like the record that is missing:
                // that reads as "loading" or "coming", and neither is true.
                <span className="mt-0.5 block truncate text-[13px] font-medium text-graphite-400 dark:text-slate-500">
                    Not started
                </span>
            )}

            {status && (
                <span className="mt-1 block">
                    <StatusBadge value={status} />
                </span>
            )}

            {meta && (
                <span className="mt-1 block truncate text-[11px] text-graphite-500 dark:text-slate-400">{meta}</span>
            )}

            {action && <span className="mt-1.5 block">{action}</span>}
        </>
    );

    const shell = cn(
        'min-w-0 max-w-[13rem] rounded-lg px-2.5 py-1.5 text-left transition-colors',
        current && 'bg-navy-900/[0.04] ring-1 ring-inset ring-navy-900/10 dark:bg-slate-800/60 dark:ring-slate-700',
        muted && !current && 'opacity-80'
    );

    // A link only when there is somewhere to go AND the viewer is not
    // already there.
    if (href && !current) {
        return (
            <Link href={href} className={cn(shell, 'hover:bg-steel-100/80 dark:hover:bg-slate-800')}>
                {body}
            </Link>
        );
    }

    return (
        <div className={shell} aria-current={current ? 'page' : undefined}>
            {body}
        </div>
    );
}
