import { cn } from '@/lib/utils';

/**
 * v2.73.0 -- FACTS ON A RECORD, SET AS A RECORD RATHER THAN AS PROSE.
 *
 * The HSE detail pages had all drifted to the same shape: a card, then a
 * column of `<span>LABEL</span><p>value</p>` pairs, one under another,
 * for as long as the model had fields. That reads as a printed document
 * pasted into a browser -- every fact the same size, the same weight and
 * the same distance from its neighbour, so nothing tells you which of the
 * twenty things on screen is the one that matters.
 *
 * WHAT THIS CHANGES. Facts are laid out as a GRID of labelled values,
 * dense enough to scan in one pass, with three things the paragraph
 * version could not express:
 *
 *   - `emphasis` -- a field that carries the weight of the record (the
 *     injury outcome, the risk level) is set larger and darker, because
 *     on a record about somebody getting hurt those are not peers of
 *     "operating unit";
 *   - `span` -- a chronology needs the full width and a date does not,
 *     and forcing both into one column wastes the page on one and
 *     cramps the other;
 *   - a deliberate EMPTY rendering. `-` is the honest answer for a fact
 *     that was not recorded, and it must look different from a fact whose
 *     value happens to be short.
 *
 * Built as `<dl>` / `<dt>` / `<dd>` because that is what this is, and a
 * screen reader reading "Body Part, Left hand" is the whole point.
 *
 * NOT A CARD. Cards are for separable objects; this is the inside of one
 * section. Wrap it in whatever the page's own section pattern is.
 */
export function FieldGrid({ columns = 2, children, className }) {
    return (
        <dl
            className={cn(
                'grid grid-cols-1 gap-x-6 gap-y-3.5',
                columns === 2 && 'sm:grid-cols-2',
                columns === 3 && 'sm:grid-cols-2 lg:grid-cols-3',
                columns === 4 && 'sm:grid-cols-2 lg:grid-cols-4',
                className
            )}
        >
            {children}
        </dl>
    );
}

export function Field({ label, value, hint, span = 1, emphasis = false, mono = false, className }) {
    // `0`, and `false` are legitimate values; only null/undefined/'' are
    // absent. `value === 0` rendering as "-" would be a real defect on a
    // field like "people injured".
    const isEmpty = value === null || value === undefined || value === '';

    return (
        <div
            className={cn(
                'min-w-0',
                span === 2 && 'sm:col-span-2',
                span === 3 && 'sm:col-span-2 lg:col-span-3',
                span === 4 && 'sm:col-span-2 lg:col-span-4',
                className
            )}
        >
            <dt className="text-[10px] font-semibold uppercase tracking-wide text-graphite-400 dark:text-slate-500">
                {label}
            </dt>
            <dd
                className={cn(
                    'mt-0.5 whitespace-pre-wrap break-words',
                    mono && 'font-mono tabular-nums',
                    emphasis
                        ? 'text-[15px] font-semibold leading-snug text-navy-900 dark:text-slate-50'
                        : 'text-[13px] leading-relaxed text-graphite-800 dark:text-slate-200',
                    isEmpty && 'font-normal text-graphite-300 dark:text-slate-600'
                )}
            >
                {isEmpty ? '—' : value}
            </dd>
            {hint && <p className="mt-0.5 text-[11px] leading-snug text-graphite-500 dark:text-slate-500">{hint}</p>}
        </div>
    );
}

/**
 * A titled band within a detail page.
 *
 * Deliberately a RULE AND A LABEL rather than another card: an HSE record
 * has six or seven groups of facts, and six or seven nested cards turns a
 * document into a pile of boxes with more border than content. The
 * numbered eyebrow gives the page the reading order a controlled document
 * has without the furniture.
 */
export function DetailSection({ index, title, icon: Icon, description, action, children, className }) {
    return (
        <section className={cn('border-t border-graphite-100 pt-4 first:border-t-0 first:pt-0 dark:border-slate-800', className)}>
            <div className="mb-3 flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-2">
                    {index && (
                        <span className="shrink-0 rounded bg-navy-900/[0.06] px-1.5 py-0.5 font-mono text-[10px] font-semibold tabular-nums text-navy-700 dark:bg-slate-800 dark:text-slate-300">
                            {index}
                        </span>
                    )}
                    {Icon && <Icon className="h-3.5 w-3.5 shrink-0 text-graphite-400" aria-hidden="true" />}
                    <h3 className="truncate text-[11px] font-semibold uppercase tracking-[0.12em] text-navy-800 dark:text-slate-200">
                        {title}
                    </h3>
                </div>
                {action && <div className="shrink-0">{action}</div>}
            </div>

            {description && (
                <p className="-mt-1.5 mb-3 text-xs leading-relaxed text-graphite-500 dark:text-slate-400">{description}</p>
            )}

            {children}
        </section>
    );
}
