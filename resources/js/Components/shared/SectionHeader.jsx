/**
 * Shared Section Header (v1.6.5 foundation) -- a smaller heading for a
 * section *within* a page (e.g. "Recent Activity" above a card), as
 * distinct from PageHeader (the page's own title). For new pages/sections
 * going forward; existing sections were left as-is.
 *
 * Usage:
 *   <SectionHeader title="Recent Activity" action={<Link href="...">View All</Link>} />
 */
export default function SectionHeader({ title, description, action }) {
    return (
        <div className="mb-3 flex items-center justify-between gap-3">
            <div>
                <h2 className="text-sm font-semibold text-graphite-800 dark:text-slate-100">{title}</h2>
                {description && <p className="text-xs text-graphite-400 dark:text-slate-500">{description}</p>}
            </div>
            {action}
        </div>
    );
}
