import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, AlertTriangle, ChevronLeft, ChevronRight } from 'lucide-react';

export default function IncidentsIndex({ incidents, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('incidents.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Incident Management" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-50">Incident Management</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Report and track HSE incidents.</p>
                </div>
                {can.manage && (
                    <Button asChild>
                        <Link href={route('incidents.create')}><Plus className="h-4 w-4" /> Report Incident</Link>
                    </Button>
                )}
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input
                            className="pl-8"
                            placeholder="Search incident number or title..."
                            defaultValue={filters.search || ''}
                            onChange={(e) => applyFilters({ search: e.target.value || null })}
                        />
                    </div>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            <SelectItem value="reported">Reported</SelectItem>
                            <SelectItem value="investigating">Investigating</SelectItem>
                            <SelectItem value="closed">Closed</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={filters.severity || 'all'} onValueChange={(v) => applyFilters({ severity: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Severity" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Severities</SelectItem>
                            <SelectItem value="minor">Minor</SelectItem>
                            <SelectItem value="moderate">Moderate</SelectItem>
                            <SelectItem value="major">Major</SelectItem>
                            <SelectItem value="critical">Critical</SelectItem>
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {incidents.data.length === 0 ? (
                        <EmptyState
                            icon={AlertTriangle}
                            title="Belum ada insiden."
                            description="Laporkan insiden pertama untuk mulai mencatatnya."
                            action={can.manage && (
                                <Button asChild size="sm"><Link href={route('incidents.create')}><Plus className="h-4 w-4" /> Report Incident</Link></Button>
                            )}
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Incident No.</TableHead>
                                    <TableHead>Title</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Severity</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead>Reported By</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {incidents.data.map((i) => (
                                    <TableRow key={i.id} className="cursor-pointer" onClick={() => router.visit(route('incidents.show', i.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{i.incident_number}</TableCell>
                                        <TableCell className="max-w-xs truncate">{i.title}</TableCell>
                                        <TableCell>{new Date(i.incident_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell><StatusBadge value={i.severity} /></TableCell>
                                        <TableCell className="capitalize">{i.category.replace('_', ' ')}</TableCell>
                                        <TableCell>{i.reporter?.name}</TableCell>
                                        <TableCell><StatusBadge value={i.status} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {incidents.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {incidents.current_page} of {incidents.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!incidents.prev_page_url} onClick={() => router.get(incidents.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700">
                            <ChevronLeft className="h-4 w-4" />
                        </button>
                        <button disabled={!incidents.next_page_url} onClick={() => router.get(incidents.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700">
                            <ChevronRight className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
