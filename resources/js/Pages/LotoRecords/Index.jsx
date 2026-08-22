import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Lock, Unlock } from 'lucide-react';

export default function LotoRecordsIndex({ lotoRecords, can }) {
    function release(l) {
        if (confirm(`Release LOTO ${l.loto_number} on ${l.equipment_name}?`)) {
            router.post(route('loto-records.release', l.id));
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title="LOTO (Lockout/Tagout)" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-50">LOTO (Lockout / Tagout)</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Energy isolation records, optionally linked to a Permit To Work.</p>
                </div>
                {can.manage && (<Button asChild><Link href={route('loto-records.create')}><Plus className="h-4 w-4" /> Apply LOTO</Link></Button>)}
            </div>

            <Card>
                <CardContent className="p-0">
                    {lotoRecords.data.length === 0 ? (
                        <EmptyState icon={Lock} title="No LOTO records" description="Apply a lockout/tagout to start tracking it." />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>LOTO No.</TableHead><TableHead>Equipment</TableHead><TableHead>Permit</TableHead><TableHead>Applied By</TableHead><TableHead>Status</TableHead>{can.manage && <TableHead />}</TableRow></TableHeader>
                            <TableBody>
                                {lotoRecords.data.map((l) => (
                                    <TableRow key={l.id}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{l.loto_number}</TableCell>
                                        <TableCell>{l.equipment_name}</TableCell>
                                        <TableCell>{l.permit_to_work ? <Link href={route('permits-to-work.show', l.permit_to_work.id)} className="text-brand-700 hover:underline">{l.permit_to_work.ptw_number}</Link> : '-'}</TableCell>
                                        <TableCell>{l.applier?.name}</TableCell>
                                        <TableCell><StatusBadge value={l.status} /></TableCell>
                                        {can.manage && (
                                            <TableCell>
                                                {l.status === 'isolated' && (
                                                    <Button variant="outline" size="sm" onClick={() => release(l)}><Unlock className="h-4 w-4" /> Release</Button>
                                                )}
                                            </TableCell>
                                        )}
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
