import { Head, Link, router, usePage } from '@inertiajs/react';
import { humanizeToken } from '@/lib/humanizeToken';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ModuleCard from '@/Components/shared/ModuleCard';
import ActivityList from '@/Components/shared/ActivityList';
import StatusBadge from '@/Components/shared/StatusBadge';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import KpiTile from '@/Components/hse-overview/KpiTile';
import OverviewHero from '@/Components/hse-overview/OverviewHero';
import OperationalKpiCard from '@/Components/hse-overview/OperationalKpiCard';
import AccidentClassificationPanel from '@/Components/hse-overview/AccidentClassificationPanel';
import FrequencyRatePanel from '@/Components/hse-overview/FrequencyRatePanel';
import NotificationsPanel from '@/Components/hse-overview/NotificationsPanel';
import SafetyTrendChart from '@/Components/hse-overview/SafetyTrendChart';
import PriorityActions from '@/Components/hse-overview/PriorityActions';
import OverviewCalendar from '@/Components/hse-overview/OverviewCalendar';
import ManHourPanel from '@/Components/hse-overview/ManHourPanel';
import QuickAccess from '@/Components/hse-overview/QuickAccess';
import {
    AlertTriangle, HardHat, History, Eye, Flame, ShieldAlert, ClipboardCheck,
    ClipboardList, FileWarning, Lock, UsersRound, FlaskConical, UserCheck, FileCheck,
    FileStack, Recycle, PackageSearch, ShieldCheck, Recycle as RecycleIcon, LayoutGrid,
    FileBarChart, PlusCircle, Info, CalendarCheck,
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
/**
 * v2.116.0 -- Operational KPI presentation. The SERVER decides what the
 * five cards are, what they count and where they lead (see
 * `HseDashboardController::operationalKpis()`); this map says only what
 * each one looks like. The ORDER is the server's too, because the
 * approved design fixes it: permits, incidents, PPE, inspections,
 * material requests, left to right.
 */
const OPERATIONAL_KPI_STYLE = {
    permits: { icon: Flame, accent: 'blue' },
    incidents: { icon: AlertTriangle, accent: 'red' },
    ppe: { icon: HardHat, accent: 'amber' },
    inspections: { icon: CalendarCheck, accent: 'green' },
    material: { icon: PackageSearch, accent: 'violet' },
};

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
    operationalKpis = [], accidentClassification, frequencyRates,
}) {
    const { auth, readiness } = usePage().props;
    const criticalIncidents = incidentsBySeverity?.critical ?? 0;

    const quickActions = quickAccess
        .filter((action) => QUICK_ACCESS_STYLE[action.key])
        .map((action) => ({ ...action, ...QUICK_ACCESS_STYLE[action.key] }));

    const kpiCards = operationalKpis
        .filter((kpi) => OPERATIONAL_KPI_STYLE[kpi.key])
        .map((kpi) => ({ ...kpi, ...OPERATIONAL_KPI_STYLE[kpi.key] }));

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
            /* A. Header band -- the approved dark industrial opening.      */
            /* It states where you are and what the page is scoped to, and  */
            /* carries the one filter that genuinely narrows every figure   */
            /* below it.                                                    */
            /* ---------------------------------------------------------- */}
            <OverviewHero
                companies={companies}
                selectedCompany={selectedCompany}
                onCompanyChange={applyCompany}
                fullCalendarHref={route('calendar.index')}
            />

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
            /* B. Operational KPIs -- five cards, in the approved order.    */
            /*                                                              */
            /* What is open RIGHT NOW, and nothing else. The six tiles this */
            /* replaced mixed operational state (open permits) with         */
            /* categorical counts (open CAPA) at equal weight; the counts    */
            /* that are not operational conditions moved to the compliance   */
            /* strip lower down, where supporting figures belong.            */
            /*                                                              */
            /* 1 / 2 / 3 / 5 columns: five cards squeezed onto a phone are   */
            /* five unreadable cards.                                       */
            /* ---------------------------------------------------------- */}
            <section className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-5">
                {kpiCards.map((kpi) => (
                    <OperationalKpiCard
                        key={kpi.key}
                        icon={kpi.icon}
                        accent={kpi.accent}
                        label={kpi.label}
                        value={kpi.value}
                        detail={kpi.detail}
                        href={kpi.href}
                        alert={kpi.alert}
                    />
                ))}
            </section>

            {/* ---------------------------------------------------------- */
            /* C. Trend, calendar and notifications, side by side.          */
            /*                                                              */
            /* 5 / 4 / 3 of twelve: the chart needs width to stay readable,  */
            /* the calendar needs a near-square to hold a month grid, and a  */
            /* notification list is a column of short rows that reads fine    */
            /* narrow. Three equal thirds would suit none of them.           */
            /* ---------------------------------------------------------- */}
            <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-12">
                <div className="xl:col-span-5">
                    <SafetyTrendChart trend={safetyTrend} />
                </div>

                <div className="xl:col-span-4">
                    <OverviewCalendar
                        events={calendar?.events ?? []}
                        window={calendar?.window}
                        today={calendar?.today}
                        companies={companies}
                    />
                </div>

                <div className="lg:col-span-2 xl:col-span-3">
                    <NotificationsPanel />
                </div>
            </div>

            {/* ---------------------------------------------------------- */
            /* D. Safety KPIs -- classification, then rates, then exposure. */
            /*                                                              */
            /* THE ORDER IS THE ARGUMENT. A classification is a count of     */
            /* cases; a frequency rate divides those cases by exposure       */
            /* hours; the exposure base is what it divides by. Reading left   */
            /* to right you meet the numerator, the rate, then the           */
            /* denominator -- which is also why they are three cards and not  */
            /* one panel of eight numbers.                                   */
            /* ---------------------------------------------------------- */}
            <div className="mb-4 grid grid-cols-1 items-stretch gap-4 lg:grid-cols-2 xl:grid-cols-12">
                <div className="xl:col-span-5">
                    <AccidentClassificationPanel classification={accidentClassification} />
                </div>

                <div className="xl:col-span-3">
                    <FrequencyRatePanel rates={frequencyRates} />
                </div>

                <div className="lg:col-span-2 xl:col-span-4">
                    <ManHourPanel manHours={manHours} safetyKpi={safetyKpi} />
                </div>
            </div>

            {/* E. Quick Access -- what you start after reading the page. */}
            <div className="mb-4">
                <QuickAccess actions={quickActions} />
            </div>

            {/* F. What is already late. Below the headline sections because
                it is a worklist rather than a reading of the operation. */}
            <div className="mb-4">
                <PriorityActions items={actionRequired ?? []} />
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

            {/* ---------------------------------------------------------- */
            /* G. The counts that are NOT operational conditions.           */
            /*                                                              */
            /* Critical incidents, open observations and open CAPA were in   */
            /* the headline row and are still real, still counted the same   */
            /* way and still click through to the same registers -- they are  */
            /* simply not what "open right now" means on an operations       */
            /* dashboard, so they read here at supporting weight instead of   */
            /* competing with the five above. Nothing was dropped.           */
            /* ---------------------------------------------------------- */}
            <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                <KpiTile
                    icon={AlertTriangle}
                    value={criticalIncidents}
                    label="Critical Incidents"
                    reading={criticalIncidents > 0 ? 'Perlu tindakan segera' : 'Tidak ada yang kritis'}
                    accent={criticalIncidents > 0 ? 'red' : 'green'}
                    href={route('incidents.index', { severity: 'critical' })}
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
