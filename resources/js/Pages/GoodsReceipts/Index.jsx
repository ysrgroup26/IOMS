import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, PackageCheck, ChevronLeft, ChevronRight } from 'lucide-react';

export default function GoodsReceiptsIndex({ goodsReceipts, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('goods-receipts.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Goods Receipt" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-bold tracking-tight text-graphite-900 dark:text-slate-50">Goods Receipt</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Record materials received against approved Material Requests.</p>
                </div>
                {can.manage && (
                    <Button asChild>
                        <Link href={route('goods-receipts.create')}><Plus className="h-4 w-4" /> New Receipt</Link>
                    </Button>
                )}
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input
                            className="pl-8"
                            placeholder="Search receipt number..."
                            defaultValue={filters.search || ''}
                            onChange={(e) => applyFilters({ search: e.target.value || null })}
                        />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {goodsReceipts.data.length === 0 ? (
                        <EmptyState icon={PackageCheck} title="No goods receipts yet" description="Record materials as they arrive." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Receipt No.</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Material Request</TableHead>
                                    <TableHead>Project</TableHead>
                                    <TableHead>Received By</TableHead>
                                    <TableHead>Items</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {goodsReceipts.data.map((r) => (
                                    <TableRow key={r.id} className="cursor-pointer" onClick={() => router.visit(route('goods-receipts.show', r.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{r.receipt_number}</TableCell>
                                        <TableCell>{new Date(r.received_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell>{r.material_request?.request_number || '-'}</TableCell>
                                        <TableCell>{r.project?.name || '-'}</TableCell>
                                        <TableCell>{r.receiver?.name}</TableCell>
                                        <TableCell>{r.items_count}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {goodsReceipts.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {goodsReceipts.current_page} of {goodsReceipts.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!goodsReceipts.prev_page_url} onClick={() => router.get(goodsReceipts.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700">
                            <ChevronLeft className="h-4 w-4" />
                        </button>
                        <button disabled={!goodsReceipts.next_page_url} onClick={() => router.get(goodsReceipts.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700">
                            <ChevronRight className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
