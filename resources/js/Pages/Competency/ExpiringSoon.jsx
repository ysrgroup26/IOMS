import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import EmptyState from '@/Components/shared/EmptyState';
import { ShieldCheck } from 'lucide-react';

const STATUS_VARIANT = {
    expired: 'destructive',
    expiring_soon: 'default',
};

/**
 * Milestone 4, Workstream A2 / J (Industrial Reporting -- HR: Competency
 * Expiry). Cross-employee expiry monitoring -- mirrors Ppe/ReplacementDue.jsx's
 * role as the real, data-backed answer to "what's about to lapse", scoped
 * server-side to the current tenant's own companies only (see
 * CompetencyController::expiringSoon()'s own doc comment).
 */
export default function CompetencyExpiringSoon({ items, companies, filters }) {
    function changeCompany(value) {
        router.get(route('competency.expiring-soon'), { company_id: value === 'all' ? undefined : value }, { preserveState: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Competency Expiring Soon" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-bold tracking-tight text-graphite-900">Competency Expiring Soon</h1>
                    <p className="mt-1 text-sm text-graphite-500">
                        Training and certifications expiring within 30 days, or already expired.
                    </p>
                </div>
                <Select value={filters.company_id ? String(filters.company_id) : 'all'} onValueChange={changeCompany}>
                    <SelectTrigger className="w-48"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Companies</SelectItem>
                        {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                    </SelectContent>
                </Select>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Expiring / Expired Records</CardTitle>
                    <CardDescription>{items.length} record{items.length !== 1 ? 's' : ''}</CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    {items.length === 0 ? (
                        <EmptyState icon={ShieldCheck} title="Nothing expiring" description="No training or certification records are due within 30 days." />
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Employee</TableHead>
                                        <TableHead>Company</TableHead>
                                        <TableHead>Department</TableHead>
                                        <TableHead>Competency</TableHead>
                                        <TableHead>Expiry</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {items.map((item) => (
                                        <TableRow key={item.id}>
                                            <TableCell className="font-medium">
                                                <Link href={route('employees.show', item.employee_id)} className="hover:underline">
                                                    {item.employee_name}
                                                </Link>
                                                <div className="text-xs text-graphite-400">{item.employee_code}</div>
                                            </TableCell>
                                            <TableCell className="text-graphite-500">{item.company ?? '—'}</TableCell>
                                            <TableCell className="text-graphite-500">{item.department ?? '—'}</TableCell>
                                            <TableCell>
                                                {item.competency_name}
                                                <Badge variant="outline" className="ml-2 capitalize">{item.competency_type}</Badge>
                                            </TableCell>
                                            <TableCell>{item.expiry_date}</TableCell>
                                            <TableCell>
                                                <Badge variant={STATUS_VARIANT[item.effective_status] ?? 'secondary'}>
                                                    {item.effective_status === 'expired'
                                                        ? `Expired ${Math.abs(item.days_remaining)}d ago`
                                                        : `${item.days_remaining}d left`}
                                                </Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            </Card>
        </AuthenticatedLayout>
    );
}
