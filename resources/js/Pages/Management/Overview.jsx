import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import StatCard from '@/Components/shared/StatCard';
import StatusBadge from '@/Components/shared/StatusBadge';
import { FigureGroup, Figure, Ranking, TrendBars, NoData, Panel, Distribution } from '@/Components/shared/ManagementPanels';
import {
    Users, ShieldAlert, ClipboardCheck, Flame, Building2,
    BarChart3, HardHat, UsersRound, PackageSearch, ArrowRight,
} from 'lucide-react';

/**
 * MANAGEMENT OVERVIEW (v2.83.0).
 *
 * WHAT → STATUS → PRIORITY → ACTION, top to bottom, and that order is the
 * page's whole argument:
 *
 *   1. SCALE      how big is the company (headcount, units, projects)
 *   2. SAFETY     the one status a safety-led business leads with
 *   3. COMPARE    which departments carry the work
 *   4. ATTENTION  what is overdue, with names against it
 *
 * It is deliberately NOT the Dashboard with different numbers. The
 * Dashboard answers "what is happening now" and is full of today's
 * activity; this page carries no live feed, no create button and no
 * per-record link list -- it carries positions, trends and the things
 * nobody has closed.
 *
 * Every figure is real (see ManagementInsightsService) and every section
 * states when its module has never been used rather than showing a
 * confident zero.
 */
export default function ManagementOverview({ summary, departments, hse, actions, period }) {
    const incidents = summary?.incidents ?? {};
    const capa = summary?.corrective_actions ?? {};
    const manHours = summary?.man_hours ?? {};

    const overdueTotal = (capa.overdue ?? 0)
        + (actions?.overdue_inspections?.safety_equipment ?? 0)
        + (actions?.overdue_inspections?.p3k_boxes ?? 0);

    const awaiting = actions?.awaiting_decision ?? {};
    const awaitingTotal = (awaiting.permits ?? 0) + (awaiting.material_requests ?? 0) + (awaiting.leave_requests ?? 0);

    return (
        <AuthenticatedLayout>
            <Head title="Management Overview" />
            <DashboardShell
                title="Management Overview"
                subtitle="Kondisi perusahaan secara keseluruhan dan hal-hal yang perlu perhatian management."
                actions={
                    <Link
                        href={route('management.kpi')}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-graphite-200 bg-white px-3 py-1.5 text-xs font-medium text-graphite-700 transition-colors hover:bg-graphite-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
                    >
                        <BarChart3 className="h-3.5 w-3.5" /> Company KPI
                    </Link>
                }
            >
                {/* LEVEL 1 -- SCALE AND STATUS. The only cards on the page:
                    these are the figures somebody reads in three seconds
                    before deciding which section to open. */}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <StatCard
                        icon={Users}
                        value={summary?.headcount?.available ? summary.headcount.active : '—'}
                        label="Active Workforce"
                        hint={summary?.headcount?.available ? `${summary.headcount.total} tercatat` : 'Belum ada data karyawan'}
                        href={route('management.workforce')}
                    />
                    <StatCard
                        icon={Building2}
                        value={summary?.operating_units ?? 0}
                        label="Operating Units"
                    />
                    <StatCard
                        icon={ShieldAlert}
                        value={incidents.available ? incidents.open : '—'}
                        label="Open Incidents"
                        accent={incidents.available ? (incidents.open > 0 ? 'red' : 'green') : undefined}
                        hint={incidents.available ? `${incidents.this_month} bulan ini` : 'Belum ada insiden tercatat'}
                        href={route('management.hse')}
                    />
                    <StatCard
                        icon={ClipboardCheck}
                        value={capa.available ? capa.open : '—'}
                        label="Open CAPA"
                        accent={capa.available ? (capa.overdue > 0 ? 'red' : capa.open > 0 ? 'amber' : 'green') : undefined}
                        hint={capa.available ? `${capa.overdue} terlambat` : 'Belum ada tindakan perbaikan'}
                        href={route('management.actions')}
                    />
                    <StatCard
                        icon={Flame}
                        value={summary?.permits?.available ? summary.permits.active : '—'}
                        label="Active Permits"
                        hint={summary?.permits?.available ? undefined : 'Belum ada izin kerja'}
                    />
                </div>

                {/* LEVEL 2 -- SAFETY POSITION. A trend, not a count: the
                    direction is the management-level fact, and the count is
                    already above. */}
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                    <Panel
                        className="lg:col-span-2"
                        title="Safety Trend"
                        description="Insiden dan observasi keselamatan selama enam bulan terakhir."
                        actions={
                            <Link href={route('management.hse')} className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                HSE Performance <ArrowRight className="h-3 w-3" />
                            </Link>
                        }
                    >
                        <TrendBars
                            points={hse?.trend}
                            series={[
                                { key: 'incidents', label: 'Incidents', className: 'bg-red-500/85' },
                                { key: 'observations', label: 'Observations', className: 'bg-brand-500/80' },
                            ]}
                            empty={(
                                <NoData
                                    title="No safety records yet"
                                    description="Grafik ini terisi setelah tim mencatat insiden atau observasi keselamatan."
                                />
                            )}
                        />
                    </Panel>

                    <FigureGroup
                        title="Safety Position"
                        description="Angka utama yang dibaca management setiap bulan."
                        columns={2}
                    >
                        <Figure
                            label="Days Since Incident"
                            value={incidents.days_since_last}
                            hint={incidents.days_since_last === null ? 'Belum pernah tercatat' : 'hari'}
                            tone={incidents.days_since_last === null ? 'neutral' : 'good'}
                            emphasis
                        />
                        <Figure
                            label="Overdue Items"
                            value={overdueTotal}
                            hint="CAPA dan inspeksi"
                            tone={overdueTotal > 0 ? 'bad' : 'good'}
                            emphasis
                            href={route('management.actions')}
                        />
                        <Figure
                            label="Awaiting Decision"
                            value={awaitingTotal}
                            hint="izin, permintaan, cuti"
                            tone={awaitingTotal > 0 ? 'warn' : 'neutral'}
                        />
                        <Figure
                            label="Toolbox Meetings"
                            value={hse?.toolbox_meetings ?? 0}
                            hint="enam bulan terakhir"
                        />
                        <Figure
                            label="Man-Hours"
                            value={manHours.available ? Math.round(manHours.regular).toLocaleString('id-ID') : null}
                            hint={manHours.available ? 'reguler, bulan ini' : 'Belum ada catatan man-hour'}
                        />
                        <Figure
                            label="Overtime"
                            value={manHours.available ? Math.round(manHours.overtime).toLocaleString('id-ID') : null}
                            hint={manHours.available ? 'jam, bulan ini' : '—'}
                            tone={manHours.available && manHours.overtime > manHours.regular * 0.25 ? 'warn' : 'neutral'}
                        />
                    </FigureGroup>
                </div>

                {/* LEVEL 3 -- DEPARTMENT COMPARISON. The centre of the page:
                    a company is its departments, and management's question
                    is which of them is carrying what. */}
                <Panel
                    title="Department Performance"
                    description={`Perbandingan antar department untuk periode ${period?.month ? `bulan ${period.month} ` : ''}${period?.year}.`}
                    actions={
                        <Link href={route('management.kpi')} className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                            KPI by Department <ArrowRight className="h-3 w-3" />
                        </Link>
                    }
                >
                    <Ranking
                        rows={departments}
                        columns={[
                            { key: 'name', label: 'Department' },
                            { key: 'code', label: 'Code', render: (row) => row.code ?? '—' },
                            { key: 'headcount', label: 'Headcount', align: 'right' },
                            { key: 'kpi_total', label: 'KPI Total', align: 'right' },
                            { key: 'open_requests', label: 'Open Requests', align: 'right' },
                        ]}
                        empty={(
                            <NoData
                                title="No departments configured"
                                description="Tambahkan department di Admin Space agar perbandingan antar department dapat ditampilkan."
                            />
                        )}
                    />
                </Panel>

                {/* LEVEL 4 -- ATTENTION. Rows with owners and dates, so the
                    page ends in something somebody can do. */}
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                    <Panel
                        className="lg:col-span-2"
                        title="Needs Attention"
                        description="Tindakan perbaikan yang belum selesai, paling mendesak di atas."
                        actions={
                            <Link href={route('management.actions')} className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                Outstanding Actions <ArrowRight className="h-3 w-3" />
                            </Link>
                        }
                    >
                        <Ranking
                            rows={actions?.corrective_actions?.rows}
                            columns={[
                                { key: 'action', label: 'Action', render: (row) => <span className="line-clamp-1">{row.action}</span> },
                                { key: 'assignee', label: 'Owner', render: (row) => row.assignee ?? '—' },
                                { key: 'status', label: 'Status', render: (row) => <StatusBadge value={row.status} /> },
                                {
                                    key: 'due_date',
                                    label: 'Due',
                                    align: 'right',
                                    render: (row) => row.due_date
                                        ? <span className={row.overdue ? 'font-semibold text-red-600 dark:text-red-400' : undefined}>{row.due_date}</span>
                                        : '—',
                                },
                            ]}
                            empty={(
                                <NoData
                                    title="Nothing outstanding"
                                    description="Belum ada tindakan perbaikan terbuka, atau modul CAPA belum digunakan."
                                />
                            )}
                        />
                    </Panel>

                    <Panel title="Open Incidents by Severity" description="Sebaran tingkat keparahan insiden yang masih terbuka.">
                        {Object.keys(hse?.by_severity ?? {}).length > 0
                            ? <Distribution data={hse.by_severity} />
                            : (
                                <NoData
                                    title="No open incidents"
                                    description="Tidak ada insiden terbuka saat ini."
                                />
                            )}
                    </Panel>
                </div>

                {/* The rest of the workspace, named rather than hidden behind
                    a sidebar the reader may not have opened. */}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    {[
                        { icon: BarChart3, label: 'Company KPI', href: route('management.kpi'), note: 'KPI perusahaan dan per department.' },
                        { icon: HardHat, label: 'HSE Performance', href: route('management.hse'), note: 'Tren keselamatan dan status dokumen.' },
                        { icon: UsersRound, label: 'Workforce', href: route('management.workforce'), note: 'Komposisi dan kapasitas tenaga kerja.' },
                        { icon: PackageSearch, label: 'Logistics & Inventory', href: route('management.logistics'), note: 'Stok, permintaan material, penerimaan.' },
                    ].map((entry) => (
                        <Link
                            key={entry.label}
                            href={entry.href}
                            className="group flex flex-col gap-1 rounded-xl border border-graphite-100 bg-white p-3 transition-colors hover:border-brand-200 hover:bg-steel-50/60 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-brand-900"
                        >
                            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-navy-800 to-brand-600 text-white">
                                <entry.icon className="h-4 w-4" />
                            </span>
                            <span className="mt-1 text-sm font-semibold text-graphite-900 dark:text-slate-100">{entry.label}</span>
                            <span className="text-[11px] text-graphite-400 dark:text-slate-500">{entry.note}</span>
                        </Link>
                    ))}
                </div>
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
