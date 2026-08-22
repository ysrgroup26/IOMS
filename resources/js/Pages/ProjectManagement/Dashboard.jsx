import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import StatCard from '@/Components/shared/StatCard';
import StatusBadge from '@/Components/shared/StatusBadge';
import ActivityList from '@/Components/shared/ActivityList';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import ModuleCard from '@/Components/shared/ModuleCard';
import DepartmentCalendarWidget from '@/Components/shared/DepartmentCalendarWidget';
import { FolderKanban, AlertTriangle, Flag, ClipboardList, ListTodo, CheckSquare, FileWarning, ClipboardCheck } from 'lucide-react';

// v1.11.7 (Bahasa Indonesia Standardization, Part 4) -- hrefs unchanged.
const PM_MODULES = [
    { icon: FolderKanban, title: 'Proyek', description: 'Register proyek & linimasa.', href: 'projects.index' },
    { icon: Flag, title: 'Milestone', description: 'Pelacakan milestone lintas proyek.', href: 'milestones.index' },
    { icon: ClipboardList, title: 'Laporan Harian', description: 'Catatan progres aktivitas harian.', href: 'daily-reports.index' },
    { icon: CheckSquare, title: 'Tugas', description: 'Penugasan & pelacakan tugas.', href: 'tasks.index' },
    { icon: ClipboardCheck, title: 'Permintaan Inspeksi', description: 'Permintaan inspeksi kualitas.', href: 'inspection-requests.index' },
    { icon: FileWarning, title: 'NCR', description: 'Laporan ketidaksesuaian.', href: 'ncrs.index' },
];

function ProgressBar({ percent }) {
    if (percent === null || percent === undefined) return <span className="text-xs text-graphite-400">—</span>;
    return (
        <div className="flex items-center gap-2">
            <div className="h-1.5 w-16 overflow-hidden rounded-full bg-graphite-100 dark:bg-slate-800">
                <div
                    className={`h-full rounded-full ${percent >= 100 ? 'bg-emerald-500' : percent >= 50 ? 'bg-brand-500' : 'bg-amber-500'}`}
                    style={{ width: `${Math.min(percent, 100)}%` }}
                />
            </div>
            <span className="text-xs tabular-nums text-graphite-500">{percent}%</span>
        </div>
    );
}

/**
 * Project Management Dashboard (v1.10.0, redesigned v1.11.5 -- Dashboard
 * UX Completion, Phase 4). Portfolio changed from an implicit list to an
 * explicit compact TABLE (Project/Manager/Status/Progress/Next Milestone/
 * Due) per the directive's explicit instruction NOT to render a grid of
 * large project cards -- the underlying data is
 * ProjectManagementDashboardController's new `projectPortfolio` prop,
 * itself built from relations (manager(), milestones()) that already
 * existed on the Project model.
 */
export default function ProjectManagementDashboard({
    activeProjectsCount, delayedProjectsCount, milestoneCompletionPercent, avgActivityProgressPercent, todaysActivitiesCount,
    projectPortfolio, upcomingMilestones, delayedProjects, departmentCalendar,
}) {
    return (
        <AuthenticatedLayout>
            <Head title="Ringkasan Manajemen Proyek" />
            <DashboardShell title="Ringkasan Manajemen Proyek" subtitle="Tampilan operasional berbasis portofolio.">
                {/* LEVEL 1 -- compact KPI strip */}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <StatCard icon={FolderKanban} value={activeProjectsCount} label="Proyek Aktif" href={route('projects.index')} />
                    <StatCard icon={AlertTriangle} value={delayedProjectsCount} label="Proyek Terlambat" accent={delayedProjectsCount > 0 ? 'red' : 'green'} href={route('projects.index')} />
                    <StatCard icon={Flag} value={milestoneCompletionPercent === null ? '—' : `${milestoneCompletionPercent}%`} label="Penyelesaian Milestone" href={route('milestones.index')} />
                    <StatCard icon={ListTodo} value={avgActivityProgressPercent === null ? '—' : `${avgActivityProgressPercent}%`} label="Rata-rata Progres Aktivitas" />
                    <StatCard icon={ClipboardList} value={todaysActivitiesCount} label="Aktivitas Hari Ini" href={route('daily-reports.index')} />
                </div>

                {/* LEVEL 2 -- Project Portfolio, a compact TABLE not cards */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm">Portofolio Proyek</CardTitle>
                        <CardDescription>Proyek aktif & terencana, tenggat terdekat dahulu</CardDescription>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        {projectPortfolio.length === 0 ? (
                            <p className="py-6 text-center text-sm text-graphite-400">Tidak ada proyek aktif atau terencana.</p>
                        ) : (
                            <table className="w-full min-w-[640px] text-sm">
                                <thead>
                                    <tr className="border-b border-graphite-100 text-left text-xs text-graphite-400 dark:border-slate-800">
                                        <th className="py-1.5 font-medium">Proyek</th>
                                        <th className="py-1.5 font-medium">Manajer</th>
                                        <th className="py-1.5 font-medium">Status</th>
                                        <th className="py-1.5 font-medium">Progres</th>
                                        <th className="py-1.5 font-medium">Milestone Berikutnya</th>
                                        <th className="py-1.5 font-medium">Tenggat</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-graphite-50 dark:divide-slate-800/60">
                                    {projectPortfolio.map((p) => (
                                        <tr key={p.id} className="hover:bg-graphite-25 dark:hover:bg-slate-900/40">
                                            <td className="py-2 pr-2">
                                                <Link href={route('projects.show', p.id)} className="font-medium text-graphite-700 hover:text-brand-700 dark:text-slate-200">{p.name}</Link>
                                            </td>
                                            <td className="py-2 pr-2 text-graphite-500">{p.manager ?? '—'}</td>
                                            <td className="py-2 pr-2"><StatusBadge value={p.status} /></td>
                                            <td className="py-2 pr-2"><ProgressBar percent={p.progress_percent} /></td>
                                            <td className="py-2 pr-2 text-graphite-500">{p.next_milestone ?? '—'}</td>
                                            <td className="py-2 text-xs text-graphite-400">{p.end_date ? new Date(p.end_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' }) : '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        <Link href={route('projects.index')} className="mt-2 inline-block text-xs font-medium text-brand-600 hover:underline">Lihat semua proyek</Link>
                    </CardContent>
                </Card>

                {/* LEVEL 3 -- module shortcuts */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {PM_MODULES.map((m) => <ModuleCard key={m.title} {...m} />)}
                </div>

                {/* LEVEL 4 -- milestone control + delayed projects + calendar */}
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm">Kontrol Milestone</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={upcomingMilestones}
                                getHref={() => null}
                                emptyIcon={Flag}
                                emptyTitle="Tidak ada milestone mendatang"
                                renderItem={(m) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-medium text-graphite-700 dark:text-slate-200">{m.title}</p>
                                            <p className="truncate text-xs text-graphite-400">{m.project?.name}</p>
                                        </div>
                                        <span className="shrink-0 text-xs text-graphite-400">{new Date(m.target_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}</span>
                                        <StatusBadge value={m.status} />
                                    </div>
                                )}
                            />
                            <Link href={route('milestones.index')} className="mt-2 inline-block text-xs font-medium text-brand-600 hover:underline">Lihat semua</Link>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm">Proyek Terlambat</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={delayedProjects}
                                getHref={(p) => route('projects.show', p.id)}
                                emptyIcon={FolderKanban}
                                emptyTitle="Tidak ada proyek terlambat"
                                emptyDescription="Tidak ada yang melewati tanggal selesainya."
                                renderItem={(p) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="truncate font-medium text-graphite-700 dark:text-slate-200">{p.name}</span>
                                        <span className="shrink-0 text-xs text-red-600">Berakhir {new Date(p.end_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>

                    <DepartmentCalendarWidget events={departmentCalendar} title="Kalender Proyek" description="Milestone & tenggat, 3 minggu ke depan" />
                </div>
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
