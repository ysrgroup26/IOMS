import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { ArrowLeft, FileStack } from 'lucide-react';

export default function StockCard({ item, movements }) {
    return (
        <AuthenticatedLayout>
            <Head title={`Stock Card -- ${item.name}`} />

            <Link href={route('stock.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Stock Summary
            </Link>

            <PageHeader title={`Stock Card -- ${item.name}`} subtitle={`${item.item_code} · Full movement history`} />

            <Card>
                <CardContent className="p-0">
                    {movements.length === 0 ? (
                        <EmptyState icon={FileStack} title="No movements recorded for this item" />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Date</TableHead><TableHead>Movement No.</TableHead><TableHead>Type</TableHead><TableHead>Warehouse</TableHead><TableHead>Quantity</TableHead><TableHead>Running Balance</TableHead><TableHead>By</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {movements.map((m) => (
                                    <TableRow key={m.id}>
                                        <TableCell>{new Date(m.movement_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell className="font-medium">{m.movement_number}</TableCell>
                                        <TableCell><StatusBadge value={m.type.includes('in') || m.type === 'receipt' ? 'approved' : 'rejected'} label={m.type.replace('_', ' ')} /></TableCell>
                                        <TableCell>{m.warehouse?.name}</TableCell>
                                        <TableCell>{m.quantity} {item.unit}</TableCell>
                                        <TableCell className="font-medium">{m.running_balance} {item.unit}</TableCell>
                                        <TableCell>{m.performer?.name}</TableCell>
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
