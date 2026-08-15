import PageHeader from '@/Components/shared/PageHeader';

/**
 * v1.11.3 (Global Dashboard/Overview UX Rework, Part 7). A thin layout
 * wrapper so every dashboard/overview page shares the exact same
 * top-level structure (PageHeader + a consistent vertical rhythm between
 * sections) without forcing identical content -- each department's
 * stat/module/calendar/activity content still varies, only the shell
 * around it is now literally the same component everywhere instead of
 * each page hand-rolling its own header + spacing.
 *
 * Deliberately dumb: no data-fetching, no department-specific logic,
 * just `<PageHeader>` + `<div className="space-y-4">{children}</div>`.
 * Callers compose their own StatCard rows / ModuleCard grids /
 * DepartmentCalendarWidget / ActivityList inside it.
 *
 * Usage:
 *   <DashboardShell title="HSE Dashboard" subtitle="Operational HSE overview.">
 *       <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">...stat cards...</div>
 *       ...
 *   </DashboardShell>
 */
export default function DashboardShell({ title, subtitle, actions, children }) {
    return (
        <>
            <PageHeader title={title} subtitle={subtitle}>{actions}</PageHeader>
            <div className="space-y-4">{children}</div>
        </>
    );
}
