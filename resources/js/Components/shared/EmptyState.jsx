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
        <div className="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-steel-200/90 bg-white/45 px-6 py-12 text-center">
            <div className="icon-soft icon-soft-blue mb-1 h-12 w-12 rounded-2xl">
                <Icon className="h-5 w-5" strokeWidth={1.75} />
            </div>
            <p className="text-[15px] font-bold text-navy-900">{title}</p>
            {description && <p className="max-w-sm text-[13px] leading-relaxed text-slate-500">{description}</p>}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}
