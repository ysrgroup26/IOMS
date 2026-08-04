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
export default function EmptyState({ icon: Icon = Inbox, title, description, action }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 py-12 text-center">
            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-graphite-100 text-graphite-400 dark:bg-slate-800 dark:text-slate-500">
                <Icon className="h-5 w-5" />
            </div>
            <p className="text-sm font-medium text-graphite-700 dark:text-slate-300">{title}</p>
            {description && <p className="max-w-xs text-xs text-graphite-400 dark:text-slate-500">{description}</p>}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}
