import { cn } from '@/lib/utils';

/**
 * v2.72.0 -- WHAT AM I CREATING, AND WHAT HAPPENS TO IT?
 *
 * The Form Experience System (v2.65.0/v2.66.0) fixed the INSIDE of a
 * form: labelled fields, grouped sections, an error summary, a sticky
 * action bar that answers "what happens after I save". What it never
 * addressed is the TOP of one, and that is where an operational form
 * stops feeling like part of an industrial product.
 *
 * Reported directly, about PTW: weak form identity, weak header,
 * mechanically stacked inputs, "not enough visual indication that the
 * user is creating a formal operational document". That last phrase is
 * the whole brief. A permit to work is an instrument that authorises
 * dangerous work; raising one should not look like filling in a contact
 * form.
 *
 * WHAT THIS IS NOT. It is not a second PageHeader, and it is not a card
 * with a gradient added to make it feel important. `PageHeader` answers
 * "what page am I on". This answers four different questions, and only
 * ever on a form that CREATES OR EDITS A RECORD SOMEONE ELSE WILL ACT ON:
 *
 *   WHAT is this?          the document's name, and its own reference
 *                          number -- the single strongest signal that
 *                          this is a controlled document rather than a
 *                          web form. It is shown BEFORE submission
 *                          because the number is reserved at that point,
 *                          which is what makes it real to the person
 *                          filling it in.
 *   WHAT STATE is it in?   Draft while being written, Editing when
 *                          revising something that already exists.
 *   WHO is responsible?    named, because a permit with no owner is not
 *                          a permit. Always derived from the session
 *                          server-side; this only displays it.
 *   WHAT HAPPENS NEXT?     the workflow, in one sentence, before the
 *                          user commits to it rather than after.
 *
 * WHEN NOT TO USE IT. Master-data and settings forms should NOT have
 * one: defining an equipment type has no reference number, no
 * responsible party and no downstream workflow, and giving it the
 * furniture of a controlled document would say something false about it.
 * That distinction -- reference data versus operational records -- is the
 * one v2.71.0 introduced and this deliberately reinforces rather than
 * blurs. Master data keeps `PageHeader kind="master"`.
 */
export default function FormDocumentHeader({
    icon: Icon,
    documentType,
    reference,
    state = 'draft',
    meta = [],
    workflow,
    className,
}) {
    const stateLabel = state === 'editing' ? 'Editing' : 'Draft';

    return (
        <div
            className={cn(
                // The same physical-surface treatment as the rest of the
                // product's headers -- a cool wash and a lit top edge --
                // so this reads as IOMS furniture rather than as a
                // one-off panel invented for forms.
                'relative overflow-hidden rounded-xl border border-steel-200/70 bg-gradient-to-br from-steel-100/80 via-white to-white shadow-panel',
                'before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-white/90 before:to-transparent',
                'dark:border-slate-800 dark:from-slate-900 dark:via-slate-900 dark:to-slate-900',
                className
            )}
        >
            <div className="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex min-w-0 items-start gap-3">
                    {Icon && (
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] bg-gradient-to-br from-navy-800 to-brand-600 text-white shadow-[0_3px_8px_-3px_rgba(33,102,196,0.38)] dark:from-brand-950 dark:to-brand-900 dark:text-brand-300">
                            <Icon className="h-[18px] w-[18px]" aria-hidden="true" />
                        </span>
                    )}

                    <div className="min-w-0">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-graphite-400 dark:text-slate-500">
                            {documentType}
                        </p>

                        {/* The reference number is the title here, not a
                            caption. On a controlled document the number
                            IS the identity -- it is what somebody quotes
                            on the radio. */}
                        <h1 className="flex flex-wrap items-center gap-2 text-[19px] font-semibold leading-tight tracking-tight text-navy-900 dark:text-slate-50">
                            {reference ? <span className="font-mono tabular-nums">{reference}</span> : documentType}
                            <span className="rounded-full border border-graphite-300 bg-graphite-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-graphite-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                {stateLabel}
                            </span>
                        </h1>
                    </div>
                </div>

                {/* Responsibility. Rendered as definition pairs rather
                    than a sentence so it survives being scanned. */}
                {meta.length > 0 && (
                    <dl className="flex shrink-0 flex-wrap gap-x-5 gap-y-1.5 sm:justify-end">
                        {meta.filter((m) => m?.value).map((m) => (
                            <div key={m.label} className="min-w-0">
                                <dt className="text-[10px] uppercase tracking-wide text-graphite-400 dark:text-slate-500">{m.label}</dt>
                                <dd className="truncate text-[13px] font-medium text-graphite-800 dark:text-slate-200">{m.value}</dd>
                            </div>
                        ))}
                    </dl>
                )}
            </div>

            {/* What happens after submission -- stated up front, on its
                own rule, because the answer changes how carefully the
                form gets filled in. */}
            {workflow && (
                <p className="border-t border-steel-200/70 bg-white/60 px-4 py-2 text-xs leading-relaxed text-graphite-600 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-400">
                    {workflow}
                </p>
            )}
        </div>
    );
}
