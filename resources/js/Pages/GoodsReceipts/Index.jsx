import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, PackageCheck, ChevronLeft, ChevronRight, ArrowRight } from 'lucide-react';

export default function GoodsReceiptsIndex({ goodsReceipts, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('goods-receipts.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Goods Receipt" />

            <PageHeader title="Goods Receipt" subtitle="Catat material yang diterima berdasarkan Material Request yang disetujui.">
                {can.manage && (
                    <Button asChild>
                        <Link href={route('goods-receipts.create')}><Plus className="h-4 w-4" /> New Receipt</Link>
                    </Button>
                )}
            </PageHeader>

            <div className="mb-4 relative min-w-[220px]">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                <Input
                    className="max-w-sm border-graphite-200 bg-white pl-8 shadow-none"
                    placeholder="Search receipt number..."
                    defaultValue={filters.search || ''}
                    onChange={(e) => applyFilters({ search: e.target.value || null })}
                />
            </div>

            <Card>
                <CardContent className="p-0">
                    {goodsReceipts.data.length === 0 ? (
                        <EmptyState icon={PackageCheck} title="Belum ada Goods Receipt." description="Catat material begitu tiba di lokasi." />
                    ) : (
                        <>
                            <div className="divide-y divide-graphite-100 md:hidden dark:divide-slate-800">
                                {goodsReceipts.data.map((r) => (
                                    <Link key={r.id} href={route('goods-receipts.show', r.id)} className="block px-4 py-3 active:bg-graphite-50 dark:active:bg-slate-800/50">
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="min-w-0 flex-1 truncate font-medium text-graphite-900 dark:text-slate-100">{r.receipt_number}</span>
                                            <span className="shrink-0 text-xs text-graphite-400">{r.items_count} item</span>
                                        </div>
                                        <p className="mt-1 truncate text-sm text-graphite-700 dark:text-slate-300">{r.material_request?.request_number || '-'} {r.project?.name ? `· ${r.project.name}` : ''}</p>
                                        <div className="mt-2 flex items-center justify-between gap-2 text-xs text-graphite-400">
                                            <span className="min-w-0 flex-1 truncate">{r.receiver?.name} &middot; {new Date(r.received_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                                            <span className="flex shrink-0 items-center gap-0.5 font-medium text-brand-700 dark:text-brand-400">View <ArrowRight className="h-3 w-3" /></span>
                                        </div>
                                    </Link>
                                ))}
                            </div>
                            <Table className="hidden md:table">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Receipt</TableHead>
                                        <TableHead>Material Request / Project</TableHead>
                                        <TableHead>Received By</TableHead>
                                        <TableHead>Items</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {goodsReceipts.data.map((r) => (
                                        <TableRow key={r.id} className="cursor-pointer" onClick={() => router.visit(route('goods-receipts.show', r.id))}>
                                            <TableCell>
                                                <p className="font-semibold text-graphite-900 dark:text-slate-100">{r.receipt_number}</p>
                                                <p className="text-xs text-graphite-500 dark:text-slate-400">{new Date(r.received_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</p>
                                            </TableCell>
                                            <TableCell>
                                                <p className="text-graphite-800 dark:text-slate-200">{r.material_request?.request_number || '-'}</p>
                                                <p className="text-xs text-graphite-500 dark:text-slate-400">{r.project?.name || '-'}</p>
                                            </TableCell>
                                            <TableCell className="text-graphite-500 dark:text-slate-400">{r.receiver?.name}</TableCell>
                                            <TableCell>{r.items_count}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </>
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
