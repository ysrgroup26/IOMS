import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Checkbox } from '@/Components/ui/checkbox';
import EmptyState from '@/Components/shared/EmptyState';
import { Search, AlertCircle, Boxes, ArrowRightLeft, ChevronLeft, ChevronRight } from 'lucide-react';

export default function WarehouseStock({ stocks, filters, warehouses, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('stock.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Stock Summary" />
            <PageHeader title="Stock Summary" subtitle="Current balance per item per warehouse.">
                {can.manage && (
                    <Button variant="outline" asChild><Link href={route('stock.transactions.create')}><ArrowRightLeft className="h-4 w-4" /> Issue / Transfer / Adjust</Link></Button>
                )}
            </PageHeader>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap items-center gap-2 p-3">
                    <div className="relative min-w-[220px]">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search item name or code..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                    </div>
                    <Select value={filters.warehouse_id || 'all'} onValueChange={(v) => applyFilters({ warehouse_id: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-48"><SelectValue placeholder="Warehouse" /></SelectTrigger>
                        <SelectContent><SelectItem value="all">All Warehouses</SelectItem>{warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>)}</SelectContent>
                    </Select>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={!!filters.low_stock} onCheckedChange={(v) => applyFilters({ low_stock: v ? '1' : null })} />
                        Low Stock only
                    </label>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {stocks.data.length === 0 ? (
                        <EmptyState icon={Boxes} title="No stock records" />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Item</TableHead><TableHead>Warehouse</TableHead><TableHead>Quantity</TableHead><TableHead>Reserved</TableHead><TableHead>Available</TableHead><TableHead>Min Stock</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {stocks.data.map((s) => {
                                    const low = Number(s.quantity) <= Number(s.item?.min_stock ?? 0);
                                    return (
                                        <TableRow key={s.id} className={low ? 'bg-amber-50/50' : ''}>
                                            <TableCell><Link href={route('stock.card', s.item.id)} className="font-medium text-brand-700 hover:underline">{s.item?.name}</Link> <span className="text-graphite-400">({s.item?.item_code})</span></TableCell>
                                            <TableCell>{s.warehouse?.name}</TableCell>
                                            <TableCell>{s.quantity} {s.item?.unit}{low && <AlertCircle className="ml-1 inline h-3.5 w-3.5 text-amber-500" />}</TableCell>
                                            <TableCell>{s.reserved_quantity}</TableCell>
                                            <TableCell>{s.available_quantity}</TableCell>
                                            <TableCell>{s.item?.min_stock}</TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {stocks.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {stocks.current_page} of {stocks.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!stocks.prev_page_url} onClick={() => router.get(stocks.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!stocks.next_page_url} onClick={() => router.get(stocks.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
