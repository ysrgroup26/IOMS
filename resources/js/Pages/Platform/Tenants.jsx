import { Head, router } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';

const STATUS_VARIANT = {
    active: 'success',
    trial: 'default',
    suspended: 'destructive',
    expired: 'secondary',
};

export default function PlatformTenants({ tenants }) {
    function changeStatus(tenant, status) {
        router.put(route('platform.tenants.update-status', tenant.id), { status }, { preserveScroll: true });
    }

    return (
        <PlatformLayout>
            <Head title="Tenants" />

            <div className="mb-6">
                <h1 className="text-lg font-bold tracking-tight text-graphite-900">Tenants</h1>
                <p className="mt-1 text-sm text-graphite-500">
                    Every paying customer organization on this platform. Suspending a tenant is the coarse
                    platform-level kill switch -- it does not delete any of that tenant's data.
                </p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>All Tenants</CardTitle>
                    <CardDescription>{tenants?.length ?? 0} total</CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Slug</TableHead>
                                    <TableHead>Companies</TableHead>
                                    <TableHead>Users</TableHead>
                                    <TableHead>Package</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {(tenants ?? []).map((tenant) => (
                                    <TableRow key={tenant.id}>
                                        <TableCell className="font-medium text-graphite-800">{tenant.name}</TableCell>
                                        <TableCell className="text-graphite-500">{tenant.slug}</TableCell>
                                        <TableCell>{tenant.companies_count}</TableCell>
                                        <TableCell>{tenant.users_count}</TableCell>
                                        <TableCell>{tenant.subscription?.package?.name ?? '—'}</TableCell>
                                        <TableCell>
                                            <Select value={tenant.status} onValueChange={(value) => changeStatus(tenant, value)}>
                                                <SelectTrigger className="w-32">
                                                    <SelectValue>
                                                        <Badge variant={STATUS_VARIANT[tenant.status] ?? 'secondary'}>{tenant.status}</Badge>
                                                    </SelectValue>
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="trial">Trial</SelectItem>
                                                    <SelectItem value="active">Active</SelectItem>
                                                    <SelectItem value="suspended">Suspended</SelectItem>
                                                    <SelectItem value="expired">Expired</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>
        </PlatformLayout>
    );
}
