import { Link } from '@inertiajs/react';
import EmptyState from '@/Components/shared/EmptyState';

/**
 * v1.11.3 (Global Dashboard/Overview UX Rework, Part 7). Generalizes the
 * "divide-y list of recent/pending items, or an empty state" pattern that
 * was hand-rolled 8+ times across dashboards (Recent Leave Requests,
 * Recent Incidents, Upcoming Milestones, Material Requests by Status,
 * Recent Purchase Orders, PPE by Type, Expiring Soon, ...) with near-
 * identical markup every time. Deliberately does NOT prescribe row
 * content -- every one of those lists shows different fields (employee
 * name vs. PO number vs. a status count), so this only owns the list
 * shell (divide-y wrapper + row wrapper, optionally a Link) and the
 * empty-state fallback (reusing the existing shared `EmptyState`), via a
 * `renderItem` render-prop for the row content itself.
 *
 * Usage:
 *   <ActivityList
 *       items={recentIncidents}
 *       emptyIcon={AlertTriangle}
 *       emptyTitle="No incidents recorded"
 *       getKey={(i) => i.id}
 *       getHref={(i) => route('incidents.show', i.id)}
 *       renderItem={(i) => (
 *           <div className="flex items-center justify-between gap-2 py-2 text-sm">
 *               <span className="truncate font-medium">{i.title}</span>
 *               <StatusBadge value={i.status} />
 *           </div>
 *       )}
 *   />
 */
export default function ActivityList({ items = [], renderItem, getKey, getHref, emptyIcon, emptyTitle = 'Nothing here yet', emptyDescription }) {
    if (items.length === 0) {
        return <EmptyState icon={emptyIcon} title={emptyTitle} description={emptyDescription} />;
    }

    return (
        <div className="divide-y divide-graphite-100 dark:divide-slate-800">
            {items.map((item, index) => {
                const key = getKey ? getKey(item, index) : (item.id ?? index);
                const href = getHref ? getHref(item) : null;
                const content = renderItem(item, index);

                return href ? (
                    <Link key={key} href={href} className="block hover:text-brand-700 dark:hover:text-brand-400">{content}</Link>
                ) : (
                    <div key={key}>{content}</div>
                );
            })}
        </div>
    );
}
