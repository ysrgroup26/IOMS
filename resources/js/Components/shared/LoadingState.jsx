import { Loader2 } from 'lucide-react';

/**
 * Shared Loading State (v1.6.5 foundation). No formal loading indicator
 * existed anywhere in the app before this -- most pages simply don't show
 * one (Inertia's page-to-page navigation has its own top progress bar,
 * which covers full navigations), but an in-page async action (e.g. a
 * fetch inside a component, like GlobalSearch) had nothing standard to
 * reach for. For new use going forward.
 *
 * Usage:
 *   {loading ? <LoadingState label="Loading tasks..." /> : <TaskList tasks={tasks} />}
 */
export default function LoadingState({ label = 'Loading...' }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 py-12 text-center">
            <Loader2 className="h-5 w-5 animate-spin text-graphite-400 dark:text-slate-500" />
            <p className="text-sm text-graphite-400 dark:text-slate-500">{label}</p>
        </div>
    );
}
