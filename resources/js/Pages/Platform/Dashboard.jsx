import { Head, Link } from '@inertiajs/react';
import { Building2, CheckCircle2, Clock, PauseCircle, Package } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';

const STAT_CARDS = [
    { key: 'tenants_total', label: 'Total Tenants', icon: Building2 },
    { key: 'tenants_active', label: 'Active', icon: CheckCircle2 },
    { key: 'tenants_trial', label: 'Trial', icon: Clock },
    { key: 'tenants_suspended', label: 'Suspended', icon: PauseCircle },
    { key: 'packages_total', label: 'Packages', icon: Package },
    { key: 'subscriptions_active', label: 'Active Subscriptions', icon: CheckCircle2 },
];

export default function PlatformDashboard({ stats, recent_tenants: recentTenants }) {
    return (
        <PlatformLayout>
            <Head title="Platform Dashboard" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold tracking-tight text-graphite-900">Platform Dashboard</h1>
                <p className="mt-1 text-sm text-graphite-500">
                    Cross-tenant overview -- Tenants, Packages, and Subscriptions across the whole platform.
                </p>
            </div>

            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                {STAT_CARDS.map(({ key, label, icon: Icon }) => (
                    <Card key={key}>
                        <CardContent className="flex flex-col gap-1 pt-6">
                            <Icon className="h-4 w-4 text-graphite-400" />
                            <span className="text-2xl font-bold text-graphite-900">{stats?.[key] ?? 0}</span>
                            <span className="text-xs text-graphite-500">{label}</span>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <Card className="mt-6">
                <CardHeader>
                    <CardTitle>Recent Tenants</CardTitle>
                </CardHeader>
                <CardContent>
                    {recentTenants?.length ? (
                        <div className="divide-y divide-graphite-100">
                            {recentTenants.map((tenant) => (
                                <div key={tenant.id} className="flex items-center justify-between py-3">
                                    <div>
                                        <p className="text-sm font-medium text-graphite-800">{tenant.name}</p>
                                        <p className="text-xs text-graphite-400">
                                            {tenant.companies_count} companies &middot; {tenant.users_count} users
                                        </p>
                                    </div>
                                    <Badge variant={tenant.status === 'active' ? 'success' : 'secondary'}>{tenant.status}</Badge>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-graphite-400">No tenants yet.</p>
                    )}
                    <Link href={route('platform.tenants')} className="mt-4 inline-block text-sm font-medium text-brand-600 hover:underline">
                        View all tenants &rarr;
                    </Link>
                </CardContent>
            </Card>
        </PlatformLayout>
    );
}
