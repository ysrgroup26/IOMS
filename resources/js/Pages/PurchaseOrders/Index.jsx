import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, ShoppingCart, ChevronLeft, ChevronRight } from 'lucide-react';

export default function PurchaseOrdersIndex({ orders, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('purchase-orders.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Purchase Order" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-graphite-900 dark:text-slate-50">Purchase Order</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Approved commitments to a vendor, with delivery tracking.</p>
                </div>
                {can.manage && (<Button asChild><Link href={route('purchase-orders.create')}><Plus className="h-4 w-4" /> New PO</Link></Button>)}
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search PO number..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                    </div>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-48"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            {['draft', 'submitted', 'approved', 'rejected', 'issued', 'partially_delivered', 'fully_delivered', 'closed', 'cancelled'].map((s) => (
                                <SelectItem key={s} value={s} className="capitalize">{s.replace(/_/g, ' ')}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {orders.data.length === 0 ? (
                        <EmptyState icon={ShoppingCart} title="No purchase orders recorded" description="Create a PO to start tracking it." />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>PO No.</TableHead><TableHead>Vendor</TableHead><TableHead>Date</TableHead><TableHead>Delivery Date</TableHead><TableHead>Grand Total</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {orders.data.map((po) => (
                                    <TableRow key={po.id} className={`cursor-pointer ${po.is_overdue ? 'bg-red-50/50' : ''}`} onClick={() => router.visit(route('purchase-orders.show', po.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{po.po_number}</TableCell>
                                        <TableCell>{po.vendor?.name}</TableCell>
                                        <TableCell>{new Date(po.po_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell>{po.delivery_date ? new Date(po.delivery_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) : '-'}{po.is_overdue && <span className="ml-1 text-red-600">overdue</span>}</TableCell>
                                        <TableCell>{Number(po.grand_total).toLocaleString('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })}</TableCell>
                                        <TableCell><StatusBadge value={po.status} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {orders.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {orders.current_page} of {orders.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!orders.prev_page_url} onClick={() => router.get(orders.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!orders.next_page_url} onClick={() => router.get(orders.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
