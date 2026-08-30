import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/**
 * Generic module top navigation (v1.6.7) -- extracted from the
 * PPE-specific version so it's actually reusable, not just
 * reusable-in-theory. A future module (Medical, Training, License,
 * Asset, Competency, Attendance) uses this directly:
 *
 *   <ModuleTabNav tabs={[
 *       { name: 'Dashboard', href: 'medical.dashboard' },
 *       { name: 'Employee Medical', href: 'medical.employees' },
 *   ]} />
 *
 * -- no copy-pasting or rewriting the component itself, only the tab
 * list differs per module. `PpeTabNav` (kept for backward compatibility
 * with the four existing PPE pages that already import it) is now a
 * thin wrapper around this with the PPE tabs hardcoded.
 */
export default function ModuleTabNav({ tabs }) {
    const { url } = usePage();

    return (
        <div className="mb-4 flex gap-1 border-b border-graphite-200 dark:border-slate-800">
            {tabs.map((tab) => {
                let active = false;
                try {
                    active = url.split('?')[0] === new URL(route(tab.href)).pathname;
                } catch {
                    active = false;
                }
                return (
                    <Link
                        key={tab.href}
                        href={route(tab.href)}
                        className={cn(
                            'flex items-center gap-1.5 border-b-2 px-3 py-1.5 text-xs font-medium transition-colors',
                            active
                                ? 'border-brand-600 text-brand-700 dark:text-brand-400'
                                : 'border-transparent text-graphite-500 hover:border-graphite-300 hover:text-graphite-800 dark:text-slate-400 dark:hover:text-slate-200'
                        )}
                    >
                        {tab.name}
                        {/* v2.5.0 (Field HSE Experience pass, Part 11): optional
                            small pill so a config/admin-only tab (e.g. PPE
                            Master) reads visibly different in KIND from the
                            daily-operations tabs next to it, not just by RBAC
                            hiding its buttons once you're already there. */}
                        {tab.badge && (
                            <span className="rounded-full bg-graphite-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-graphite-500 dark:bg-slate-800 dark:text-slate-400">
                                {tab.badge}
                            </span>
                        )}
                    </Link>
                );
            })}
        </div>
    );
}
