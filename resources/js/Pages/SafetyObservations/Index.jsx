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
import { Plus, Search, Eye, ChevronLeft, ChevronRight, ArrowRight } from 'lucide-react';

export default function SafetyObservationsIndex({ observations, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('safety-observations.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Safety Observation" />

            <PageHeader title="Safety Observation" subtitle="Catat temuan tidak aman dan observasi positif dari lapangan.">
                {can.manage && (
                    <Button asChild>
                        <Link href={route('safety-observations.create')}><Plus className="h-4 w-4" /> Report Observation</Link>
                    </Button>
                )}
            </PageHeader>

            {/* v2.23.0 (Complete Product UI/UX Transformation, cont'd):
                filter bar unboxed, same treatment as PermitsToWork/Index.jsx
                and Incidents/Index.jsx. */}
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <div className="relative min-w-[220px] flex-1">
                    <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                    <Input
                        className="border-graphite-200 bg-white pl-8 shadow-none"
                        placeholder="Search observation number or description..."
                        defaultValue={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value || null })}
                    />
                </div>
                <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                    <SelectTrigger className="w-44 bg-white"><SelectValue placeholder="Status" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Statuses</SelectItem>
                        <SelectItem value="draft">Draft</SelectItem>
                        <SelectItem value="open">Open</SelectItem>
                        <SelectItem value="assigned">Assigned</SelectItem>
                        <SelectItem value="in_progress">In Progress</SelectItem>
                        <SelectItem value="pending_verification">Pending Verification</SelectItem>
                        <SelectItem value="closed">Closed</SelectItem>
                        <SelectItem value="cancelled">Cancelled</SelectItem>
                    </SelectContent>
                </Select>
                <Select value={filters.type || 'all'} onValueChange={(v) => applyFilters({ type: v === 'all' ? null : v })}>
                    <SelectTrigger className="w-44 bg-white"><SelectValue placeholder="Type" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Types</SelectItem>
                        <SelectItem value="unsafe_act">Unsafe Act</SelectItem>
                        <SelectItem value="unsafe_condition">Unsafe Condition</SelectItem>
                        <SelectItem value="positive">Positive</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <Card>
                <CardContent className="p-0">
                    {observations.data.length === 0 ? (
                        <EmptyState
                            icon={Eye}
                            title="Belum ada observasi."
                            description="Laporkan temuan atau kondisi tidak aman untuk mulai mencatatnya."
                            action={can.manage && (
                                <Button asChild size="sm"><Link href={route('safety-observations.create')}><Plus className="h-4 w-4" /> Report Observation</Link></Button>
                            )}
                        />
                    ) : (
                        <>
                            {/* v2.23.0: mobile card list -- this page had no
                                mobile fallback at all before (the shared
                                Table's own overflow-auto was the only
                                containment), same pattern proven on
                                PermitsToWork/Index.jsx and
                                Incidents/Index.jsx. */}
                            <div className="divide-y divide-graphite-100 md:hidden dark:divide-slate-800">
                                {observations.data.map((o) => (
                                    <Link
                                        key={o.id}
                                        href={route('safety-observations.show', o.id)}
                                        className="block px-4 py-3 active:bg-graphite-50 dark:active:bg-slate-800/50"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="min-w-0 flex-1 truncate font-medium text-graphite-900 dark:text-slate-100">{o.observation_number}</span>
                                            <StatusBadge value={o.status} />
                                        </div>
                                        <p className="mt-1 text-sm capitalize text-graphite-700 dark:text-slate-300">{o.type.replace('_', ' ')} {o.hazard_category?.name ? `· ${o.hazard_category.name}` : ''}</p>
                                        <div className="mt-2 flex items-center justify-between gap-2 text-xs text-graphite-400">
                                            <span className="min-w-0 flex-1 truncate">
                                                {o.location || '-'} &middot; {new Date(o.observed_at).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}
                                            </span>
                                            <span className="flex shrink-0 items-center gap-0.5 font-medium text-brand-700 dark:text-brand-400">
                                                View <ArrowRight className="h-3 w-3" />
                                            </span>
                                        </div>
                                    </Link>
                                ))}
                            </div>

                            {/* v2.23.0: consolidated from 8 equal-weight
                                columns to 5 grouped cells -- no data
                                dropped, same identity-first pattern as
                                PermitsToWork/Index.jsx. */}
                            <Table className="hidden md:table">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Observation</TableHead>
                                        <TableHead>Hazard Category</TableHead>
                                        <TableHead>Reported By</TableHead>
                                        <TableHead>Assigned To</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {observations.data.map((o) => (
                                        <TableRow key={o.id} className="cursor-pointer" onClick={() => router.visit(route('safety-observations.show', o.id))}>
                                            <TableCell className="max-w-xs">
                                                <p className="truncate font-semibold text-graphite-900 dark:text-slate-100">{o.observation_number}</p>
                                                <p className="truncate text-xs capitalize text-graphite-500 dark:text-slate-400">{o.type.replace('_', ' ')} &middot; {o.location || '-'} &middot; {new Date(o.observed_at).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</p>
                                            </TableCell>
                                            <TableCell>{o.hazard_category?.name || '-'}</TableCell>
                                            <TableCell className="text-graphite-500 dark:text-slate-400">{o.reporter?.name}</TableCell>
                                            <TableCell className="text-graphite-500 dark:text-slate-400">{o.assignee?.name || '-'}</TableCell>
                                            <TableCell><StatusBadge value={o.status} /></TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </>
                    )}
                </CardContent>
            </Card>

            {observations.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {observations.current_page} of {observations.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!observations.prev_page_url} onClick={() => router.get(observations.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700">
                            <ChevronLeft className="h-4 w-4" />
                        </button>
                        <button disabled={!observations.next_page_url} onClick={() => router.get(observations.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700">
                            <ChevronRight className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
