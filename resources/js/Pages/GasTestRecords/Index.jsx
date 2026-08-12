import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { FlaskConical } from 'lucide-react';

/**
 * Milestone 4, v1.10.7. Read-only, cross-permit view of every gas test
 * reading recorded -- creation/deletion only happens from within the
 * owning Permit To Work (GasTestRecord.permit_to_work_id is required, not
 * nullable, so a reading can never exist independently of one). This page
 * exists purely so gas test history is discoverable from HSE navigation
 * without already knowing which permit it was recorded against.
 */
export default function GasTestRecordsIndex({ gasTests, filters, results }) {
    function applyFilter(result) {
        router.get(route('gas-test-records.index'), { result: result === 'all' ? null : result }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Gas Test Records" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-bold tracking-tight text-graphite-900 dark:text-slate-50">Gas Test Records</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Every atmospheric reading recorded against a Permit To Work. Add a new reading from that permit's own page.</p>
                </div>
                <Select value={filters.result || 'all'} onValueChange={applyFilter}>
                    <SelectTrigger className="w-36"><SelectValue placeholder="Result" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Results</SelectItem>
                        {results.map((r) => <SelectItem key={r} value={r} className="capitalize">{r}</SelectItem>)}
                    </SelectContent>
                </Select>
            </div>

            <Card>
                <CardContent className="p-0">
                    {gasTests.data.length === 0 ? (
                        <EmptyState icon={FlaskConical} title="No gas test records" description="Readings are added from a Permit To Work's own page." />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Permit</TableHead><TableHead>Tested At</TableHead><TableHead>O2 %</TableHead><TableHead>LEL %</TableHead><TableHead>H2S ppm</TableHead><TableHead>CO ppm</TableHead><TableHead>Result</TableHead><TableHead>Tested By</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {gasTests.data.map((g) => (
                                    <TableRow key={g.id}>
                                        <TableCell>{g.permit_to_work ? <Link href={route('permits-to-work.show', g.permit_to_work.id)} className="font-medium text-brand-700 hover:underline">{g.permit_to_work.ptw_number}</Link> : '-'}</TableCell>
                                        <TableCell>{new Date(g.tested_at).toLocaleString('en-US', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</TableCell>
                                        <TableCell>{g.o2_level ?? '-'}</TableCell>
                                        <TableCell>{g.lel_level ?? '-'}</TableCell>
                                        <TableCell>{g.h2s_level ?? '-'}</TableCell>
                                        <TableCell>{g.co_level ?? '-'}</TableCell>
                                        <TableCell><StatusBadge value={g.result} /></TableCell>
                                        <TableCell>{g.tester?.name || '-'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </AuthenticatedLayout>
    );
}
