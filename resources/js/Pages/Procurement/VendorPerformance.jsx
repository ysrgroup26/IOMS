import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { TrendingUp } from 'lucide-react';

/**
 * Vendor Performance (Milestone 4, Workstream C6). Every column is
 * computed from real transactions -- see VendorPerformanceController's
 * own doc comment. A blank on-time/response rate means "no completed
 * POs/RFQ invitations yet to measure," not zero.
 */
export default function VendorPerformance({ vendors }) {
    return (
        <AuthenticatedLayout>
            <Head title="Vendor Performance" />
            <PageHeader title="Vendor Performance" subtitle="Computed from real Purchase Order deliveries and RFQ responses." />

            <Card>
                <CardContent className="p-0">
                    {vendors.length === 0 ? (
                        <EmptyState icon={TrendingUp} title="No vendor activity yet" />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Vendor</TableHead><TableHead>Qualification</TableHead>
                                    <TableHead>Total POs</TableHead><TableHead>Total Value</TableHead><TableHead>Open POs</TableHead>
                                    <TableHead>On-Time Delivery</TableHead><TableHead>RFQ Response Rate</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {vendors.map((v) => (
                                    <TableRow key={v.id}>
                                        <TableCell><Link href={route('vendors.show', v.id)} className="font-medium text-brand-700 hover:underline">{v.name}</Link> <span className="text-graphite-400">({v.vendor_code})</span></TableCell>
                                        <TableCell><StatusBadge value={v.qualification_status === 'qualified' ? 'approved' : v.qualification_status === 'rejected' ? 'rejected' : v.qualification_status} label={v.qualification_status.replace('_', ' ')} /></TableCell>
                                        <TableCell>{v.total_po_count}</TableCell>
                                        <TableCell>{Number(v.total_po_value).toLocaleString('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })}</TableCell>
                                        <TableCell>{v.open_po_count}</TableCell>
                                        <TableCell>{v.on_time_delivery_rate !== null ? `${v.on_time_delivery_rate}%` : '-'}</TableCell>
                                        <TableCell>{v.rfq_response_rate !== null ? `${v.rfq_response_rate}%` : '-'}</TableCell>
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
