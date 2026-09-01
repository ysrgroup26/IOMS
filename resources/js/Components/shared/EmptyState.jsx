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
        <div className="flex flex-col items-center justify-center gap-2 py-10 text-center">
            <div className="flex h-11 w-11 items-center justify-center rounded-full bg-brand-50 text-brand-500 dark:bg-brand-950/40 dark:text-brand-400">
                <Icon className="h-5 w-5" />
            </div>
            <p className="text-sm font-medium text-graphite-700 dark:text-slate-300">{title}</p>
            {description && <p className="max-w-xs text-xs text-graphite-400 dark:text-slate-500">{description}</p>}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}
