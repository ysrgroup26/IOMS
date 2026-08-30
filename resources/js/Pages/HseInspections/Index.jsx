import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, ClipboardCheck, ChevronLeft, ChevronRight } from 'lucide-react';

export default function HseInspectionsIndex({ inspections, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('hse-inspections.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="HSE Inspection" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-50">HSE Inspection</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Scheduled and ad-hoc safety inspections.</p>
                </div>
                {can.manage && (<Button asChild><Link href={route('hse-inspections.create')}><Plus className="h-4 w-4" /> Record Inspection</Link></Button>)}
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search inspection number..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                    </div>
                    <Select value={filters.result || 'all'} onValueChange={(v) => applyFilters({ result: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Result" /></SelectTrigger>
                        <SelectContent><SelectItem value="all">All Results</SelectItem><SelectItem value="pass">Pass</SelectItem><SelectItem value="fail">Fail</SelectItem></SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {inspections.data.length === 0 ? (
                        <EmptyState
                            icon={ClipboardCheck}
                            title="Belum ada inspeksi."
                            description="Catat inspeksi pertama untuk mulai memantau area kerja."
                            action={can.manage && (
                                <Button asChild size="sm"><Link href={route('hse-inspections.create')}><Plus className="h-4 w-4" /> Record Inspection</Link></Button>
                            )}
                        />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Inspection No.</TableHead><TableHead>Type</TableHead><TableHead>Date</TableHead><TableHead>Location</TableHead><TableHead>Inspector</TableHead><TableHead>Result</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {inspections.data.map((i) => (
                                    <TableRow key={i.id} className="cursor-pointer" onClick={() => router.visit(route('hse-inspections.show', i.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{i.inspection_number}</TableCell>
                                        <TableCell className="capitalize">{i.inspection_type.replace('_', ' ')}</TableCell>
                                        <TableCell>{new Date(i.inspection_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell className="max-w-[160px] truncate">{i.location || '-'}</TableCell>
                                        <TableCell>{i.inspector?.name}</TableCell>
                                        <TableCell><StatusBadge value={i.overall_result === 'fail' ? 'rejected' : 'approved'} label={i.overall_result} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {inspections.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {inspections.current_page} of {inspections.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!inspections.prev_page_url} onClick={() => router.get(inspections.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!inspections.next_page_url} onClick={() => router.get(inspections.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
