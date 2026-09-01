import { Head, router, Link, usePage } from '@inertiajs/react';
import StatusBadge from '@/Components/shared/StatusBadge';
import { useState } from 'react';
import '@/lib/chartSetup';
import { CHART_COLORS } from '@/lib/chartSetup';
import { Pie, Line } from 'react-chartjs-2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import KpiSummaryCard from '@/Components/shared/KpiSummaryCard';
import DepartmentCalendarWidget from '@/Components/shared/DepartmentCalendarWidget';
import StatCard from '@/Components/shared/StatCard';
import { deptSafeHref } from '@/lib/departmentAccess';
import PeriodFilter from '@/Components/shared/PeriodFilter';
import BrandWatermark from '@/Components/shared/BrandWatermark';
import { useClock, greetingFor } from '@/lib/useClock';
import { formatNumber, cn } from '@/lib/utils';
import {
    Trophy, Flame, ClipboardList, Users2, FolderKanban, Activity, Bell,
    Users, Building2, CalendarDays, HardHat, CheckCircle2, AlertTriangle, Plus, UserCog, ArrowRight,
    Sparkles, X, ClipboardCheck, ShoppingCart, Boxes, Box, Wrench, Flag, Clock,
    Eye, FileWarning, ShieldAlert, FlaskConical, GraduationCap, PackagePlus, PackageCheck,
    ArrowRightLeft, FileStack, UserPlus, CheckSquare, Recycle, Lock,
} from 'lucide-react';

// v2.2.0 (IOMS OS Ecosystem pass, Part 5): matches every icon key
// WorkCenterService::quickActionsFor() can emit -- same list as
// WorkCenter/Index.jsx's own QUICK_ACTION_ICONS map.
// v2.3.0: added Recycle for the new "New Waste Record" quick action.
// v2.11.0: added Lock for the new "New LOTO" quick action.
const QUICK_ACTION_ICONS = {
    HardHat, AlertTriangle, Eye, ClipboardCheck, Flame, FileWarning, ShieldAlert, FlaskConical,
    UserPlus, Clock, CalendarDays, GraduationCap, FolderKanban, ClipboardList, Flag,
    PackagePlus, FileStack, ShoppingCart, PackageCheck, ArrowRightLeft, CheckSquare, Recycle, Lock,
};

/**
 * v1.3.2 redesign: visual hierarchy now goes
 * 1. Four primary cards (Total Employees, Active Projects, Companies, Current Month)
 * 2. Compact KPI grid (much less vertical space than the old large cards)
 * 3. Charts as the visual focus (Monthly Trend, Employees by Department)
 * 4. Secondary info (leaderboards, today's activity, reminders) further down
 * All existing data/filters are unchanged -- this is a layout/prominence
 * change only, no functionality removed.
 */
export default function Dashboard({
    filters, availableYears, currentMonth, companies, summary, companyHeadcount,
    departmentDistribution, monthlyTrend, leaderboards,
    activeProjectsCount, todaysActivities, upcomingReminders, pendingTasks, quickActions, employeesNeedCompletionCount,
    recentDailyReports, recentEmployeeChanges, showAnnouncement,
    openIncidentsCount, openCapaCount, pendingProcurementCount, stockAlertCount, assetCount, maintenanceDueCount,
    upcomingEvents, manpower, manhours, projectSummary, upcomingMilestones,
}) {
    const { auth, notifications, version } = usePage().props;
    const deptPrefixes = auth?.user?.department_prefixes ?? null;
    const now = useClock();
    const [announcementDismissed, setAnnouncementDismissed] = useState(false);

    // Company filter is kept separate from PeriodFilter so all four
    // filter values (company/year/month) are always sent together.
    function updateFilters(overrides = {}) {
        router.get(route('dashboard'), {
            year: filters.year,
            month: filters.month,
            company_id: filters.company_id,
            ...overrides,
        }, { preserveState: true, preserveScroll: true });
    }

    function handlePeriodChange({ year, month }) {
        updateFilters({ year, month });
    }

    const pieData = {
        labels: departmentDistribution.map((d) => d.label),
        datasets: [{
            data: departmentDistribution.map((d) => d.value),
            backgroundColor: CHART_COLORS,
            borderWidth: 0,
        }],
    };

    const trendData = {
        labels: monthlyTrend.labels,
        datasets: monthlyTrend.series.map((s, i) => ({
            label: s.label,
            data: s.data,
            borderColor: CHART_COLORS[i % CHART_COLORS.length],
            backgroundColor: CHART_COLORS[i % CHART_COLORS.length] + '20',
            tension: 0.35,
            pointRadius: 2,
        })),
    };

    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />

            {/* Subtle premium background depth (v1.5.4) -- two very soft,
                large, blurred gradient blobs bleeding off opposite
                corners. Low opacity (kept under 8%) and purely
                decorative/non-interactive so readability of the actual
                content is never affected. */}
            <div className="relative overflow-hidden">
                <div className="pointer-events-none absolute -left-32 -top-32 -z-10 h-96 w-96 rounded-full bg-brand-400 opacity-[0.06] blur-3xl" aria-hidden="true" />
                <div className="pointer-events-none absolute -bottom-40 -right-24 -z-10 h-[28rem] w-[28rem] rounded-full bg-graphite-400 opacity-[0.05] blur-3xl" aria-hidden="true" />

            {/* Subtle brand watermark. v1.6.2 root-cause fix: this
                specific wrapper previously also had `overflow-hidden`,
                but its natural content height (header row + Hero
                Summary, ~250-300px) is considerably shorter than the
                watermark (450px) -- overflow-hidden was silently
                clipping most of the image away. `relative` alone (no
                overflow-hidden) is correct here; the outer wrapper
                above already handles edge containment for the whole
                page, which is tall enough that nothing bleeds off it. */}
            <div className="relative">
                <BrandWatermark
                    context="dashboard"
                    size="h-[450px] w-[450px]"
                    opacity={0.06}
                    className="-right-16 -top-20 -z-10"
                />

                {/* Hero: introduces the Dashboard only -- title, company
                    branding, and period filters. Capped at 220px (fixed
                    height, never h-screen/min-h-screen) and kept compact
                    so KPI cards are visible immediately without scrolling.
                    Today's Summary (HeroSummary below) is separate,
                    substantive operational content, not part of this
                    height budget. Soft blue gradient in light mode,
                    slate-900 + subtle blue gradient in dark mode --
                    dedicated colors, not opacity tricks. */}
                <div className="mb-4 flex max-h-[220px] flex-wrap items-center justify-between gap-3 rounded-xl border border-graphite-100 bg-gradient-to-br from-brand-50/60 via-white to-brand-50/30 px-4 py-3 dark:border-slate-800 dark:from-slate-900 dark:via-slate-900 dark:to-blue-950/40">
                    <div>
                        {/* Final UI Stabilization: no logo/wordmark at all
                            here anymore -- per explicit instruction,
                            branding lives only in the Sidebar now, and
                            duplicating it in the Dashboard header (as the
                            previous session did) was itself the "duplicate
                            branding" this issue is about. Just the page
                            title and subtitle. */}
                        {/* v1.11.10 (Visual Correction, Part 0): reverted from
                            text-xl (20px, bumped in v1.11.9) back to
                            text-base (16px) -- direct user feedback that the
                            "Dashboard" title read too large. This hero
                            widget is a compact ~220px-tall banner alongside
                            filter controls, not a full PageHeader -- it
                            never needed the 24px "page title" treatment
                            PageHeader itself correctly uses elsewhere. */}
                        <h1 className="text-base font-bold tracking-tight text-graphite-900 dark:text-slate-50">Dashboard</h1>
                        {/* v2.26.0 (Final Copy Consistency pass): was
                            fully English -- naturalized to Indonesian,
                            "Dashboard" and "KPI" kept as established
                            terminology. */}
                        <p className="text-[11px] text-graphite-500 dark:text-slate-400">Ringkasan KPI operasional di seluruh departemen.</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Select
                            value={filters.company_id ? String(filters.company_id) : 'all'}
                            onValueChange={(v) => updateFilters({ company_id: v === 'all' ? null : Number(v) })}
                        >
                            <SelectTrigger className="w-40"><SelectValue placeholder="Company" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Companies</SelectItem>
                            {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <PeriodFilter
                        year={filters.year}
                        month={filters.month}
                        years={availableYears}
                        departments={[]}
                        showDepartment={false}
                        onChange={handlePeriodChange}
                    />
                </div>
                </div>

                <HeroSummary
                    name={auth?.user?.name?.split(' ')[0]}
                    now={now}
                    ltiCount={summary.categories.find((c) => c.code === 'lti')?.total ?? 0}
                    ppeAlertCount={notifications?.ppe_alert_count ?? 0}
                />
            </div>

            {/* Release announcement -- ported from the retired Home page
                (v1.9.0: Dashboard is now the landing page). Auto-hides 48h
                after release (computed server-side) plus a per-session
                client-side dismiss. */}
            {showAnnouncement && !announcementDismissed && (
                <div className="mt-4 flex items-start gap-3 rounded-xl border border-brand-200 bg-brand-50/60 p-4">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-brand-700">
                        <Sparkles className="h-4.5 w-4.5" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <p className="text-sm font-semibold text-graphite-900">Version {version?.number} Released</p>
                            <Badge variant="outline" className="text-[10px]">
                                {version?.release_date && new Date(version.release_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}
                            </Badge>
                        </div>
                        <ul className="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2">
                            {version?.whats_new?.slice(0, 6).map((item, i) => (
                                <li key={i} className="flex items-start gap-1.5 text-xs text-graphite-600">
                                    <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-500" />
                                    {item}
                                </li>
                            ))}
                        </ul>
                    </div>
                    <button onClick={() => setAnnouncementDismissed(true)} className="rounded p-1 text-graphite-400 hover:bg-white hover:text-graphite-600">
                        <X className="h-4 w-4" />
                    </button>
                </div>
            )}

            {/* 1. Primary cards -- the only "large" cards on this page.
                All four are now navigation widgets (v1.5.2), not dead
                statistics.
                v1.11.8 (Enterprise UI/UX Refinement, Part 7/8/23): the
                whole page previously rendered every StatCard in the same
                blue brand color regardless of what it measured -- fixed
                by assigning `accent` per the semantic meaning of each
                metric (green=healthy/active, amber=needs attention,
                red=critical, purple=assets/planning), not decoratively. */}
            <div className="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard icon={Users} value={`${formatNumber(companyHeadcount.overall_total)} Employees`} label="Active Workforce" href={deptSafeHref('employees.index', deptPrefixes)} />
                <StatCard icon={FolderKanban} value={`${formatNumber(activeProjectsCount)} Active Projects`} label="Running Projects" accent="green" href={deptSafeHref('projects.index', deptPrefixes)} />
                <StatCard icon={Building2} value={formatNumber(companyHeadcount.by_company.length)} label="Companies" accent="neutral" href={deptSafeHref('settings.index', deptPrefixes) ? route('settings.index') + '?tab=companies' : undefined} />
                <StatCard icon={CalendarDays} value={currentMonth} label="Current Period" accent="neutral" href={deptSafeHref('kpi-records.index', deptPrefixes, { year: filters.year, month: filters.month })} />
            </div>

            {/* Milestone 4, Acceleration Part 7 -- Executive cross-department
                summary. Every count below is real, tenant-scoped, over
                actual tables (see DashboardController's own doc comment) --
                additive to this page, no existing widget touched.
                v1.11.3.2: hrefs now go through deptSafeHref() -- a
                department-restricted viewer (e.g. an HSE-only user) sees
                every card's number, but a card whose route belongs to a
                DIFFERENT department renders without a link instead of a
                click that would 403 (RestrictDepartmentAccess itself is
                unchanged; this only avoids exposing a link it would reject). */}
            <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <StatCard icon={AlertTriangle} value={formatNumber(openIncidentsCount)} label="Open Incidents" accent={openIncidentsCount > 0 ? 'red' : 'green'} href={deptSafeHref('incidents.index', deptPrefixes)} />
                <StatCard icon={ClipboardCheck} value={formatNumber(openCapaCount)} label="Open CAPA" accent={openCapaCount > 0 ? 'amber' : 'green'} href={deptSafeHref('corrective-actions.index', deptPrefixes)} />
                <StatCard icon={ShoppingCart} value={formatNumber(pendingProcurementCount)} label="Pending Procurement" accent="purple" href={deptSafeHref('procurement.dashboard', deptPrefixes)} />
                <StatCard icon={Boxes} value={formatNumber(stockAlertCount)} label="Stock Alerts" accent={stockAlertCount > 0 ? 'amber' : 'green'} href={deptSafeHref('stock.index', deptPrefixes, { low_stock: 1 })} />
                <StatCard icon={Box} value={formatNumber(assetCount)} label="Active Assets" accent="purple" href={deptSafeHref('assets.index', deptPrefixes)} />
                <StatCard icon={Wrench} value={formatNumber(maintenanceDueCount)} label="Maintenance Due (7d)" accent={maintenanceDueCount > 0 ? 'amber' : 'green'} href={deptSafeHref('work-orders.index', deptPrefixes)} />
            </div>

            {/* Pending Tasks -- Universal Task Engine Dashboard integration
                (v1.6.4). Real query (Task::assignedTo($user)->openStatus()),
                not placeholder data. Only rendered when the user actually
                has open tasks assigned, so this doesn't add an empty
                "0 tasks" card to every dashboard for users who don't use
                the Task Engine. */}
            {pendingTasks.length > 0 && (
                <Card className="mt-4">
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <CardTitle>Pending Tasks</CardTitle>
                        <Link href={route('tasks.index')} className="text-xs font-medium text-brand-600 hover:underline">View All</Link>
                    </CardHeader>
                    <CardContent className="divide-y divide-graphite-100 p-0">
                        {pendingTasks.map((task) => (
                            <Link
                                key={task.id}
                                href={route('tasks.show', task.id)}
                                className="flex items-center justify-between gap-3 px-5 py-3 text-sm transition-colors hover:bg-graphite-50"
                            >
                                <span className="min-w-0 flex-1 truncate font-medium text-graphite-800">{task.title}</span>
                                {/* v2.15.0 (Product UI/UX Finalization, Part 12): was a
                                    hand-rolled priority color map living right next to a
                                    StatusBadge import used elsewhere in this same file --
                                    consolidated onto the one shared status/priority
                                    vocabulary so "high" reads as warning (amber), not the
                                    same destructive red as "critical". */}
                                <StatusBadge value={task.priority} />
                                <StatusBadge value={task.status} />
                                <span className="w-24 shrink-0 text-right text-xs text-graphite-400">
                                    {task.due_date ? new Date(task.due_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' }) : 'No due date'}
                                </span>
                            </Link>
                        ))}
                    </CardContent>
                </Card>
            )}

            {/* Employee Import (v1.6.8) -- only shown when there's
                actually something to complete, same "don't show an
                empty card" pattern as Pending Tasks above. */}
            {employeesNeedCompletionCount > 0 && (
                <Card className="mt-4">
                    <CardContent className="flex items-center justify-between gap-3 p-3.5">
                        <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-600">
                                <UserCog className="h-4 w-4" />
                            </div>
                            <div>
                                <p className="text-[13px] font-semibold text-graphite-800">Employee Profiles Need Completion</p>
                                <p className="text-xs text-graphite-500">{employeesNeedCompletionCount} employee{employeesNeedCompletionCount !== 1 ? 's' : ''}</p>
                            </div>
                        </div>
                        <Link href={route('employees.index', { profile_status: 'needs_completion' })} className="flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline">
                            View <ArrowRight className="h-3.5 w-3.5" />
                        </Link>
                    </CardContent>
                </Card>
            )}

            {/* 2. KPI Summary -- compact grid, secondary to the cards above.
                Fully data-driven: every card comes from an active,
                dashboard-visible KpiCategory row (icon/color/name all
                admin-configurable in Settings > KPI Categories) -- adding
                a new category here requires zero code changes. Each card
                links into the KPI Records list pre-filtered to that
                category + the current period/company. */}
            <div className="mt-4">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-graphite-400">KPI Summary</p>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-8">
                    {summary.categories.map((c) => (
                        <KpiSummaryCard
                            key={c.id}
                            label={c.short_label}
                            value={c.total}
                            isNegative={c.is_negative}
                            icon={c.icon}
                            color={c.color}
                            compact
                            href={route('kpi-records.index', { year: filters.year, month: filters.month, company_id: filters.company_id, category_id: c.id })}
                        />
                    ))}
                </div>
            </div>

            {/* 3. Charts -- the visual focus of the page */}
            <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-5">
                <Card className="lg:col-span-3">
                    <CardHeader>
                        <CardTitle>Monthly Trend</CardTitle>
                        <CardDescription>KPI occurrences by category across {filters.year}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="h-80">
                            <Line
                                data={trendData}
                                options={{
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
                                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                                }}
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Employees by Department</CardTitle>
                        <CardDescription>Active headcount distribution</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="h-80">
                            {departmentDistribution.length === 0 ? (
                                <p className="flex h-full items-center justify-center text-sm text-graphite-400">No data yet.</p>
                            ) : (
                                <Pie
                                    data={pieData}
                                    options={{
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
                                    }}
                                />
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* 4. Secondary info -- de-emphasized, further down the page.
                v1.6.3: added Quick Actions using real, existing create
                pages. Pending Tasks is rendered above (§ "Pending Tasks"
                card) now that the Universal Task Engine is real data --
                this comment previously said neither existed yet; both do
                now, see v2.2.0's note on the Quick Actions card itself for
                what changed there. Pending Approval intentionally lives on
                Work Center instead of duplicating it here (one
                implementation, see WorkCenterService). */}
            {/* v1.11.1 (Final Production Readiness Pass, Part 4/5/6),
                widened v1.11.6 once Man-Hours became real data: Calendar,
                Man-Power, and Man-Hours widgets, required on the MAIN
                Dashboard specifically (not only the standalone Calendar
                page/HSE Overview). Man-Power and Man-Hours are shown as
                two SEPARATE cards -- see DashboardController's own doc
                comment on `manhours`. */}
            <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                {/* v1.11.3: was hand-duplicated inline markup, byte-for-byte
                    the same shape as DepartmentCalendarWidget -- now reuses
                    that shared component directly (see docs/CONVENTIONS.md
                    for why this was worth fixing: a shared component that
                    only 5 of 6 intended callers actually use isn't fully
                    shared). Data/query unchanged -- still managementEvents(). */}
                <DepartmentCalendarWidget
                    events={upcomingEvents}
                    title="Management Calendar"
                    description="Next 14 days -- events explicitly marked for management, plus permits & milestones"
                />

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2"><Users2 className="h-3.5 w-3.5 text-graphite-400" /> Man-Power</CardTitle>
                        <CardDescription>Workforce currently on record.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-3">
                        <div className="rounded-lg border border-graphite-100 p-3 dark:border-slate-800">
                            <p className="text-base font-semibold text-graphite-900 dark:text-slate-50">{formatNumber(manpower.active_employees)}</p>
                            <p className="text-xs text-graphite-400">Active Employees</p>
                        </div>
                        <div className="rounded-lg border border-graphite-100 p-3 dark:border-slate-800">
                            <p className="text-base font-semibold text-graphite-900 dark:text-slate-50">{formatNumber(manpower.on_shift_today)}</p>
                            <p className="text-xs text-graphite-400">On Shift Today</p>
                        </div>
                    </CardContent>
                </Card>

                {/* v1.11.6 -- Man-Hours shown as a SEPARATE concept from
                    Man-Power above, backed by the real ManHourLog record.
                    v1.11.10 (Part 7): when NO period has any record at all
                    (nothing has ever been logged), the three-tile grid is
                    replaced with one explicit "Belum ada data Man-Hour"
                    empty state instead of three bare "—" tiles -- clearer
                    than a dash that a viewer could misread as a zero.
                    Partial data (e.g. today logged, YTD not) still shows
                    "—" per-tile since the page as a whole isn't empty. */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2"><Clock className="h-3.5 w-3.5 text-graphite-400" /> Man-Hours</CardTitle>
                        <CardDescription>Actual worked hours, entered via Man-Hour (HR).</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {manhours?.today == null && manhours?.this_month == null && manhours?.ytd == null ? (
                            <p className="py-3 text-center text-xs text-graphite-400">Belum ada data Man-Hour.</p>
                        ) : (
                            <div className="grid grid-cols-3 gap-3">
                                <div className="rounded-lg border border-graphite-100 p-3 text-center dark:border-slate-800">
                                    <p className="text-base font-semibold text-graphite-900 dark:text-slate-50">{manhours?.today ?? '—'}</p>
                                    <p className="text-xs text-graphite-400">Today</p>
                                </div>
                                <div className="rounded-lg border border-graphite-100 p-3 text-center dark:border-slate-800">
                                    <p className="text-base font-semibold text-graphite-900 dark:text-slate-50">{manhours?.this_month ?? '—'}</p>
                                    <p className="text-xs text-graphite-400">This Month</p>
                                </div>
                                <div className="rounded-lg border border-graphite-100 p-3 text-center dark:border-slate-800">
                                    <p className="text-base font-semibold text-graphite-900 dark:text-slate-50">{manhours?.ytd ?? '—'}</p>
                                    <p className="text-xs text-graphite-400">YTD</p>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* v1.11.3.2 (Priority Pass Part 4) -- Management Summary. Explicit
                product rule: cross-department project visibility belongs on
                the Main Dashboard ONCE, not repeated in every department
                Overview (HSE/HR/Logistics/Warehouse Overviews show only their
                own department's data). Real data only -- Project.manager_id
                and Milestone.status/target_date already exist; progress is a
                real milestone-completion percentage, not fabricated. */}
            <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <div>
                            <CardTitle className="flex items-center gap-2"><FolderKanban className="h-3.5 w-3.5 text-graphite-400" /> Project Portfolio</CardTitle>
                            <CardDescription>Active projects across all companies</CardDescription>
                        </div>
                        <Link href={route('projects.index')} className="text-xs font-medium text-brand-600 hover:underline">View all</Link>
                    </CardHeader>
                    <CardContent>
                        {projectSummary.length === 0 ? (
                            <p className="py-6 text-center text-sm text-graphite-400">No active projects.</p>
                        ) : (
                            <div className="divide-y divide-graphite-100 dark:divide-slate-800">
                                {projectSummary.map((p) => (
                                    <Link key={p.id} href={route('projects.show', p.id)} className="flex items-center justify-between gap-2 py-2 text-sm hover:text-brand-700">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-medium text-graphite-700 dark:text-slate-200">{p.name}</p>
                                            <p className="truncate text-xs text-graphite-400">{p.manager || 'No manager assigned'}</p>
                                        </div>
                                        <span className="shrink-0 text-xs text-graphite-400">{p.progress_percent === null ? '—' : `${p.progress_percent}%`}</span>
                                        <StatusBadge value={p.status} />
                                    </Link>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <div>
                            <CardTitle className="flex items-center gap-2"><Flag className="h-3.5 w-3.5 text-graphite-400" /> Upcoming Milestones</CardTitle>
                            <CardDescription>Across all active projects</CardDescription>
                        </div>
                        <Link href={route('milestones.index')} className="text-xs font-medium text-brand-600 hover:underline">View all</Link>
                    </CardHeader>
                    <CardContent>
                        {upcomingMilestones.length === 0 ? (
                            <p className="py-6 text-center text-sm text-graphite-400">No upcoming milestones.</p>
                        ) : (
                            <div className="divide-y divide-graphite-100 dark:divide-slate-800">
                                {upcomingMilestones.map((m) => (
                                    <div key={m.id} className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-medium text-graphite-700 dark:text-slate-200">{m.title}</p>
                                            <p className="truncate text-xs text-graphite-400">{m.project?.name}</p>
                                        </div>
                                        <span className="shrink-0 text-xs text-graphite-400">{new Date(m.target_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2"><Activity className="h-3.5 w-3.5 text-graphite-400" /> Today's Activities</CardTitle>
                        <CardDescription>KPI records logged today</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {todaysActivities.length === 0 ? (
                            <p className="py-6 text-center text-sm text-graphite-400">No activity logged today.</p>
                        ) : (
                            <div className="divide-y divide-graphite-100">
                                {todaysActivities.map((a) => (
                                    <div key={a.id} className="flex items-center justify-between py-2.5 text-sm">
                                        <span className="font-medium text-graphite-800">{a.employee_name}</span>
                                        <span className="text-xs text-graphite-400">{a.category}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2"><Bell className="h-3.5 w-3.5 text-graphite-400" /> Upcoming Reminder</CardTitle>
                        <CardDescription>Projects ending within the next 14 days</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {upcomingReminders.length === 0 ? (
                            <p className="py-6 text-center text-sm text-graphite-400">No upcoming reminders.</p>
                        ) : (
                            <div className="space-y-2">
                                {upcomingReminders.map((r) => (
                                    <Link
                                        key={r.project_id}
                                        href={route('projects.show', r.project_id)}
                                        className="block rounded-lg border border-graphite-100 px-3 py-2 text-sm text-graphite-700 hover:bg-graphite-50"
                                    >
                                        {r.label}
                                    </Link>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2"><Plus className="h-3.5 w-3.5 text-graphite-400" /> Quick Actions</CardTitle>
                        {/* v2.2.0 (IOMS OS Ecosystem pass, Part 5): this card used
                            to be 4 links, hardcoded, shown to every user
                            regardless of role/department/module. Now driven by
                            `quickActions` (WorkCenterService::quickActionsFor()),
                            the same list Work Center's own Quick Actions bar
                            uses -- module-gated, role-gated, and department-
                            tagged for Department Users. */}
                        <CardDescription>Aksi cepat sesuai peran dan departemen Anda</CardDescription>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-2">
                        {quickActions?.length > 0 ? (
                            quickActions.map((action) => {
                                const Icon = QUICK_ACTION_ICONS[action.icon] || Plus;
                                return (
                                    <Button key={action.url + action.label} variant="outline" size="sm" asChild>
                                        <Link href={action.url}><Icon className="h-3.5 w-3.5" /> {action.label}</Link>
                                    </Button>
                                );
                            })
                        ) : (
                            <p className="col-span-2 text-xs text-graphite-400">Belum ada aksi cepat yang tersedia untuk peran Anda.</p>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Recent Daily Reports / Recent Employee Changes -- ported
                from the retired Home page (v1.9.0), the two feeds that
                weren't already covered elsewhere on this Dashboard
                (Today's Activities above is KPI records specifically). */}
            <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
                <FeedCard
                    icon={ClipboardCheck}
                    title="Recent Daily Reports"
                    viewAllHref={route('daily-reports.index')}
                    items={recentDailyReports}
                    empty="No daily reports submitted yet."
                    renderItem={(r) => (
                        <>
                            <span className="font-medium text-graphite-700">{r.project_name}</span>
                            <span className="text-graphite-400">{r.department_name}</span>
                            <span className="text-xs text-graphite-400">{r.date}</span>
                        </>
                    )}
                />

                <FeedCard
                    icon={UserCog}
                    title="Recent Employee Changes"
                    viewAllHref={route('employees.index')}
                    items={recentEmployeeChanges}
                    empty="No recent employee changes."
                    renderItem={(log) => (
                        <>
                            <span className="min-w-0 flex-1 truncate text-graphite-700">{log.description}</span>
                            <span className="shrink-0 text-xs text-graphite-400">{log.when}</span>
                        </>
                    )}
                />
            </div>

            <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <LeaderboardCard
                    icon={Flame}
                    label="Top Department (Incidents)"
                    value={leaderboards.top_department_incidents?.name ?? '—'}
                    sub={leaderboards.top_department_incidents ? `${formatNumber(leaderboards.top_department_incidents.total)} incidents` : 'No incidents recorded'}
                />
                <LeaderboardCard
                    icon={Trophy}
                    label="Most Active Employee"
                    value={leaderboards.most_active_employee?.name ?? '—'}
                    sub={leaderboards.most_active_employee ? `${formatNumber(leaderboards.most_active_employee.total)} activities` : '—'}
                />
                <LeaderboardCard
                    icon={ClipboardList}
                    label="Most BBS Reports"
                    value={leaderboards.most_bbs_report?.name ?? '—'}
                    sub={leaderboards.most_bbs_report ? `${formatNumber(leaderboards.most_bbs_report.total)} reports` : '—'}
                />
                <LeaderboardCard
                    icon={Users2}
                    label="Most TBM Attendance"
                    value={leaderboards.most_tbm_attendance?.name ?? '—'}
                    sub={leaderboards.most_tbm_attendance ? `${formatNumber(leaderboards.most_tbm_attendance.total)} sessions` : '—'}
                />
            </div>

            {/* Top Department Workload (v1.6.1) -- a ranked list, so it
                needs its own row rather than squeezing into the 4-column
                single-stat grid above. Real KPI activity volume per
                department (see DashboardStatsService::leaderboards()); no
                fabricated "Tasks" metric, since no task-tracking module
                exists yet. */}
            <Card className="mt-3 rounded-2xl bg-white/85 backdrop-blur-sm">
                <CardHeader>
                    <CardTitle className="flex items-center gap-2"><Building2 className="h-3.5 w-3.5 text-graphite-400" /> Top Department Workload</CardTitle>
                    <CardDescription>KPI activity volume by department this year</CardDescription>
                </CardHeader>
                <CardContent>
                    {leaderboards.top_departments_workload?.length > 0 ? (
                        <div className="divide-y divide-graphite-100">
                            {leaderboards.top_departments_workload.map((dept, i) => (
                                <div key={dept.name} className="flex items-center justify-between py-2.5 text-sm">
                                    <div className="flex items-center gap-2.5">
                                        <span className="flex h-5 w-5 items-center justify-center rounded-full bg-graphite-100 text-[11px] font-semibold text-graphite-500">{i + 1}</span>
                                        <span className="font-medium text-graphite-800">{dept.name}</span>
                                    </div>
                                    <span className="text-graphite-500">{formatNumber(dept.total)} activities</span>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="py-6 text-center text-sm text-graphite-400">No KPI activity recorded yet.</p>
                    )}
                </CardContent>
            </Card>
            </div>
        </AuthenticatedLayout>
    );
}

/**
 * "Today's Summary" (v1.6.1) -- a management-facing operational snapshot
 * inside the Dashboard hero. Every figure here is real, already-tracked
 * data (no placeholder numbers): Employees and Active Projects reuse the
 * same values as the primary cards below; Lost Time Incidents comes from
 * the current period's LTI KPI category total; "PPE Alerts" is the same
 * expiring/expired count already surfacing as the topbar notification
 * badge. There is no "Pending Inspections" figure -- an Inspection
 * module doesn't exist yet (see ROADMAP.md's v2.0 plans) -- PPE Alerts
 * was used instead of inventing a number that isn't backed by real data.
 */
/**
 * v1.11.9 (Enterprise UI/UX Refinement Part 1): this used to also show
 * Employees + Active Projects -- the EXACT two metrics the "Primary
 * cards" StatCard grid immediately below it already shows, so the very
 * first viewport displayed the same two numbers twice. Consolidated down
 * to what's actually unique here: the greeting, and the two SAFETY-
 * relevant alerts (LTI, PPE) that appear nowhere else on this page --
 * nothing informative was removed, only the literal duplicate.
 */
function HeroSummary({ name, now, ltiCount, ppeAlertCount }) {
    const hasWarnings = ppeAlertCount > 0 || ltiCount > 0;

    return (
        <Card className="border-graphite-200 bg-white/70 shadow-card backdrop-blur-sm">
            <CardContent className="p-3.5">
                <h2 className="text-[13px] font-bold text-graphite-900">{greetingFor(now)}, {name} 👋</h2>
                <p className="mt-1 text-[11px] font-semibold uppercase tracking-wide text-graphite-400">Today's Alerts</p>

                <div className="mt-3 grid grid-cols-2 gap-3">
                    <SummaryStat icon={AlertTriangle} value={formatNumber(ltiCount)} label="Lost Time Incidents" accent={ltiCount > 0 ? 'red' : null} />
                    <SummaryStat icon={HardHat} value={formatNumber(ppeAlertCount)} label="PPE Alerts" accent={ppeAlertCount > 0 ? 'amber' : null} href={route('ppe.dashboard')} />
                </div>

                {/* Alert deliberately kept visually secondary (v1.6.7 Beta
                    final polish) -- smaller icon and text, muted color,
                    so it reads as a footnote to the statistics above it
                    rather than competing with them. */}
                <div className="mt-3 flex items-start gap-1.5 rounded-lg border border-graphite-100 bg-graphite-50/60 px-3 py-1.5 text-[11px]">
                    {hasWarnings ? (
                        <>
                            <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-500" />
                            <span className="text-graphite-600">
                                {ppeAlertCount > 0 && <>⚠️ {ppeAlertCount} PPE item(s) require attention. </>}
                                {ltiCount > 0 && <>⚠️ {ltiCount} Lost Time Incident(s) recorded this period.</>}
                            </span>
                        </>
                    ) : (
                        <>
                            <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-500" />
                            <span className="text-graphite-600">✅ Great job! No critical issues detected today.</span>
                        </>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function SummaryStat({ icon: Icon, value, label, accent, href }) {
    const color = accent === 'red' ? 'text-red-600' : accent === 'amber' ? 'text-amber-600' : 'text-graphite-900';
    const content = (
        <div className={cn('flex items-center gap-2 rounded-lg p-1 -m-1 transition-colors duration-200', href && 'hover:bg-graphite-50 cursor-pointer')}>
            <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-graphite-100 text-graphite-500">
                <Icon className="h-3.5 w-3.5" />
            </div>
            <div className="min-w-0 space-y-0.5">
                <p className={cn('text-sm font-semibold leading-tight', color)}>{value}</p>
                <p className="truncate text-[11px] text-graphite-400">{label}</p>
            </div>
        </div>
    );
    return href ? <Link href={href}>{content}</Link> : content;
}

function LeaderboardCard({ icon: Icon, label, value, sub }) {
    return (
        <Card>
            <CardContent className="p-4">
                <div className="mb-2 flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                    <Icon className="h-4 w-4" />
                </div>
                <p className="text-[11px] font-medium uppercase tracking-wide text-graphite-400">{label}</p>
                <p className="truncate text-sm font-semibold text-graphite-800">{value}</p>
                <p className="text-xs text-graphite-400">{sub}</p>
            </CardContent>
        </Card>
    );
}

/** Ported from the retired Home page (v1.9.0) -- unchanged. */
function FeedCard({ icon: Icon, title, viewAllHref, items, empty, renderItem }) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <CardTitle className="flex items-center gap-2">
                    <Icon className="h-4 w-4 text-graphite-400" /> {title}
                </CardTitle>
                <Link href={viewAllHref} className="flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                    View all <ArrowRight className="h-3 w-3" />
                </Link>
            </CardHeader>
            <CardContent>
                {items.length === 0 ? (
                    <p className="py-6 text-center text-sm text-graphite-400">{empty}</p>
                ) : (
                    <ul className="divide-y divide-graphite-100">
                        {items.map((item) => (
                            <li key={item.id} className="flex items-center justify-between gap-2 py-2.5 text-sm">
                                {renderItem(item)}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
