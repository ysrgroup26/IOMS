import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
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

            <PageHeader title="Incident Management" subtitle="Report and track HSE incidents.">
                {can.manage && (
                    <Button asChild>
                        <Link href={route('incidents.create')}><Plus className="h-4 w-4" /> Report Incident</Link>
                    </Button>
                )}
            </PageHeader>

            {/* v2.22.0 (Complete Product UI/UX Transformation, Part 5/19):
                filter bar unboxed -- a search field + two selects read
                fine as a plain toolbar, matching the same treatment
                PermitsToWork/Index.jsx already established. */}
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <div className="relative min-w-[220px] flex-1">
                    <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                    <Input
                        className="border-graphite-200 bg-white pl-8 shadow-none"
                        placeholder="Search incident number or title..."
                        defaultValue={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value || null })}
                    />
                </div>
                <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                    <SelectTrigger className="w-40 bg-white"><SelectValue placeholder="Status" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Statuses</SelectItem>
                        <SelectItem value="reported">Reported</SelectItem>
                        <SelectItem value="investigating">Investigating</SelectItem>
                        <SelectItem value="closed">Closed</SelectItem>
                    </SelectContent>
                </Select>
                <Select value={filters.severity || 'all'} onValueChange={(v) => applyFilters({ severity: v === 'all' ? null : v })}>
                    <SelectTrigger className="w-40 bg-white"><SelectValue placeholder="Severity" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Severities</SelectItem>
                        <SelectItem value="minor">Minor</SelectItem>
                        <SelectItem value="moderate">Moderate</SelectItem>
                        <SelectItem value="major">Major</SelectItem>
                        <SelectItem value="critical">Critical</SelectItem>
                    </SelectContent>
                </Select>
            </div>

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
                        <>
                            {/* v2.15.0 (Product UI/UX Finalization, Part 10/14D). A
                                7-column table forced horizontal scroll on mobile to see
                                severity/status at all -- replaced below `md` with the
                                same card-list pattern already proven on
                                PermitsToWork/Index.jsx (title+status up top, secondary
                                info below, truncated/shrink-0 where it matters), instead
                                of shrinking columns or forcing sideways scrolling on an
                                actionable, click-to-open list. */}
                            <div className="divide-y divide-graphite-100 md:hidden dark:divide-slate-800">
                                {incidents.data.map((i) => (
                                    <Link
                                        key={i.id}
                                        href={route('incidents.show', i.id)}
                                        className="block px-4 py-3 active:bg-graphite-50 dark:active:bg-slate-800/50"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="min-w-0 flex-1 truncate font-medium text-graphite-900 dark:text-slate-100">{i.incident_number}</span>
                                            <StatusBadge value={i.severity} />
                                        </div>
                                        <p className="mt-1 line-clamp-1 text-sm text-graphite-700 dark:text-slate-300">{i.title}</p>
                                        <div className="mt-2 flex items-center justify-between gap-2 text-xs text-graphite-400">
                                            <span className="min-w-0 flex-1 truncate">
                                                {new Date(i.incident_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}
                                                {' · '}<span className="capitalize">{i.category.replace('_', ' ')}</span>
                                                {i.reporter?.name ? ` · ${i.reporter.name}` : ''}
                                            </span>
                                            <StatusBadge value={i.status} />
                                        </div>
                                    </Link>
                                ))}
                            </div>
                            {/* v2.22.0: consolidated from 7 equal-weight
                                columns to 4 identity-first cells -- same
                                data, grouped so "what happened, how bad,
                                who reported it" each read as one unit
                                rather than competing on equal footing,
                                same pattern PermitsToWork/Index.jsx
                                already established. */}
                            <Table className="hidden md:table">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Incident</TableHead>
                                        <TableHead>Category</TableHead>
                                        <TableHead>Reported By</TableHead>
                                        <TableHead>Severity</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {incidents.data.map((i) => (
                                        <TableRow key={i.id} className="cursor-pointer" onClick={() => router.visit(route('incidents.show', i.id))}>
                                            <TableCell className="max-w-xs">
                                                <p className="truncate font-semibold text-graphite-900 dark:text-slate-100">{i.incident_number}</p>
                                                <p className="truncate text-xs text-graphite-500 dark:text-slate-400">{i.title}</p>
                                            </TableCell>
                                            <TableCell>
                                                <p className="capitalize text-graphite-800 dark:text-slate-200">{i.category.replace('_', ' ')}</p>
                                                <p className="text-xs text-graphite-500 dark:text-slate-400">{new Date(i.incident_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</p>
                                            </TableCell>
                                            <TableCell className="text-graphite-500 dark:text-slate-400">{i.reporter?.name || '-'}</TableCell>
                                            <TableCell><StatusBadge value={i.severity} /></TableCell>
                                            <TableCell><StatusBadge value={i.status} /></TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </>
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
