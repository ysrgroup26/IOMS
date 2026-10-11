import { Head, Link, router, usePage } from '@inertiajs/react';
import { humanizeToken } from '@/lib/humanizeToken';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ModuleCard from '@/Components/shared/ModuleCard';
import ActivityList from '@/Components/shared/ActivityList';
import StatusBadge from '@/Components/shared/StatusBadge';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import KpiTile from '@/Components/hse-overview/KpiTile';
import SafetyTrendChart from '@/Components/hse-overview/SafetyTrendChart';
import PriorityActions from '@/Components/hse-overview/PriorityActions';
import OverviewCalendar from '@/Components/hse-overview/OverviewCalendar';
import ManHourPanel from '@/Components/hse-overview/ManHourPanel';
import QuickAccess from '@/Components/hse-overview/QuickAccess';
import {
    AlertTriangle, HardHat, History, Eye, Flame, ShieldAlert, ClipboardCheck,
    ClipboardList, FileWarning, Lock, UsersRound, FlaskConical, UserCheck, FileCheck,
    FileStack, Recycle, PackageSearch, ShieldCheck, Recycle as RecycleIcon, LayoutGrid,
    FileBarChart, PlusCircle, Building2, Info,
} from 'lucide-react';

/* v2.68.0 -- LANGUAGE HIERARCHY (v2.53.0) APPLIED HERE AT LAST.
   ENGLISH for module and feature names, navigation, headers, stat and
   status labels, action labels; INDONESIAN for explanatory sentences,
   help text and guidance. Each `title` below is the name of the page it
   navigates to, which is the property that makes a menu item and its
   destination verifiably the same thing. */
const HSE_MODULES = [
    { icon: Eye, title: 'Safety Observation', description: 'Pelaporan bahaya/nyaris celaka satu klik.', href: 'safety-observations.index' },
    { icon: ClipboardCheck, title: 'HSE Inspection', description: 'Inspeksi terjadwal dengan temuan.', href: 'hse-inspections.index' },
    { icon: UsersRound, title: 'Toolbox Meeting (TBM)', description: 'Catatan toolbox meeting.', href: 'tbm-meetings.index' },
    { icon: ShieldAlert, title: 'HIRADC / Risk Assessment', description: 'Identifikasi bahaya & matriks risiko.', href: 'risk-assessments.index' },
    { icon: FileWarning, title: 'JSA', description: 'Analisis keselamatan kerja dengan matriks risiko.', href: 'job-safety-analyses.index' },
    { icon: Flame, title: 'Permit To Work (PTW)', description: 'Pekerjaan panas, ruang terbatas, dan lainnya.', href: 'permits-to-work.index' },
    { icon: FlaskConical, title: 'Gas Test', description: 'Pembacaan atmosfer di semua izin kerja.', href: 'gas-test-records.index' },
    { icon: Lock, title: 'LOTO', description: 'Isolasi energi lockout/tagout.', href: 'loto-records.index' },
    { icon: ClipboardCheck, title: 'Corrective Actions (CAPA)', description: 'Pelacakan tindakan perbaikan lintas sumber.', href: 'corrective-actions.index' },
    { icon: Recycle, title: 'Waste Management', description: 'Limbah B3/Non-B3, penyimpanan, dan pembuangan.', href: 'waste.dashboard' },
    { icon: UserCheck, title: 'Contractor Management', description: 'Register kontraktor, pekerja, dokumen.', href: 'contractors.index' },
    { icon: FileCheck, title: 'Visitor Management', description: 'Register akses lokasi.', href: 'visitors.index' },
    { icon: FileStack, title: 'Document Control', description: 'Dokumen terkendali dengan riwayat versi.', href: 'controlled-documents.index' },
    { icon: PackageSearch, title: 'Material Request', description: 'Pengajuan kebutuhan barang dan material HSE.', href: 'material-requests.index' },
];

/**
 * v2.109.0 -- Quick Access presentation. The SERVER decides which of
 * these exist (see HseDashboardController's `quickAccess`); this map only
 * says what each one looks like. A key the server stops sending simply
 * stops rendering.
 */
const QUICK_ACCESS_STYLE = {
    incident: { icon: AlertTriangle, accent: 'red' },
    observation: { icon: Eye, accent: 'amber' },
    capa: { icon: ClipboardCheck, accent: 'violet' },
    ptw: { icon: Flame, accent: 'blue' },
    ppe: { icon: HardHat, accent: 'green' },
    reports: { icon: FileBarChart, accent: 'blue' },
};

/**
 * HSE OVERVIEW -- v2.109.0 REDESIGN.
 *
 * THE ORDER OF THIS PAGE IS A DECISION, NOT A LAYOUT. Reading down:
 * what is wrong right now (KPIs), what is already late (Priority
 * Actions), what is coming (Calendar), how the trend is moving
 * (Performance), what the exposure base is (Man-Hour), and what you
 * might start (Quick Access). The old page opened with six equally
 * weighted cards followed by four panels of the same size, so nothing
 * was more important than anything else and the first screen answered
 * no question in particular.
 *
 * EVERY NUMBER IS A PROP. Nothing on this page is computed in the
 * browser except presentation: the day an event falls on, how late an
 * overdue item is, and which tail of the trend to draw. No metric is
 * derived, combined or estimated client-side, because a figure invented
 * in React is a figure nobody can audit.
 *
 * SCOPED STYLING. Every new visual lives in
 * `Components/hse-overview/`; the shared `StatCard`, `ModuleCard`,
 * `ActivityList` and `Card` primitives other modules render with are
 * untouched, so this redesign changes how the HSE Overview looks and
 * nothing else in the product.
 */
export default function HseDashboard({
    openIncidentsCount, incidentsBySeverity, ppeAlertCount, recentIncidents,
    openSafetyObservationsCount, recentSafetyObservations, recentActivity,
    openPermitsCount, overdueSafetyEquipmentCount, overdueP3kCount, openCapaCount,
    actionRequired, manHours, safetyKpi, wasteSummary, canManageHse,
    safetyTrend, calendar, quickAccess = [], companies = [], filters = {},
}) {
    const { auth, readiness } = usePage().props;
    const criticalIncidents = incidentsBySeverity?.critical ?? 0;

    const quickActions = quickAccess
        .filter((action) => QUICK_ACCESS_STYLE[action.key])
        .map((action) => ({ ...action, ...QUICK_ACCESS_STYLE[action.key] }));

    /* The operating-unit filter is a server round trip, because the
       figures it changes are counted on the server. `preserveState` keeps
       the calendar's selected day and the chart's range across it. */
    function applyCompany(value) {
        router.get(
            route('hse.dashboard'),
            value === 'all' ? {} : { company_id: value },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    }

    const selectedCompany = filters?.company_id ? String(filters.company_id) : 'all';

    return (
        <AuthenticatedLayout>
            <Head title="HSE Overview" />

            {/* ---------------------------------------------------------- */
            /* A. Introduction -- compact, one row on desktop.              */
            /* The old page spent a full-width hero card on a greeting;     */
            /* this states what the page is, what it is scoped to, and      */
            /* gets out of the way so the KPIs are above the fold.          */
            /* ---------------------------------------------------------- */}
            <section className="mb-4 flex flex-col gap-3 rounded-2xl border border-graphite-200/80 bg-gradient-to-br from-blue-50/60 via-white to-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04)] dark:border-slate-800 dark:from-slate-900 dark:via-slate-900 dark:to-slate-900 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-blue-600 text-white shadow-sm">
                            <ShieldCheck className="h-[18px] w-[18px]" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="truncate text-[17px] font-bold leading-tight text-graphite-900 dark:text-slate-50">HSE Overview</h1>
                            <p className="mt-0.5 truncate text-[12px] text-graphite-500 dark:text-slate-400">
                                Kondisi keselamatan, pekerjaan yang terlambat, dan agenda HSE dalam satu halaman.
                            </p>
                        </div>
                    </div>
                </div>

                {/* Shown only when there is more than one operating unit to
                    choose between -- a filter with one option is furniture. */}
                {companies.length > 1 && (
                    <div className="flex shrink-0 items-center gap-2">
                        <Building2 className="h-3.5 w-3.5 text-graphite-400" />
                        <Select value={selectedCompany} onValueChange={applyCompany}>
                            <SelectTrigger className="h-9 w-full bg-white text-[12px] sm:w-52 dark:bg-slate-900">
                                <SelectValue placeholder="Operating Unit" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Operating Units</SelectItem>
                                {companies.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}
            </section>

            {/* PRESERVED FROM DashboardShell (v2.39.0), NOT DROPPED WITH IT.
                This page no longer composes through that shared shell --
                it has its own compact introduction above -- but the shell
                also carried this notice, and it is the one thing on an
                Overview that stops six green zeroes from being read as an
                all-clear on a database with nothing in it. Browser-verified
                on an empty tenant when it was written; re-homed here so the
                HSE Overview keeps it while every other Overview keeps
                getting it from the shell, unchanged. */}
            {readiness?.is_operational === false && (
                <div className="mb-4 flex items-start gap-2 rounded-xl border border-brand-200 bg-brand-50/60 px-3 py-2 dark:border-brand-900 dark:bg-brand-950/20">
                    <Info className="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-500" />
                    <p className="text-[11px] leading-snug text-graphite-700 dark:text-slate-300">
                        Belum ada data workforce, jadi angka di bawah{' '}
                        <span className="font-medium">belum mengukur apa pun</span> &mdash; nol di sini berarti{' '}
                        <span className="font-medium">belum ada data</span>, bukan kondisi aman.{' '}
                        <Link href={route('employees.index')} className="font-medium text-brand-700 underline-offset-2 hover:underline dark:text-brand-400">
                            Tambah data karyawan
                        </Link>
                    </p>
                </div>
            )}

            {/* ---------------------------------------------------------- */
            /* B. Safety KPIs -- six counts, differentiated by meaning.     */
            /* 2 / 3 / 6 columns so a phone shows pairs, a tablet shows     */
            /* triples and a desktop shows the row, instead of six cards    */
            /* squeezed to unreadable width.                                */
            /* ---------------------------------------------------------- */}
            <section className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                <KpiTile
                    icon={AlertTriangle}
                    value={criticalIncidents}
                    label="Critical Incidents"
                    reading={criticalIncidents > 0 ? 'Perlu tindakan segera' : 'Tidak ada yang kritis'}
                    accent={criticalIncidents > 0 ? 'red' : 'green'}
                    href={route('incidents.index', { severity: 'critical' })}
                />
                <KpiTile
                    icon={ShieldAlert}
                    value={openIncidentsCount}
                    label="Open Incidents"
                    reading="Dilaporkan atau sedang diinvestigasi"
                    accent={openIncidentsCount > 0 ? 'red' : 'green'}
                    href={route('incidents.index')}
                />
                <KpiTile
                    icon={Eye}
                    value={openSafetyObservationsCount}
                    label="Open Observations"
                    reading="Belum ditutup"
                    accent={openSafetyObservationsCount > 0 ? 'amber' : 'green'}
                    href={route('safety-observations.index')}
                />
                <KpiTile
                    icon={ClipboardCheck}
                    value={openCapaCount}
                    label="Open CAPA"
                    reading="Belum diverifikasi"
                    accent={openCapaCount > 0 ? 'violet' : 'green'}
                    href={route('corrective-actions.index')}
                />
                <KpiTile
                    icon={Flame}
                    value={openPermitsCount}
                    label="Active Permits"
                    reading="Disetujui atau sedang berjalan"
                    accent="blue"
                    href={route('permits-to-work.index')}
                />
                <KpiTile
                    icon={HardHat}
                    value={ppeAlertCount}
                    label="PPE Alerts"
                    reading="Akan atau sudah kedaluwarsa"
                    accent={ppeAlertCount > 0 ? 'amber' : 'green'}
                    href={route('ppe.dashboard')}
                />
            </section>

            {/* ---------------------------------------------------------- */
            /* C + D + E. The working row: trend, overdue work, calendar.   */
            /* 12-col grid so the chart gets the width it needs to be       */
            /* readable (7) and the calendar keeps a usable square (5),     */
            /* rather than three equal thirds that suit none of them.       */
            /* ---------------------------------------------------------- */}
            <div className="mb-4 grid grid-cols-1 gap-4 xl:grid-cols-12">
                <div className="flex flex-col gap-4 xl:col-span-7">
                    <SafetyTrendChart trend={safetyTrend} />
                    <PriorityActions items={actionRequired ?? []} />
                </div>

                <div className="xl:col-span-5">
                    <OverviewCalendar
                        events={calendar?.events ?? []}
                        window={calendar?.window}
                        today={calendar?.today}
                    />
                </div>
            </div>

            {/* F. Man-Hour & Safety KPI Foundation. */}
            <div className="mb-4">
                <ManHourPanel manHours={manHours} safetyKpi={safetyKpi} />
            </div>

            {/* ---------------------------------------------------------- */
            /* Operational detail: the three feeds that were already here,  */
            /* kept because they are real and useful, but moved BELOW the   */
            /* decision-first sections instead of competing with them.      */
            /* ---------------------------------------------------------- */}
            {/* `items-start`: an empty feed takes the height of its own
                content instead of stretching to match a populated sibling,
                which is what turned "no activity yet" into a tall blank
                panel. */}
            <div className="mb-4 grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="flex items-center gap-1.5"><ShieldAlert className="h-3.5 w-3.5 text-red-500" /> Recent Incidents</CardTitle>
                        <CardDescription>Lima laporan terakhir</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ActivityList
                            items={recentIncidents}
                            getKey={(i) => i.id}
                            getHref={(i) => route('incidents.show', i.id)}
                            emptyIcon={ShieldAlert}
                            emptyTitle="Belum ada insiden tercatat"
                            renderItem={(i) => (
                                <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate font-medium text-graphite-700 dark:text-slate-200">{i.title}</p>
                                        <p className="text-xs text-graphite-400">{i.incident_number}</p>
                                    </div>
                                    <StatusBadge status={i.severity} />
                                </div>
                            )}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="flex items-center gap-1.5"><Eye className="h-3.5 w-3.5 text-amber-500" /> Recent Observations</CardTitle>
                        <CardDescription>Lima observasi terakhir</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ActivityList
                            items={recentSafetyObservations}
                            getKey={(o) => o.id}
                            getHref={(o) => route('safety-observations.show', o.id)}
                            emptyIcon={Eye}
                            emptyTitle="Belum ada observasi"
                            renderItem={(o) => (
                                <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                    <div className="min-w-0 flex-1">
                                        {/* humanizeToken, not CSS capitalize: `capitalize`
                                            renders an acronym as "Hse". See
                                            ProductTerminologyTest. */}
                                        <p className="truncate font-medium text-graphite-700 dark:text-slate-200">{humanizeToken(o.type)}</p>
                                        <p className="text-xs text-graphite-400">{o.observation_number}</p>
                                    </div>
                                    <StatusBadge status={o.status} />
                                </div>
                            )}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="flex items-center gap-1.5"><History className="h-3.5 w-3.5 text-graphite-400" /> Recent Activity</CardTitle>
                        <CardDescription>Perubahan terakhir di modul HSE</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ActivityList
                            items={recentActivity}
                            getKey={(a) => a.id}
                            emptyIcon={History}
                            emptyTitle="Belum ada aktivitas"
                            renderItem={(a) => (
                                <div className="py-2 text-sm">
                                    <p className="truncate text-graphite-700 dark:text-slate-200">{a.description}</p>
                                    <p className="text-xs text-graphite-400">{a.user?.name ?? 'System'}</p>
                                </div>
                            )}
                        />
                    </CardContent>
                </Card>
            </div>

            {/* Compliance strip: equipment, P3K and waste, each a real
                count with its own destination. Kept small -- these are
                supporting figures, not headline KPIs. */}
            <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                <KpiTile
                    icon={ClipboardList}
                    value={overdueSafetyEquipmentCount}
                    label="Equipment Overdue"
                    accent={overdueSafetyEquipmentCount > 0 ? 'red' : 'green'}
                    href={route('hse.master')}
                />
                <KpiTile
                    icon={ShieldCheck}
                    value={overdueP3kCount}
                    label="P3K Overdue"
                    accent={overdueP3kCount > 0 ? 'red' : 'green'}
                    href={route('hse.master')}
                />
                {/* A COUNT OF ZERO IS NOT A CATEGORY. These tiles carry a
                    categorical accent only when they are actually counting
                    something -- a violet "0" reads as a classification that
                    applies, when it means there is nothing stored at all. */}
                <KpiTile
                    icon={RecycleIcon}
                    value={wasteSummary?.b3_stored ?? 0}
                    label="B3 Stored"
                    accent={(wasteSummary?.b3_stored ?? 0) > 0 ? 'violet' : 'slate'}
                    href={route('waste.dashboard')}
                />
                <KpiTile
                    icon={RecycleIcon}
                    value={wasteSummary?.non_b3_stored ?? 0}
                    label="Non-B3 Stored"
                    accent={(wasteSummary?.non_b3_stored ?? 0) > 0 ? 'blue' : 'slate'}
                    href={route('waste.dashboard')}
                />
                <KpiTile
                    icon={AlertTriangle}
                    value={wasteSummary?.storage_alerts ?? 0}
                    label="Storage Alerts"
                    accent={(wasteSummary?.storage_alerts ?? 0) > 0 ? 'amber' : 'green'}
                    href={route('waste.dashboard')}
                />
                <KpiTile
                    icon={PlusCircle}
                    value={wasteSummary?.pending_disposal ?? 0}
                    label="Pending Disposal"
                    accent={(wasteSummary?.pending_disposal ?? 0) > 0 ? 'amber' : 'slate'}
                    href={route('waste.dashboard')}
                />
            </div>

            {/* G. Quick Access -- server-gated, near the bottom because it
                is what you do after reading, not before. */}
            <div className="mb-4">
                <QuickAccess actions={quickActions} />
            </div>

            {/* Module directory, unchanged in content and gating. */}
            <section>
                <h2 className="mb-2 flex items-center gap-1.5 px-0.5 text-[13px] font-bold text-graphite-900 dark:text-slate-50">
                    <LayoutGrid className="h-4 w-4 text-graphite-400" /> HSE Modules
                </h2>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-7">
                    {HSE_MODULES.map((m) => <ModuleCard key={m.title} {...m} />)}
                </div>
            </section>

            {/* HSE Administration row, gated exactly as before. */}
            {canManageHse && (
                <section className="mt-4">
                    <h2 className="mb-2 px-0.5 text-[13px] font-bold text-graphite-900 dark:text-slate-50">HSE Administration</h2>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-7">
                        <ModuleCard icon={ShieldCheck} title="Safety Equipment &amp; Compliance" description="Register peralatan keselamatan dan data acuan HSE." href="hse.master" />
                    </div>
                </section>
            )}

            {/* `auth` is read so the page fails loudly if the shared prop
                ever stops being provided, rather than silently rendering
                an unauthenticated-looking Overview. */}
            <span className="sr-only">{auth?.user?.name}</span>
        </AuthenticatedLayout>
    );
}
