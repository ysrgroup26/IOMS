import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, Building2, ChevronLeft, ChevronRight } from 'lucide-react';

export default function VendorsIndex({ vendors, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('vendors.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Vendor / Supplier" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-bold tracking-tight text-graphite-900 dark:text-slate-50">Vendor / Supplier</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Vendor master data, qualification, and documents.</p>
                </div>
                {can.manage && (<Button asChild><Link href={route('vendors.create')}><Plus className="h-4 w-4" /> Add Vendor</Link></Button>)}
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search vendor name or code..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                    </div>
                    <Select value={filters.qualification_status || 'all'} onValueChange={(v) => applyFilters({ qualification_status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-48"><SelectValue placeholder="Qualification" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            <SelectItem value="draft">Draft</SelectItem>
                            <SelectItem value="under_review">Under Review</SelectItem>
                            <SelectItem value="qualified">Qualified</SelectItem>
                            <SelectItem value="conditionally_qualified">Conditionally Qualified</SelectItem>
                            <SelectItem value="rejected">Rejected</SelectItem>
                            <SelectItem value="suspended">Suspended</SelectItem>
                            <SelectItem value="expired">Expired</SelectItem>
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {vendors.data.length === 0 ? (
                        <EmptyState icon={Building2} title="No vendors recorded" description="Add a vendor to start tracking it." />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Code</TableHead><TableHead>Name</TableHead><TableHead>Type</TableHead><TableHead>Category</TableHead><TableHead>POs</TableHead><TableHead>Qualification</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {vendors.data.map((v) => (
                                    <TableRow key={v.id} className="cursor-pointer" onClick={() => router.visit(route('vendors.show', v.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{v.vendor_code}</TableCell>
                                        <TableCell>{v.name}</TableCell>
                                        <TableCell className="capitalize">{v.type}</TableCell>
                                        <TableCell>{v.category || '-'}</TableCell>
                                        <TableCell>{v.purchase_orders_count}</TableCell>
                                        <TableCell><StatusBadge value={v.qualification_status === 'qualified' ? 'approved' : v.qualification_status === 'rejected' ? 'rejected' : v.qualification_status} label={v.qualification_status.replace('_', ' ')} /></TableCell>
                                        <TableCell><StatusBadge value={v.is_active ? 'active' : 'inactive'} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {vendors.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {vendors.current_page} of {vendors.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!vendors.prev_page_url} onClick={() => router.get(vendors.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!vendors.next_page_url} onClick={() => router.get(vendors.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
