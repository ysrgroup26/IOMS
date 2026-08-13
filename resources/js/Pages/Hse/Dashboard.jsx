import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import StatCard from '@/Components/shared/StatCard';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import ModuleCard from '@/Components/shared/ModuleCard';
import {
    FolderKanban, AlertTriangle, HardHat, History, Eye, Flame, ShieldAlert, ClipboardCheck,
    ClipboardList, FileWarning, Lock, UsersRound, FlaskConical, UserCheck, FileCheck, FileStack,
} from 'lucide-react';

/**
 * v1.11.0 (SaaS Finalization Pass, Part 3). The shared "module card" grid
 * every department Overview should use for its own operational shortcuts
 * -- proof-of-pattern applied here first (HSE has the most modules of any
 * department in this codebase). Remaining department dashboards (HR,
 * Project Management, Logistics, Procurement) still use their own
 * pre-existing widget layout below the stat cards -- retrofitting them to
 * this same grid is flagged as a follow-up, not silently claimed done
 * here (see docs/CONVENTIONS.md).
 */
const HSE_MODULES = [
    { icon: Eye, title: 'Safety Observation', description: 'One-click hazard/near-miss reporting.', href: 'safety-observations.index' },
    { icon: ClipboardCheck, title: 'HSE Inspection', description: 'Scheduled inspections with findings.', href: 'hse-inspections.index' },
    { icon: UsersRound, title: 'Safety Meeting (TBM)', description: 'Toolbox meeting records.', href: 'tbm-meetings.index' },
    { icon: ShieldAlert, title: 'HIRADC / Risk Assessment', description: 'Hazard identification & risk matrix.', href: 'risk-assessments.index' },
    { icon: FileWarning, title: 'JSA', description: 'Job safety analysis with risk matrix.', href: 'job-safety-analyses.index' },
    { icon: Flame, title: 'Permit To Work', description: 'Hot work, confined space, and more.', href: 'permits-to-work.index' },
    { icon: FlaskConical, title: 'Gas Test', description: 'Atmospheric readings across all permits.', href: 'gas-test-records.index' },
    { icon: Lock, title: 'LOTO', description: 'Lockout/tagout energy isolation.', href: 'loto-records.index' },
    { icon: ClipboardCheck, title: 'Corrective Actions (CAPA)', description: 'Cross-source corrective action tracking.', href: 'corrective-actions.index' },
    { icon: UserCheck, title: 'Contractor Management', description: 'Contractor register, workers, documents.', href: 'contractors.index' },
    { icon: FileCheck, title: 'Visitor Management', description: 'Site access register.', href: 'visitors.index' },
    { icon: FileStack, title: 'Document Control', description: 'Controlled documents with version history.', href: 'controlled-documents.index' },
];

/**
 * HSE Dashboard (v1.10.0). Distinct from PPE's own dashboard
 * (route('ppe.dashboard'), still the detailed PPE-specific page) -- see
 * HseDashboardController's own doc comment for which spec widgets were
 * intentionally left out, and for Milestone 4 Workstream B's tenant-leak
 * fix + Safety Observation widget addition.
 */
export default function HseDashboard({
    activeProjectsCount, openIncidentsCount, incidentsBySeverity, ppeAlertCount,
    recentIncidents, recentActivity, openSafetyObservationsCount, recentSafetyObservations,
    openPermitsCount, overdueSafetyEquipmentCount, overdueP3kCount, openCapaCount,
}) {
    return (
        <AuthenticatedLayout>
            <Head title="HSE Dashboard" />
            <PageHeader title="HSE Dashboard" subtitle="Operational HSE overview." />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                <StatCard icon={FolderKanban} value={activeProjectsCount} label="Active Projects" href={route('projects.index')} />
                <StatCard icon={AlertTriangle} value={openIncidentsCount} label="Open Incidents" accent={openIncidentsCount > 0 ? 'red' : null} href={route('incidents.index')} />
                <StatCard icon={Eye} value={openSafetyObservationsCount} label="Open Observations" accent={openSafetyObservationsCount > 0 ? 'amber' : null} href={route('safety-observations.index')} />
                <StatCard icon={HardHat} value={ppeAlertCount} label="PPE Alerts" accent={ppeAlertCount > 0 ? 'amber' : null} href={route('ppe.dashboard')} />
                <StatCard icon={AlertTriangle} value={incidentsBySeverity?.critical ?? 0} label="Critical Incidents" accent={(incidentsBySeverity?.critical ?? 0) > 0 ? 'red' : null} href={route('incidents.index', { severity: 'critical' })} />
                <StatCard icon={Flame} value={openPermitsCount} label="Open Permits" href={route('permits-to-work.index')} />
                <StatCard icon={ShieldAlert} value={overdueSafetyEquipmentCount} label="Overdue Equipment" accent={overdueSafetyEquipmentCount > 0 ? 'red' : null} href={route('hse.master')} />
                <StatCard icon={ShieldAlert} value={overdueP3kCount} label="Overdue P3K" accent={overdueP3kCount > 0 ? 'red' : null} href={route('hse.master')} />
                <StatCard icon={ClipboardCheck} value={openCapaCount} label="Open CAPA" accent={openCapaCount > 0 ? 'amber' : null} href={route('corrective-actions.index')} />
            </div>

            <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                {HSE_MODULES.map((m) => <ModuleCard key={m.title} {...m} />)}
            </div>

            <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                <Card>
                    <CardHeader><CardTitle>Recent Incidents</CardTitle></CardHeader>
                    <CardContent>
                        {recentIncidents.length === 0 ? (
                            <EmptyState icon={AlertTriangle} title="No incidents recorded" />
                        ) : (
                            <ul className="divide-y divide-graphite-100">
                                {recentIncidents.map((i) => (
                                    <li key={i.id}>
                                        <Link href={route('incidents.show', i.id)} className="flex items-center justify-between gap-2 py-2.5 text-sm hover:text-brand-700">
                                            <span className="min-w-0 flex-1 truncate font-medium text-graphite-700">{i.title}</span>
                                            <StatusBadge value={i.severity} />
                                            <StatusBadge value={i.status} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Recent Safety Observations</CardTitle></CardHeader>
                    <CardContent>
                        {recentSafetyObservations.length === 0 ? (
                            <EmptyState icon={Eye} title="No observations recorded" />
                        ) : (
                            <ul className="divide-y divide-graphite-100">
                                {recentSafetyObservations.map((o) => (
                                    <li key={o.id}>
                                        <Link href={route('safety-observations.show', o.id)} className="flex items-center justify-between gap-2 py-2.5 text-sm hover:text-brand-700">
                                            <span className="min-w-0 flex-1 truncate font-medium capitalize text-graphite-700">{o.type.replace('_', ' ')}</span>
                                            <StatusBadge value={o.status} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center gap-2 space-y-0"><History className="h-4 w-4 text-graphite-400" /><CardTitle>Recent Activity</CardTitle></CardHeader>
                    <CardContent>
                        {recentActivity.length === 0 ? (
                            <EmptyState icon={History} title="No recent activity" />
                        ) : (
                            <ul className="divide-y divide-graphite-100">
                                {recentActivity.map((a) => (
                                    <li key={a.id} className="py-2.5 text-sm">
                                        <p className="text-graphite-700">{a.description}</p>
                                        <p className="text-xs text-graphite-400">{a.user?.name} · {new Date(a.created_at).toLocaleString('en-US', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
