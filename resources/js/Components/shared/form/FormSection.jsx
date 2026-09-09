import { cn } from '@/lib/utils';

/**
 * v2.65.0 -- A GROUP THAT EXPLAINS ITSELF.
 *
 * The audit's finding was not that forms lacked cards -- most had exactly
 * one, titled with the entity name ("Employee Information") and holding
 * twenty fields in stacked two-column grids. The grid gave them rhythm.
 * Nothing gave them MEANING: no statement of what a group of fields is
 * for, or why the information is being asked for.
 *
 * PTW is the one form that does this, and it is why it reads better. Its
 * sections carry a title AND a one-line description written as a question
 * -- "Who is involved in this work?" -- so the section answers "why am I
 * being asked this" before the reader has to wonder. That is the
 * transferable principle, and it is what this component makes cheap.
 *
 * DELIBERATELY NOT A CARD BY DEFAULT.
 *
 * A card should communicate a meaningful object. When every group is a
 * card, the card stops meaning anything and becomes wallpaper -- four
 * bordered boxes stacked down a page is not more organised than one, it
 * is just more borders. The default here is a plain section separated by
 * a hairline rule and whitespace, which is quieter and reads faster.
 *
 * `variant="card"` exists for the case where a group genuinely IS a
 * distinct object -- a line-item table, a document preview, a panel that
 * could sensibly be moved elsewhere.
 *
 * `step` renders PTW's numbered badge. Use it ONLY where the order is
 * genuinely sequential (a safety process that must be completed in
 * order). Numbering an Employee form implies a sequence that does not
 * exist and makes a short form feel like a procedure.
 */
export default function FormSection({
    title,
    description,
    step,
    icon: Icon,
    variant = 'plain',
    className,
    children,
    footer,
}) {
    const isCard = variant === 'card';

    return (
        <section
            className={cn(
                isCard
                    ? 'rounded-xl border border-graphite-200 bg-white p-5 shadow-card dark:border-slate-800 dark:bg-slate-900'
                    : 'border-t border-graphite-100 pt-6 first:border-t-0 first:pt-0 dark:border-slate-800',
                className
            )}
        >
            {(title || description) && (
                <header className={cn('mb-4', isCard && 'mb-4')}>
                    <div className="flex items-center gap-2">
                        {step && (
                            <span
                                aria-hidden="true"
                                className="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-graphite-100 text-[10px] font-bold tabular-nums text-graphite-500 dark:bg-slate-800 dark:text-slate-400"
                            >
                                {step}
                            </span>
                        )}
                        {Icon && <Icon className="h-4 w-4 shrink-0 text-graphite-400" aria-hidden="true" />}
                        <h2 className="text-[15px] font-semibold tracking-tight text-navy-900 dark:text-slate-100">
                            {title}
                        </h2>
                    </div>
                    {description && (
                        <p className="mt-1 text-xs leading-relaxed text-graphite-500 dark:text-slate-400">
                            {description}
                        </p>
                    )}
                </header>
            )}

            {/* One rhythm for every form: single column on a phone, two
                from `sm`. Never three -- a form field wider than about
                60 characters is harder to scan, and three columns on a
                laptop produces exactly that. A field that needs the full
                width asks for it with `className="sm:col-span-2"`. */}
            <div className="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-2">{children}</div>

            {footer && <div className="mt-4">{footer}</div>}
        </section>
    );
}
