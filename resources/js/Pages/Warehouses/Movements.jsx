import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { FileStack, ChevronLeft, ChevronRight } from 'lucide-react';

export default function StockMovements({ movements, filters, warehouses, types }) {
    function applyFilters(overrides = {}) {
        router.get(route('stock.movements'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Stock Movement History" />
            <PageHeader title="Stock Movement History" subtitle="Full warehouse transaction log." />

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <Select value={filters.type || 'all'} onValueChange={(v) => applyFilters({ type: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Type" /></SelectTrigger>
                        <SelectContent><SelectItem value="all">All Types</SelectItem>{types.map((t) => <SelectItem key={t} value={t} className="capitalize">{t.replace('_', ' ')}</SelectItem>)}</SelectContent>
                    </Select>
                    <Select value={filters.warehouse_id || 'all'} onValueChange={(v) => applyFilters({ warehouse_id: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-48"><SelectValue placeholder="Warehouse" /></SelectTrigger>
                        <SelectContent><SelectItem value="all">All Warehouses</SelectItem>{warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>)}</SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {movements.data.length === 0 ? (
                        <EmptyState icon={FileStack} title="No stock movements recorded" />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Date</TableHead><TableHead>Movement No.</TableHead><TableHead>Item</TableHead><TableHead>Warehouse</TableHead><TableHead>Type</TableHead><TableHead>Quantity</TableHead><TableHead>By</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {movements.data.map((m) => (
                                    <TableRow key={m.id}>
                                        <TableCell>{new Date(m.movement_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell className="font-medium">{m.movement_number}</TableCell>
                                        <TableCell>{m.item?.name} <span className="text-graphite-400">({m.item?.item_code})</span></TableCell>
                                        <TableCell>{m.warehouse?.name}</TableCell>
                                        <TableCell><StatusBadge value={m.type.includes('in') || m.type === 'receipt' ? 'approved' : 'rejected'} label={m.type.replace('_', ' ')} /></TableCell>
                                        <TableCell>{m.quantity} {m.item?.unit}</TableCell>
                                        <TableCell>{m.performer?.name}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {movements.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {movements.current_page} of {movements.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!movements.prev_page_url} onClick={() => router.get(movements.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!movements.next_page_url} onClick={() => router.get(movements.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
