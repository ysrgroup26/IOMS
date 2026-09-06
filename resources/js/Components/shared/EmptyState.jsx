import { Inbox } from 'lucide-react';

/**
 * Shared Empty State (v1.6.5 foundation). The "No records found" message
 * has been repeated with slightly different markup on nearly every list
 * page (Employees, Projects, PPE, Tasks, Daily Reports...). Canonical
 * version for new pages going forward -- existing pages were left as-is.
 *
 * Usage:
 *   <EmptyState icon={FolderKanban} title="No projects yet" description="Create your first project to get started." />
 */
// v2.29.0 (Authenticated UI Visual Transformation, Part 12): the icon
// chip now uses the same soft-blue surface treatment as StatCard/tinted
// section blocks elsewhere in this pass, instead of a plain neutral
// gray circle -- "intentional, not a missing database" reads more
// convincingly with a touch of the app's own visual identity behind it.
// Structure/copy contract unchanged (icon, title, one-line description,
// optional action) -- every existing caller renders identically.
export default function EmptyState({ icon: Icon = Inbox, title, description, action }) {
    return (
        // v2.43.0: on the new tinted ground a bare centred paragraph read as
        // a rendering gap. A dashed steel panel makes "there is deliberately
        // nothing here yet" look like a designed state -- the same reasoning
        // as HSE's "-- / not available" blocks. Dashed, not solid, so it is
        // never mistaken for a data card.
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-steel-200 bg-gradient-to-b from-steel-50/60 to-white/40 px-6 py-10 text-center dark:border-slate-700 dark:from-slate-900/40 dark:to-transparent">
            <div className="flex h-11 w-11 items-center justify-center rounded-full bg-gradient-to-br from-navy-800 to-brand-600 text-white shadow-[0_3px_8px_-3px_rgba(33,102,196,0.38)] dark:from-brand-950 dark:to-brand-900 dark:text-brand-300">
                <Icon className="h-5 w-5" />
            </div>
            <p className="text-sm font-semibold text-navy-800 dark:text-slate-300">{title}</p>
            {description && <p className="max-w-xs text-xs text-graphite-400 dark:text-slate-500">{description}</p>}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}
