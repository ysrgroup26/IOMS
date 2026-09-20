import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import PageHeader from '@/Components/shared/PageHeader';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { SearchCheck, AlertTriangle } from 'lucide-react';

/**
 * v2.73.0 -- THE HSE INVESTIGATION WORKSPACE.
 *
 * This page did not exist. Before v2.73.0 an investigation was a card on
 * an incident, which meant the question an HSE lead actually has -- "what
 * are we still investigating, and is any of it overdue" -- had no screen
 * and no query behind it.
 *
 * WHAT THIS LIST IS FOR, and what it is therefore sorted and shaped
 * around: open work. The two columns that carry the page are the state
 * and the count of corrective actions still open, because an
 * investigation whose analysis is finished but whose actions are not is
 * the single most common way this process quietly fails.
 *
 * The incident is shown as CONTEXT, not as the subject. Its number links
 * back to the initial report, which is the source of fact; the subject of
 * each row is the investigation.
 */
export default function InvestigationsIndex({ investigations, filters, statuses, methodLabels, summary, can }) {
    function applyFilter(key, value) {
        router.get(route('investigations.index'), { ...filters, [key]: value === 'all' ? undefined : value }, {
            preserveState: true,
            replace: true,
        });
    }

    const rows = investigations.data || [];

    return (
        <AuthenticatedLayout>
            <Head title="HSE Investigation" />

            <PageHeader
                title="HSE Investigation"
                subtitle="Analisis penyebab atas kejadian yang dilaporkan. Setiap investigasi punya nomor, status, dan penutupannya sendiri."
            >
                <span className="rounded-full border border-steel-200 bg-steel-50 px-3 py-1 text-xs font-medium text-navy-800 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
                    {summary.open} open
                </span>
            </PageHeader>

            <Card className="mb-4">
                <CardContent className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center">
                    <Input
                        className="sm:max-w-xs"
                        placeholder="Search investigation or incident..."
                        defaultValue={filters.search || ''}
                        onKeyDown={(e) => { if (e.key === 'Enter') applyFilter('search', e.target.value); }}
                    />
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilter('status', v)}>
                        <SelectTrigger className="sm:w-48"><SelectValue placeholder="All statuses" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            {statuses.map((s) => <SelectItem key={s} value={s} className="capitalize">{s.replace(/_/g, ' ')}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.method || 'all'} onValueChange={(v) => applyFilter('method', v)}>
                        <SelectTrigger className="sm:w-56"><SelectValue placeholder="All methodologies" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All methodologies</SelectItem>
                            {Object.entries(methodLabels).map(([k, label]) => <SelectItem key={k} value={k}>{label}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            {rows.length === 0 ? (
                <EmptyState
                    icon={SearchCheck}
                    title="No investigations"
                    description={
                        can.manage
                            ? 'Investigasi dibuka dari sebuah laporan kejadian. Buka Incident Management, pilih laporannya, lalu mulai investigasi.'
                            : 'Belum ada investigasi yang tercatat.'
                    }
                    action={can.manage ? (
                        <Link href={route('incidents.index')} className="text-sm font-medium text-brand-600 hover:underline">
                            Go to Incident Management
                        </Link>
                    ) : undefined}
                />
            ) : (
                <Card>
                    <CardContent className="p-0">
                        {/* Desktop: a table, because these rows are compared
                            against each other. Phone: stacked cards, because
                            a seven-column table at 375px is unreadable. */}
                        <div className="hidden md:block">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Investigation</TableHead>
                                        <TableHead>Incident</TableHead>
                                        <TableHead>Lead Investigator</TableHead>
                                        <TableHead>Methodology</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">Open CAPA</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((v) => (
                                        <TableRow key={v.id} className="cursor-pointer" onClick={() => router.visit(route('investigations.show', v.id))}>
                                            <TableCell>
                                                <span className="font-mono text-[13px] font-semibold tabular-nums text-navy-900 dark:text-slate-100">
                                                    {v.investigation_number}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <span className="block font-mono text-xs tabular-nums text-graphite-500 dark:text-slate-400">
                                                    {v.incident?.incident_number}
                                                </span>
                                                <span className="block max-w-[22ch] truncate text-[13px] text-graphite-800 dark:text-slate-200">
                                                    {v.incident?.title}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-[13px]">{v.investigator?.name || '—'}</TableCell>
                                            <TableCell className="text-[13px]">{methodLabels[v.method] || '—'}</TableCell>
                                            <TableCell><StatusBadge value={v.status} /></TableCell>
                                            <TableCell className="text-right">
                                                <OpenActions count={v.open_actions_count} />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>

                        <div className="divide-y divide-graphite-100 md:hidden dark:divide-slate-800">
                            {rows.map((v) => (
                                <Link key={v.id} href={route('investigations.show', v.id)} className="block px-4 py-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <span className="font-mono text-[13px] font-semibold tabular-nums text-navy-900 dark:text-slate-100">
                                            {v.investigation_number}
                                        </span>
                                        <StatusBadge value={v.status} />
                                    </div>
                                    <p className="mt-0.5 truncate text-[13px] text-graphite-800 dark:text-slate-200">{v.incident?.title}</p>
                                    <p className="mt-0.5 text-[11px] text-graphite-500 dark:text-slate-400">
                                        {v.incident?.incident_number} · {v.investigator?.name || 'Unassigned'}
                                    </p>
                                    {v.open_actions_count > 0 && (
                                        <p className="mt-1"><OpenActions count={v.open_actions_count} /></p>
                                    )}
                                </Link>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            )}
        </AuthenticatedLayout>
    );
}

/**
 * Zero is a good outcome and is rendered quietly. A non-zero count is the
 * thing this page exists to surface, so it carries the warning treatment
 * -- restrained amber, not a red alarm: outstanding actions are normal
 * mid-investigation and only become a problem in context.
 */
function OpenActions({ count }) {
    if (!count) {
        return <span className="text-[13px] text-graphite-300 dark:text-slate-600">—</span>;
    }

    return (
        <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">
            <AlertTriangle className="h-3 w-3" aria-hidden="true" />
            {count} open
        </span>
    );
}
