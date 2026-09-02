import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Plus, Recycle, ArrowLeft } from 'lucide-react';

const STATUS_LABELS = {
    generated: 'Generated', stored: 'Stored', scheduled_pickup: 'Scheduled Pickup',
    in_transit: 'In Transit', disposed: 'Disposed', closed: 'Closed',
};
const STATUS_VARIANTS = {
    generated: 'outline', stored: 'secondary', scheduled_pickup: 'secondary',
    in_transit: 'secondary', disposed: 'success', closed: 'success',
};

/**
 * v1.11.4 (HSE Waste Management, Part 13). Waste Records list -- compact
 * table, same filter-row convention as every other list page in this
 * codebase (company/status/type Select, preserveState navigation).
 */
export default function WasteRecordsIndex({ records, wasteTypes, companies, filters, statuses, can }) {
    function updateFilter(key, value) {
        router.get(route('waste-records.index'), { ...filters, [key]: value === '__all' ? null : value }, { preserveState: true, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Waste Records" />

            <Link href={route('waste.dashboard')} className="mb-3 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Waste Management
            </Link>

            <PageHeader title="Waste Records" subtitle="Daftar lengkap limbah dari timbulan hingga pembuangan.">
                {can.manage && <Link href={route('waste-records.create')}><Button><Plus className="h-4 w-4" /> New Waste Record</Button></Link>}
            </PageHeader>

            <div className="mb-3 flex flex-wrap gap-2">
                <Select value={filters.company_id ? String(filters.company_id) : '__all'} onValueChange={(v) => updateFilter('company_id', v)}>
                    <SelectTrigger className="w-40"><SelectValue placeholder="Company" /></SelectTrigger>
                    <SelectContent><SelectItem value="__all">All Companies</SelectItem>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                </Select>
                <Select value={filters.status || '__all'} onValueChange={(v) => updateFilter('status', v)}>
                    <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__all">All Statuses</SelectItem>
                        {statuses.map((s) => <SelectItem key={s} value={s}>{STATUS_LABELS[s] || s}</SelectItem>)}
                    </SelectContent>
                </Select>
                <Select value={filters.waste_type_id ? String(filters.waste_type_id) : '__all'} onValueChange={(v) => updateFilter('waste_type_id', v)}>
                    <SelectTrigger className="w-48"><SelectValue placeholder="Waste Type" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__all">All Types</SelectItem>
                        {wasteTypes.map((t) => <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>)}
                    </SelectContent>
                </Select>
            </div>

            <Card>
                <CardContent className="p-0">
                    {records.data.length === 0 ? (
                        <EmptyState icon={Recycle} title="Belum ada catatan limbah" description="Buat catatan limbah pertama untuk mulai memantau." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Record #</TableHead><TableHead>Type</TableHead><TableHead>Project</TableHead>
                                    <TableHead>Quantity</TableHead><TableHead>Generated</TableHead><TableHead>Storage</TableHead><TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {records.data.map((r) => (
                                    <TableRow key={r.id} className="cursor-pointer" onClick={() => router.get(route('waste-records.show', r.id))}>
                                        <TableCell className="font-medium">{r.record_number}</TableCell>
                                        <TableCell>
                                            {r.waste_type?.name}
                                            <Badge variant={r.waste_type?.category === 'b3' ? 'destructive' : 'secondary'} className="ml-1.5">{r.waste_type?.category === 'b3' ? 'B3' : 'Non-B3'}</Badge>
                                        </TableCell>
                                        <TableCell className="text-graphite-500">{r.project?.name || '-'}</TableCell>
                                        <TableCell>{r.quantity} {r.unit}</TableCell>
                                        <TableCell>{new Date(r.generated_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell className="text-graphite-500">{r.storage_location?.name || '-'}</TableCell>
                                        <TableCell><Badge variant={STATUS_VARIANTS[r.status] || 'outline'}>{STATUS_LABELS[r.status] || r.status}</Badge></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </AuthenticatedLayout>
    );
}
