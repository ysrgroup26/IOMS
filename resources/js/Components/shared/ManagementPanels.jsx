import { Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { cn } from '@/lib/utils';
import { Info, Minus } from 'lucide-react';

/**
 * v2.83.0 -- THE MANAGEMENT WORKSPACE'S OWN VOCABULARY.
 *
 * Six pages share these, for one reason: a management surface is
 * information-dense, and the densest thing in IOMS's existing system is
 * StatCard -- one number per card. Twenty numbers becomes twenty cards,
 * which is a wall, not a report. So the primitives here are deliberately
 * NOT cards:
 *
 *   FigureGroup   several related figures inside ONE bordered block
 *   Ranking       a compact ordered table, the shape of a comparison
 *   TrendBars     a real series, drawn only when there is one
 *   NoData        what a section says when the module has never been used
 *
 * StatCard is still the right answer for the two or three headline numbers
 * at the top of the Overview, and is used there. Everything below the fold
 * uses these instead.
 *
 * THE HIERARCHY THESE ENFORCE is what → status → priority → action: a
 * figure states what, its tone states status, `emphasis` states priority,
 * and an `href` is the action. Nothing here invents a colour for a number
 * it cannot interpret -- the default tone is neutral, and a caller has to
 * decide that a figure is bad before it turns amber or red.
 */

const TONES = {
    neutral: 'text-graphite-900 dark:text-slate-50',
    good: 'text-emerald-700 dark:text-emerald-400',
    warn: 'text-amber-700 dark:text-amber-400',
    bad: 'text-red-700 dark:text-red-400',
};

/**
 * One figure: a label, a value, and optionally a quieter line beneath it.
 *
 * `value` may legitimately be null, which renders an em-dash rather than a
 * zero -- "IOMS does not know this" and "the answer is nought" are
 * different statements and must not look identical.
 */
export function Figure({ label, value, hint, tone = 'neutral', emphasis = false, href }) {
    const body = (
        <>
            <p className="text-[11px] font-medium uppercase tracking-wide text-graphite-400 dark:text-slate-500">{label}</p>
            <p className={cn('mt-0.5 font-semibold tabular-nums', emphasis ? 'text-2xl' : 'text-lg', TONES[tone] ?? TONES.neutral)}>
                {value === null || value === undefined
                    ? <Minus className="h-4 w-4 text-graphite-300" aria-label="Not available" />
                    : value}
            </p>
            {hint && <p className="mt-0.5 text-[11px] text-graphite-400 dark:text-slate-500">{hint}</p>}
        </>
    );

    if (href) {
        return (
            <Link href={href} className="block rounded-lg px-3 py-2.5 transition-colors hover:bg-steel-50/70 dark:hover:bg-slate-800/60">
                {body}
            </Link>
        );
    }

    return <div className="px-3 py-2.5">{body}</div>;
}

/**
 * A titled block holding several Figures, divided rather than boxed
 * individually -- the divisions are what make a row of numbers read as one
 * statement about one subject.
 */
export function FigureGroup({ title, description, columns = 3, actions, children }) {
    return (
        <Card>
            {(title || actions) && (
                <CardHeader className="flex-row items-start justify-between gap-3 pb-2">
                    <div className="min-w-0">
                        {title && <CardTitle>{title}</CardTitle>}
                        {description && <CardDescription>{description}</CardDescription>}
                    </div>
                    {actions}
                </CardHeader>
            )}
            <CardContent className="pt-0">
                <div className={cn(
                    'grid divide-graphite-100 dark:divide-slate-800',
                    'divide-y sm:divide-y-0 sm:divide-x',
                    columns === 2 && 'sm:grid-cols-2',
                    columns === 3 && 'sm:grid-cols-3',
                    columns === 4 && 'grid-cols-2 sm:grid-cols-4',
                    columns === 5 && 'grid-cols-2 sm:grid-cols-5',
                    columns === 6 && 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6',
                )}>
                    {children}
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * A real series, as bars.
 *
 * REFUSES TO DRAW WITHOUT DATA, following Sparkline's existing rule: a
 * chart of twelve zeroes is a decorative chart, and this workspace's whole
 * premise is that it shows nothing it cannot source. A caller that has no
 * series gets the `empty` slot instead.
 *
 * Two series are supported because the pairing that matters in HSE is
 * incidents against observations -- leading versus lagging indicators,
 * which are only meaningful side by side.
 */
export function TrendBars({ points, series, empty }) {
    const rows = points ?? [];
    const keys = series ?? [];
    const max = Math.max(0, ...rows.flatMap((p) => keys.map((s) => Number(p[s.key] ?? 0))));

    if (rows.length === 0 || max === 0) {
        return empty ?? null;
    }

    return (
        <div>
            <div className="flex items-end gap-1.5" style={{ height: '120px' }} role="img" aria-label={keys.map((s) => s.label).join(' and ')}>
                {rows.map((point, index) => (
                    // `max-w` on the bar, not the column: a twelve-month
                    // series on a wide panel gives each column ~90px, and a
                    // bar that wide reads as a block of colour rather than a
                    // measurement. Browser-verified on six months of real
                    // data, where one incident rendered as a red slab.
                    <div key={index} className="flex h-full flex-1 flex-col items-center justify-end gap-px">
                        {keys.map((s) => {
                            const value = Number(point[s.key] ?? 0);

                            return (
                                <div
                                    key={s.key}
                                    className={cn('w-full max-w-[26px] rounded-t-sm', s.className)}
                                    // Height is the only inline style here: it is
                                    // data, not design, and cannot be a class.
                                    style={{ height: `${(value / max) * 100}%` }}
                                    title={`${point.label}: ${value} ${s.label}`}
                                />
                            );
                        })}
                    </div>
                ))}
            </div>
            <div className="mt-1.5 flex gap-1.5">
                {rows.map((point, index) => (
                    <div key={index} className="flex-1 truncate text-center text-[10px] text-graphite-400 dark:text-slate-500">
                        {point.short_label ?? point.label}
                    </div>
                ))}
            </div>
            <div className="mt-2 flex flex-wrap gap-3">
                {keys.map((s) => (
                    <span key={s.key} className="flex items-center gap-1.5 text-[11px] text-graphite-500 dark:text-slate-400">
                        <span className={cn('h-2 w-2 rounded-sm', s.className)} aria-hidden="true" /> {s.label}
                    </span>
                ))}
            </div>
        </div>
    );
}

/**
 * A compact comparison table. `columns` are `{ key, label, align, render }`
 * and the first column is the row's name.
 *
 * Rows are given as-is -- no client-side sorting, because the server
 * already ordered them by whatever makes them comparable, and re-sorting
 * here would let the page disagree with the report it came from.
 */
export function Ranking({ rows, columns, empty }) {
    if (!rows || rows.length === 0) {
        return empty ?? null;
    }

    return (
        <div className="-mx-2 overflow-x-auto">
            <table className="w-full min-w-[420px] text-sm">
                <thead>
                    <tr className="border-b border-graphite-100 dark:border-slate-800">
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                scope="col"
                                className={cn(
                                    'px-2 pb-1.5 text-[11px] font-medium uppercase tracking-wide text-graphite-400 dark:text-slate-500',
                                    column.align === 'right' ? 'text-right' : 'text-left',
                                )}
                            >
                                {column.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => (
                        <tr key={row.id ?? index} className="border-b border-graphite-50 last:border-0 dark:border-slate-800/60">
                            {columns.map((column) => (
                                <td
                                    key={column.key}
                                    className={cn(
                                        'px-2 py-1.5',
                                        column.align === 'right' ? 'text-right tabular-nums' : 'text-left',
                                        column.key === columns[0].key
                                            ? 'font-medium text-graphite-900 dark:text-slate-100'
                                            : 'text-graphite-600 dark:text-slate-400',
                                    )}
                                >
                                    {column.render ? column.render(row) : row[column.key]}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * What a section says when the module behind it has never been used.
 *
 * Deliberately explains WHICH module produces the data rather than saying
 * "no data": a manager looking at an empty Logistics panel needs to know
 * that it fills once their team raises material requests, not that
 * something is broken. This is the honest alternative to a zero.
 */
export function NoData({ title, description }) {
    return (
        <div className="flex items-start gap-2.5 rounded-lg border border-dashed border-steel-200 bg-steel-50/50 px-3 py-3 dark:border-slate-700 dark:bg-slate-900/40">
            <Info className="mt-0.5 h-4 w-4 shrink-0 text-steel-400" aria-hidden="true" />
            <div className="min-w-0">
                <p className="text-xs font-semibold text-navy-800 dark:text-slate-300">{title}</p>
                {description && <p className="mt-0.5 text-[11px] text-graphite-500 dark:text-slate-500">{description}</p>}
            </div>
        </div>
    );
}

/** A plain titled panel for content that is neither figures nor a table. */
export function Panel({ title, description, actions, children, className }) {
    return (
        <Card className={className}>
            <CardHeader className="flex-row items-start justify-between gap-3 pb-2">
                <div className="min-w-0">
                    <CardTitle>{title}</CardTitle>
                    {description && <CardDescription>{description}</CardDescription>}
                </div>
                {actions}
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

/**
 * A labelled horizontal distribution, for a status or category breakdown.
 *
 * Takes an object keyed by the module's own vocabulary and renders whatever
 * keys are present -- never a fixed list, so a status the module adds later
 * appears here without an edit, and one it never uses does not show as 0.
 */
export function Distribution({ data, total, labels = {} }) {
    const entries = Object.entries(data ?? {}).filter(([, value]) => Number(value) > 0);
    const sum = total ?? entries.reduce((carry, [, value]) => carry + Number(value), 0);

    if (entries.length === 0) return null;

    return (
        <div className="space-y-1.5">
            {entries.map(([key, value]) => (
                <div key={key} className="flex items-center gap-2">
                    <span className="w-32 shrink-0 truncate text-xs text-graphite-600 dark:text-slate-400">
                        {labels[key] ?? key.replace(/_/g, ' ')}
                    </span>
                    <span className="h-2 min-w-[2px] rounded-sm bg-brand-500/80" style={{ width: `${sum > 0 ? (Number(value) / sum) * 100 : 0}%` }} aria-hidden="true" />
                    <span className="ml-auto text-xs font-semibold tabular-nums text-graphite-800 dark:text-slate-200">{value}</span>
                </div>
            ))}
        </div>
    );
}
