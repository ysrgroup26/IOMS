import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, Eye, ChevronLeft, ChevronRight } from 'lucide-react';

export default function SafetyObservationsIndex({ observations, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('safety-observations.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Safety Observation" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-50">Safety Observation</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Report and track unsafe acts, unsafe conditions, and positive observations.</p>
                </div>
                {can.manage && (
                    <Button asChild>
                        <Link href={route('safety-observations.create')}><Plus className="h-4 w-4" /> Report Observation</Link>
                    </Button>
                )}
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input
                            className="pl-8"
                            placeholder="Search observation number or description..."
                            defaultValue={filters.search || ''}
                            onChange={(e) => applyFilters({ search: e.target.value || null })}
                        />
                    </div>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
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
                        <SelectTrigger className="w-44"><SelectValue placeholder="Type" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Types</SelectItem>
                            <SelectItem value="unsafe_act">Unsafe Act</SelectItem>
                            <SelectItem value="unsafe_condition">Unsafe Condition</SelectItem>
                            <SelectItem value="positive">Positive</SelectItem>
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {observations.data.length === 0 ? (
                        <EmptyState icon={Eye} title="No safety observations recorded" description="Report an observation to start tracking it." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Observation No.</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Location</TableHead>
                                    <TableHead>Hazard Category</TableHead>
                                    <TableHead>Reported By</TableHead>
                                    <TableHead>Assigned To</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {observations.data.map((o) => (
                                    <TableRow key={o.id} className="cursor-pointer" onClick={() => router.visit(route('safety-observations.show', o.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{o.observation_number}</TableCell>
                                        <TableCell className="capitalize">{o.type.replace('_', ' ')}</TableCell>
                                        <TableCell>{new Date(o.observed_at).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell className="max-w-[160px] truncate">{o.location || '-'}</TableCell>
                                        <TableCell>{o.hazard_category?.name || '-'}</TableCell>
                                        <TableCell>{o.reporter?.name}</TableCell>
                                        <TableCell>{o.assignee?.name || '-'}</TableCell>
                                        <TableCell><StatusBadge value={o.status} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
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
