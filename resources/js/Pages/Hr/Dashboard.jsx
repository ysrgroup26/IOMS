import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import StatCard from '@/Components/shared/StatCard';
import ModuleCard from '@/Components/shared/ModuleCard';
import ActivityList from '@/Components/shared/ActivityList';
import StatusBadge from '@/Components/shared/StatusBadge';
import DepartmentCalendarWidget from '@/Components/shared/DepartmentCalendarWidget';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Users, UserCheck, CalendarDays, ClipboardEdit, UserCog, ClipboardList, GraduationCap, CalendarClock, ClipboardSignature, AlertTriangle } from 'lucide-react';

// v1.11.7 (Bahasa Indonesia Standardization, Part 4) -- hrefs unchanged.
const HR_MODULES = [
    { icon: Users, title: 'Karyawan', description: 'Data master karyawan.', href: 'employees.index' },
    { icon: CalendarDays, title: 'Cuti', description: 'Pengajuan & persetujuan cuti.', href: 'leave-requests.index' },
    { icon: ClipboardList, title: 'Catatan KPI', description: 'Pelacakan indikator kinerja.', href: 'kpi-records.index' },
    { icon: CalendarClock, title: 'Data Master Shift', description: 'Pola shift & penugasan.', href: 'shifts.master' },
    { icon: ClipboardSignature, title: 'Roster', description: 'Ringkasan roster shift.', href: 'rosters.overview' },
    { icon: GraduationCap, title: 'Kompetensi', description: 'Sertifikasi & pelacakan masa berlaku.', href: 'competency.master' },
];

/**
 * HR Dashboard (v1.10.0, redesigned v1.11.5 -- Dashboard UX Completion,
 * Phase 3). Restructured into: compact KPI strip -> Workforce Status ->
 * Attention Required (real contract/certification expiry rows, not just
 * counts) -> Recent Leave Activity -> Calendar. See
 * HrDashboardController's own doc comment for the two real data sources
 * added this pass (contract_end_date, EmployeeCompetency.expiry_date) --
 * both already existed in the schema, this only queries them from here.
 */
export default function HrDashboard({
    totalEmployees, activeEmployees, onShiftToday, employeesOnLeaveToday, pendingLeaveRequests,
    employeesNeedCompletionCount, kpiThisMonth, contractExpiringCount, certificationExpiringCount,
    attentionRequired, recentLeaveRequests, departmentCalendar,
}) {
    return (
        <AuthenticatedLayout>
            <Head title="Ringkasan HRD" />
            <DashboardShell title="Ringkasan HRD" subtitle="Ringkasan operasional HRD.">
                {/* LEVEL 1 -- compact KPI strip */}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
                    <StatCard icon={Users} value={activeEmployees} label="Karyawan Aktif" href={route('employees.index', { status: 'active' })} />
                    <StatCard icon={UserCheck} value={onShiftToday} label="Bertugas Hari Ini" href={route('rosters.overview')} />
                    <StatCard icon={CalendarDays} value={employeesOnLeaveToday} label="Cuti Hari Ini" href={route('leave-requests.index')} />
                    <StatCard icon={ClipboardEdit} value={pendingLeaveRequests} label="Cuti Menunggu Persetujuan" accent={pendingLeaveRequests > 0 ? 'amber' : 'green'} href={route('leave-requests.index', { status: 'submitted' })} />
                    <StatCard icon={AlertTriangle} value={contractExpiringCount} label="Kontrak Akan Berakhir (30 hr)" accent={contractExpiringCount > 0 ? 'amber' : 'green'} />
                    <StatCard icon={GraduationCap} value={certificationExpiringCount} label="Sertifikasi Akan Berakhir" accent={certificationExpiringCount > 0 ? 'amber' : 'green'} href={route('competency.expiring-soon')} />
                </div>

                {/* LEVEL 2/3 -- workforce status + attention required */}
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm">Status Tenaga Kerja</CardTitle>
                            <CardDescription>Seluruh perusahaan</CardDescription>
                        </CardHeader>
                        <CardContent className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                            <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                                <p className="text-xs text-graphite-400">Total</p>
                                <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{totalEmployees}</p>
                            </div>
                            <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                                <p className="text-xs text-graphite-400">Aktif</p>
                                <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{activeEmployees}</p>
                            </div>
                            <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                                <p className="text-xs text-graphite-400">Bertugas Hari Ini</p>
                                <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{onShiftToday}</p>
                            </div>
                            <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                                <p className="text-xs text-graphite-400">Profil Belum Lengkap</p>
                                <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{employeesNeedCompletionCount}</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm">Perlu Perhatian</CardTitle>
                            <CardDescription>Kontrak & sertifikasi akan berakhir, terdekat dahulu</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ActivityList
                                items={attentionRequired}
                                getKey={(a, i) => i}
                                getHref={(a) => a.href}
                                emptyIcon={GraduationCap}
                                emptyTitle="Tidak ada yang akan berakhir dalam 30 hari ke depan"
                                renderItem={(a) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-medium text-graphite-700 dark:text-slate-200">{a.label}</p>
                                            <p className="text-xs text-amber-600">{a.type}</p>
                                        </div>
                                        <span className="shrink-0 text-xs text-graphite-400">{new Date(a.date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}</span>
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>
                </div>

                {/* LEVEL 4 -- module shortcuts */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {HR_MODULES.map((m) => <ModuleCard key={m.title} {...m} />)}
                </div>

                {/* LEVEL 3 -- recent activity + calendar */}
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm">Pengajuan Cuti Terbaru</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentLeaveRequests}
                                getHref={(lr) => route('leave-requests.show', lr.id)}
                                emptyIcon={CalendarDays}
                                emptyTitle="Belum ada pengajuan cuti"
                                renderItem={(lr) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700 dark:text-slate-200">{lr.employee?.full_name}</span>
                                        <span className="shrink-0 capitalize text-graphite-400">{lr.leave_type}</span>
                                        <span className="shrink-0 text-xs text-graphite-400">
                                            {new Date(lr.start_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}
                                            {' - '}
                                            {new Date(lr.end_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}
                                        </span>
                                        <StatusBadge value={lr.status} label={lr.status === 'submitted' ? 'Menunggu Persetujuan' : undefined} />
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>

                    {/* v1.11.7 bug fix: was hardcoded events={[]}, so
                        HrDashboardController's own `departmentCalendar`
                        prop (real data since the department dashboards
                        pass) was silently never rendered. Now actually
                        wired to it. */}
                    <DepartmentCalendarWidget events={departmentCalendar} title="Kalender HRD" description="Cuti & acara perusahaan, 3 minggu ke depan" />
                </div>
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
