import { Head, router, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { HardHat, AlertTriangle, ShieldAlert, RefreshCw, UserX } from 'lucide-react';
import PpeTabNav from '@/Components/shared/PpeTabNav';
import PageHeader from '@/Components/shared/PageHeader';
import StatCard from '@/Components/shared/StatCard';
import { formatNumber } from '@/lib/utils';

export default function PpeDashboard({ totalActive, expiringSoonCount, expiredCount, replacementDueCount, noPpeAssignedCount, countsByType, expiringSoon, companies, filters }) {
    function updateCompany(v) {
        router.get(route('ppe.dashboard'), { company_id: v === 'all' ? null : v }, { preserveState: true });
    }

    // Cards link into Employee PPE (not the flat Reports list) --
    // v1.6.7: clicking a status card should land on "which employees
    // have this problem," not "which individual PPE rows match," per
    // the explicit new navigation philosophy (Dashboard = monitoring,
    // Employee PPE = employee selector).
    function employeesUrl(params) {
        const query = new URLSearchParams(params);
        if (filters.company_id) query.set('company_id', filters.company_id);
        return route('ppe.employees') + '?' + query.toString();
    }

    return (
        <AuthenticatedLayout>
            <Head title="PPE Dashboard" />

            <PpeTabNav />

            <PageHeader title="PPE Dashboard" subtitle="Replacement-due overview across all PPE types. Click a card to see the full list.">
                <Select value={filters.company_id ? String(filters.company_id) : 'all'} onValueChange={updateCompany}>
                    <SelectTrigger className="w-40"><SelectValue placeholder="Company" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Companies</SelectItem>
                        {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                    </SelectContent>
                </Select>
            </PageHeader>

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <StatCard href={employeesUrl({ effective_status: 'active' })} icon={HardHat} label="Active PPE Issued" value={formatNumber(totalActive)} />
                <StatCard href={employeesUrl({ effective_status: 'expiring_soon' })} icon={AlertTriangle} label="Expiring in 30 Days" value={formatNumber(expiringSoonCount)} accent="amber" />
                <StatCard href={employeesUrl({ effective_status: 'expired' })} icon={ShieldAlert} label="Expired" value={formatNumber(expiredCount)} accent="red" />
                <StatCard href={route('ppe.replacement-due')} icon={RefreshCw} label="Replacement Due" value={formatNumber(replacementDueCount)} accent="amber" />
                <StatCard href={employeesUrl({ no_ppe_assigned: '1' })} icon={UserX} label="No PPE Assigned" value={formatNumber(noPpeAssignedCount)} accent="red" />
            </div>

            <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle>Active PPE by Type</CardTitle><CardDescription>Currently issued and active</CardDescription></CardHeader>
                    <CardContent className="space-y-1.5">
                        {countsByType.length === 0 ? (
                            <p className="py-6 text-center text-sm text-graphite-400">No data yet.</p>
                        ) : countsByType.map((t) => (
                            <div key={t.name} className="flex items-center justify-between rounded-lg border border-graphite-100 px-3 py-1.5 text-[13px]">
                                <span className="font-medium text-graphite-800">{t.name}</span>
                                <Badge variant="secondary">{formatNumber(t.total)}</Badge>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Expiring Soon</CardTitle>
                        <CardDescription>Due for replacement within 30 days &middot; <Link href={employeesUrl({ effective_status: 'expiring_soon' })} className="text-brand-600 hover:underline">view all</Link></CardDescription>
                    </CardHeader>
                    <CardContent>
                        {expiringSoon.length === 0 ? (
                            <p className="py-6 text-center text-sm text-graphite-400">Nothing expiring soon.</p>
                        ) : (
                            <div className="divide-y divide-graphite-100">
                                {expiringSoon.map((e) => (
                                    <div key={e.id} className="flex items-center justify-between py-2.5 text-sm">
                                        <div>
                                            <p className="font-medium text-graphite-800">{e.employee_name}</p>
                                            <p className="text-xs text-graphite-400">{e.ppe_type} &middot; {e.company} &middot; {e.department}</p>
                                        </div>
                                        <Badge variant={e.days_left <= 7 ? 'destructive' : 'outline'}>
                                            {e.days_left <= 0 ? 'Due today' : `${e.days_left}d left`}
                                        </Badge>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
