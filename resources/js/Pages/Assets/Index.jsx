import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatCard from '@/Components/shared/StatCard';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, Box, ClipboardCheck, ChevronLeft, ChevronRight } from 'lucide-react';

export default function AssetsIndex({ assets, filters, categories, inspectionDueCount, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('assets.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Asset Management" />
            <PageHeader title="Asset Management" subtitle="Company-owned equipment, vehicles, and tools.">
                {can.manage && (<Button asChild><Link href={route('assets.create')}><Plus className="h-4 w-4" /> Register Asset</Link></Button>)}
            </PageHeader>

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard icon={Box} value={assets.total} label="Total Assets" />
                <StatCard icon={ClipboardCheck} value={inspectionDueCount} label="Inspection Due (90d)" accent={inspectionDueCount > 0 ? 'amber' : null} />
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px]">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search asset name, code, or serial..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                    </div>
                    <Select value={filters.category || 'all'} onValueChange={(v) => applyFilters({ category: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-48"><SelectValue placeholder="Category" /></SelectTrigger>
                        <SelectContent><SelectItem value="all">All Categories</SelectItem>{categories.map((c) => <SelectItem key={c} value={c}>{c}</SelectItem>)}</SelectContent>
                    </Select>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            {['active', 'assigned', 'under_maintenance', 'retired', 'disposed'].map((s) => <SelectItem key={s} value={s} className="capitalize">{s.replace('_', ' ')}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {assets.data.length === 0 ? (
                        <EmptyState icon={Box} title="No assets registered" description="Register an asset to start tracking it." />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Code</TableHead><TableHead>Name</TableHead><TableHead>Category</TableHead><TableHead>Location</TableHead><TableHead>Responsible</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {assets.data.map((a) => (
                                    <TableRow key={a.id} className="cursor-pointer" onClick={() => router.visit(route('assets.show', a.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{a.asset_code}</TableCell>
                                        <TableCell>{a.name}</TableCell>
                                        <TableCell>{a.category || '-'}</TableCell>
                                        <TableCell>{a.location || '-'}</TableCell>
                                        <TableCell>{a.responsible_employee?.full_name || '-'}</TableCell>
                                        <TableCell><StatusBadge value={a.status === 'active' || a.status === 'assigned' ? 'active' : a.status} label={a.status.replace('_', ' ')} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {assets.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {assets.current_page} of {assets.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!assets.prev_page_url} onClick={() => router.get(assets.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!assets.next_page_url} onClick={() => router.get(assets.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
