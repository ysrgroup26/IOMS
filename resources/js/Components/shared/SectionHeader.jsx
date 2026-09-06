/**
 * Shared Section Header (v1.6.5 foundation) -- a smaller heading for a
 * section *within* a page (e.g. "Recent Activity" above a card), as
 * distinct from PageHeader (the page's own title). For new pages/sections
 * going forward; existing sections were left as-is.
 *
 * Usage:
 *   <SectionHeader title="Recent Activity" action={<Link href="...">View All</Link>} />
 */
/**
 * v2.43.0: was a plain 14px bold line, visually almost identical to a
 * CardTitle, so a page of stacked sections had no readable rhythm -- you
 * could not tell at a glance where one region ended and the next began.
 * Now an uppercase tracked eyebrow with a short navy rule, the same
 * section-label language the Dashboard already uses for COMPANY SNAPSHOT /
 * NEEDS ATTENTION / KPI SUMMARY. Same props, so existing callers upgrade
 * untouched; the hairline fills the remaining width so sections read as
 * bands down the page rather than as free-floating text.
 */
export default function SectionHeader({ title, description, action }) {
    return (
        <div className="mb-3 flex items-end justify-between gap-3">
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="h-3 w-0.5 shrink-0 rounded-full bg-navy-700 dark:bg-brand-500" aria-hidden="true" />
                    <h2 className="text-[11px] font-semibold uppercase tracking-[0.12em] text-navy-700 dark:text-slate-300">{title}</h2>
                    <span className="h-px min-w-4 flex-1 bg-gradient-to-r from-steel-200 to-transparent dark:from-slate-700" aria-hidden="true" />
                </div>
                {description && <p className="mt-0.5 pl-3.5 text-xs text-graphite-500 dark:text-slate-500">{description}</p>}
            </div>
            {action && <div className="shrink-0">{action}</div>}
        </div>
    );
}
