import { cn } from '@/lib/utils';
import { COMPLIANCE, RESULTS, TONE_CLASSES, TONE_DOT } from './compliance';

/** A unit's derived standing. Text and colour both carry the state, so it reads without colour too. */
export default function ComplianceBadge({ state, className }) {
    const meta = COMPLIANCE[state] ?? COMPLIANCE.not_scheduled;

    return (
        <span
            title={meta.hint}
            className={cn('inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border px-2 py-0.5 text-[11px] font-semibold', TONE_CLASSES[meta.tone], className)}
        >
            <span className={cn('h-1.5 w-1.5 rounded-full', TONE_DOT[meta.tone])} aria-hidden="true" />
            {meta.label}
        </span>
    );
}

export function ResultBadge({ result }) {
    const meta = RESULTS[result];
    if (!meta) return <span className="text-graphite-400">Not inspected</span>;

    return (
        <span className={cn('inline-flex items-center whitespace-nowrap rounded-md border px-1.5 py-px text-[11px] font-semibold', TONE_CLASSES[meta.tone])}>
            {meta.label}
        </span>
    );
}
