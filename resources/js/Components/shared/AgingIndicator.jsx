import { cn } from '@/lib/utils';

/**
 * v2.69.0 -- how long something has been waiting.
 *
 * TWO RULES, both deliberate.
 *
 * 1. THE NUMBER IS ALWAYS SHOWN. The colour is emphasis; the day count is
 *    the fact. "47 days" tells the reader something "Overdue" does not,
 *    and it is what makes the difference between a request held for two
 *    weeks on purpose and one nobody has touched since March. Colour
 *    alone would also fail anyone who cannot distinguish the two tints --
 *    the accessibility rule this codebase already follows for risk
 *    matrices and status badges.
 *
 * 2. AGE IS NOT A STATUS. This renders alongside StatusBadge, never
 *    instead of it. A request can legitimately be old (consolidated on
 *    purpose) or illegitimately old (forgotten), and only the status says
 *    which -- so the two facts are shown as two facts.
 *
 * `level` comes from the server (MaterialRequest::getAgingLevelAttribute)
 * so the thresholds live in one place rather than being re-decided here.
 */
const LEVEL_CLASSES = {
    normal: 'text-graphite-500 dark:text-slate-400',
    attention: 'text-warning dark:text-amber-400 font-medium',
    overdue: 'text-danger dark:text-red-400 font-semibold',
};

export default function AgingIndicator({ days, level, className }) {
    if (days === null || days === undefined) {
        return <span className="text-graphite-300 dark:text-slate-600">—</span>;
    }

    const label = days === 0 ? 'Today' : `${days} ${days === 1 ? 'day' : 'days'}`;

    return (
        <span
            className={cn('whitespace-nowrap text-xs tabular-nums', LEVEL_CLASSES[level] ?? LEVEL_CLASSES.normal, className)}
            // Screen readers get the same two facts sighted users get, in
            // one phrase rather than a bare number.
            title={level === 'overdue' ? 'Open longer than expected' : undefined}
        >
            {label}
            {level === 'overdue' && <span className="sr-only"> — open longer than expected</span>}
        </span>
    );
}
