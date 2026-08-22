import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import StatCard from '@/Components/shared/StatCard';
import StatusBadge from '@/Components/shared/StatusBadge';
import ActivityList from '@/Components/shared/ActivityList';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import ModuleCard from '@/Components/shared/ModuleCard';
import DepartmentCalendarWidget from '@/Components/shared/DepartmentCalendarWidget';
import {
    FolderKanban, AlertTriangle, HardHat, History, Eye, Flame, ShieldAlert, ClipboardCheck,
    ClipboardList, FileWarning, Lock, UsersRound, FlaskConical, UserCheck, FileCheck, FileStack, Recycle,
} from 'lucide-react';

// v1.11.7 (Bahasa Indonesia Standardization, Part 4) -- translated per
// resources/js/lib/id.js's terminology map; hrefs unchanged.
const HSE_MODULES = [
    { icon: Eye, title: 'Observasi Keselamatan', description: 'Pelaporan bahaya/nyaris celaka satu klik.', href: 'safety-observations.index' },
    { icon: ClipboardCheck, title: 'Inspeksi HSE', description: 'Inspeksi terjadwal dengan temuan.', href: 'hse-inspections.index' },
    { icon: UsersRound, title: 'Rapat Keselamatan (TBM)', description: 'Catatan toolbox meeting.', href: 'tbm-meetings.index' },
    { icon: ShieldAlert, title: 'HIRADC / Penilaian Risiko', description: 'Identifikasi bahaya & matriks risiko.', href: 'risk-assessments.index' },
    { icon: FileWarning, title: 'JSA', description: 'Analisis keselamatan kerja dengan matriks risiko.', href: 'job-safety-analyses.index' },
    { icon: Flame, title: 'Izin Kerja (PTW)', description: 'Pekerjaan panas, ruang terbatas, dan lainnya.', href: 'permits-to-work.index' },
    { icon: FlaskConical, title: 'Uji Gas', description: 'Pembacaan atmosfer di semua izin kerja.', href: 'gas-test-records.index' },
    { icon: Lock, title: 'LOTO', description: 'Isolasi energi lockout/tagout.', href: 'loto-records.index' },
    { icon: ClipboardCheck, title: 'Tindakan Perbaikan (CAPA)', description: 'Pelacakan tindakan perbaikan lintas sumber.', href: 'corrective-actions.index' },
    { icon: Recycle, title: 'Pengelolaan Limbah', description: 'Limbah B3/Non-B3, penyimpanan, dan pembuangan.', href: 'waste.dashboard' },
    { icon: UserCheck, title: 'Manajemen Kontraktor', description: 'Register kontraktor, pekerja, dokumen.', href: 'contractors.index' },
    { icon: FileCheck, title: 'Manajemen Pengunjung', description: 'Register akses lokasi.', href: 'visitors.index' },
    { icon: FileStack, title: 'Kontrol Dokumen', description: 'Dokumen terkendali dengan riwayat versi.', href: 'controlled-documents.index' },
];

/**
 * HSE Dashboard (v1.10.0, redesigned v1.11.5 -- Dashboard UX Completion,
 * Phase 2). Restructured from "row of stat cards + row of list cards"
 * into an operational hierarchy: compact KPI strip -> Safety Performance
 * (status breakdown) -> Action Required (real overdue items, actionable/
 * clickable) -> HSE Activity -> Waste + Calendar. Every number here was
 * already real before this pass (see HseDashboardController's own doc
 * comment for the tenant-scoping history); this pass only changes HOW
 * they're organized and adds one new real data source (`actionRequired`,
 * built from the same WHERE clauses the existing overdue counts already
 * used, not new query logic).
 */
export default function HseDashboard({
    activeProjectsCount, openIncidentsCount, incidentsBySeverity, ppeAlertCount,
    recentIncidents, recentActivity, openSafetyObservationsCount, recentSafetyObservations,
    openPermitsCount, overdueSafetyEquipmentCount, overdueP3kCount, openCapaCount, actionRequired,
    manHours, safetyKpi, departmentCalendar, wasteSummary,
}) {
    return (
        <AuthenticatedLayout>
            <Head title="Ringkasan HSE" />
            <DashboardShell title="Ringkasan HSE" subtitle="Status keselamatan operasional.">
                {/* LEVEL 1 -- compact KPI strip */}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
                    <StatCard icon={AlertTriangle} value={openIncidentsCount} label="Insiden Terbuka" accent={openIncidentsCount > 0 ? 'red' : null} href={route('incidents.index')} />
                    <StatCard icon={AlertTriangle} value={incidentsBySeverity?.critical ?? 0} label="Insiden Kritis" accent={(incidentsBySeverity?.critical ?? 0) > 0 ? 'red' : null} href={route('incidents.index', { severity: 'critical' })} />
                    <StatCard icon={Eye} value={openSafetyObservationsCount} label="Observasi Terbuka" accent={openSafetyObservationsCount > 0 ? 'amber' : null} href={route('safety-observations.index')} />
                    <StatCard icon={ClipboardCheck} value={openCapaCount} label="CAPA Terbuka" accent={openCapaCount > 0 ? 'amber' : null} href={route('corrective-actions.index')} />
                    <StatCard icon={Flame} value={openPermitsCount} label="Izin Kerja Aktif" href={route('permits-to-work.index')} />
                    <StatCard icon={HardHat} value={ppeAlertCount} label="Peringatan APD" accent={ppeAlertCount > 0 ? 'amber' : null} href={route('ppe.dashboard')} />
                </div>

                {/* LEVEL 2/3 -- primary safety status + action required, side by side */}
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm">Performa Keselamatan</CardTitle>
                            <CardDescription>Rincian status terkini di seluruh modul HSE</CardDescription>
                        </CardHeader>
                        <CardContent className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                            <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                                <p className="text-xs text-graphite-400">Insiden</p>
                                <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{openIncidentsCount}</p>
                                <p className="text-[11px] text-graphite-400">{incidentsBySeverity?.critical ?? 0} kritis</p>
                            </div>
                            <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                                <p className="text-xs text-graphite-400">Observasi</p>
                                <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{openSafetyObservationsCount}</p>
                                <p className="text-[11px] text-graphite-400">terbuka</p>
                            </div>
                            <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                                <p className="text-xs text-graphite-400">CAPA</p>
                                <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{openCapaCount}</p>
                                <p className="text-[11px] text-graphite-400">terbuka</p>
                            </div>
                            <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                                <p className="text-xs text-graphite-400">Izin Kerja (PTW)</p>
                                <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{openPermitsCount}</p>
                                <p className="text-[11px] text-graphite-400">aktif</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm">Tindakan Diperlukan</CardTitle>
                            <CardDescription>Peralatan, P3K, dan CAPA yang terlambat -- terlama dahulu</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ActivityList
                                items={actionRequired}
                                getKey={(a, i) => i}
                                getHref={(a) => a.href}
                                emptyIcon={ShieldAlert}
                                emptyTitle="Tidak ada yang terlambat saat ini"
                                renderItem={(a) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-medium text-graphite-700 dark:text-slate-200">{a.label}</p>
                                            <p className="text-xs text-red-500">{a.type}</p>
                                        </div>
                                        <span className="shrink-0 text-xs text-graphite-400">{new Date(a.date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}</span>
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>
                </div>

                {/* v1.11.6 (Production Readiness pass, Part 4/5) -- Man-Hours
                    (real data, ManHourLog) and a Safety KPI Foundation built
                    ONLY from data that actually exists. LTI/LTIFR/TRIR/
                    Fatality intentionally show "Not available" rather than a
                    fabricated formula -- see HseDashboardController's own
                    doc comment on `safetyKpi.lost_time_metrics_available`. */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm">Man-Hour &amp; Fondasi KPI Keselamatan</CardTitle>
                        <CardDescription>Hanya data nyata -- rasio yang memerlukan data belum tercatat ditampilkan sebagai tidak tersedia</CardDescription>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
                        <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                            <p className="text-xs text-graphite-400">Man-Hour Hari Ini</p>
                            <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{manHours?.today ?? '—'}</p>
                        </div>
                        <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                            <p className="text-xs text-graphite-400">Man-Hour Bulan Ini</p>
                            <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{manHours?.this_month ?? '—'}</p>
                        </div>
                        <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                            <p className="text-xs text-graphite-400">Man-Hour Tahun Berjalan</p>
                            <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{manHours?.ytd ?? '—'}</p>
                        </div>
                        <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                            <p className="text-xs text-graphite-400">Cedera Tercatat Tahun Berjalan</p>
                            <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{safetyKpi?.recordable_injuries_ytd ?? 0}</p>
                        </div>
                        <div className="rounded-lg border border-dashed border-graphite-200 p-2.5 dark:border-slate-700">
                            <p className="text-xs text-graphite-400">LTI / LTIFR / TRIR</p>
                            <p className="text-xs italic text-graphite-400">Tidak tersedia -- memerlukan data hari-hilang-kerja &amp; klasifikasi tingkat keparahan yang belum tercatat</p>
                        </div>
                    </CardContent>
                </Card>

                {/* LEVEL 4 -- module shortcuts, compact grid */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {HSE_MODULES.map((m) => <ModuleCard key={m.title} {...m} />)}
                </div>

                {/* LEVEL 3 -- HSE activity */}
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm">Insiden Terbaru</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentIncidents}
                                getHref={(i) => route('incidents.show', i.id)}
                                emptyIcon={AlertTriangle}
                                emptyTitle="Belum ada insiden tercatat"
                                renderItem={(i) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700">{i.title}</span>
                                        <StatusBadge value={i.severity} />
                                        <StatusBadge value={i.status} />
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm">Observasi Terbaru</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentSafetyObservations}
                                getHref={(o) => route('safety-observations.show', o.id)}
                                emptyIcon={Eye}
                                emptyTitle="Belum ada observasi tercatat"
                                renderItem={(o) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium capitalize text-graphite-700">{o.type.replace('_', ' ')}</span>
                                        <StatusBadge value={o.status} />
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center gap-2 space-y-0 pb-2"><History className="h-3.5 w-3.5 text-graphite-400" /><CardTitle className="text-sm">Aktivitas Terbaru</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentActivity}
                                emptyIcon={History}
                                emptyTitle="Belum ada aktivitas terbaru"
                                renderItem={(a) => (
                                    <div className="py-2 text-sm">
                                        <p className="text-graphite-700">{a.description}</p>
                                        <p className="text-xs text-graphite-400">{a.user?.name} · {new Date(a.created_at).toLocaleString('en-US', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}</p>
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>
                </div>

                {/* LEVEL 3 -- waste (compact, unchanged from the prior pass) + calendar */}
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                    <Link href={route('waste.dashboard')} className="block">
                        <Card className="h-full transition-colors hover:border-brand-300 dark:hover:border-brand-700">
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm"><Recycle className="h-3.5 w-3.5 text-graphite-400" /> Limbah</CardTitle>
                            </CardHeader>
                            <CardContent className="grid grid-cols-2 gap-2 text-xs">
                                <div className="flex items-center justify-between rounded-md bg-graphite-50 px-2 py-1.5 dark:bg-slate-800">
                                    <span className="text-graphite-500">B3</span><span className="font-semibold">{wasteSummary.b3_stored}</span>
                                </div>
                                <div className="flex items-center justify-between rounded-md bg-graphite-50 px-2 py-1.5 dark:bg-slate-800">
                                    <span className="text-graphite-500">Non-B3</span><span className="font-semibold">{wasteSummary.non_b3_stored}</span>
                                </div>
                                <div className="flex items-center justify-between rounded-md bg-graphite-50 px-2 py-1.5 dark:bg-slate-800">
                                    <span className="text-graphite-500">Peringatan Penyimpanan</span>
                                    <span className={`font-semibold ${wasteSummary.storage_alerts > 0 ? 'text-red-600' : ''}`}>{wasteSummary.storage_alerts}</span>
                                </div>
                                <div className="flex items-center justify-between rounded-md bg-graphite-50 px-2 py-1.5 dark:bg-slate-800">
                                    <span className="text-graphite-500">Menunggu Pembuangan</span><span className="font-semibold">{wasteSummary.pending_disposal}</span>
                                </div>
                            </CardContent>
                        </Card>
                    </Link>

                    <div className="lg:col-span-2">
                        <DepartmentCalendarWidget events={departmentCalendar} title="Kalender HSE" description="Izin kerja, TBM & inspeksi, 3 minggu ke depan" />
                    </div>
                </div>
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
