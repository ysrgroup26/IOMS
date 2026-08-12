import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { FlaskConical, Plus } from 'lucide-react';

/**
 * Milestone 4, v1.10.7/v1.10.8. Read-only cross-permit list, PLUS (v1.10.8)
 * an "Add Gas Test" dialog -- a second entry point into the SAME
 * `permits-to-work.gas-tests.store` action the PTW Show page's own
 * embedded form already posts to (see GasTestRecordController's own doc
 * comment). Choosing a permit here is required since GasTestRecord always
 * belongs to exactly one; opening this from within a specific permit
 * instead is still the other, unchanged entry point.
 */
export default function GasTestRecordsIndex({ gasTests, filters, results, permits, can }) {
    const [addOpen, setAddOpen] = useState(false);
    const form = useForm({
        permit_to_work_id: '',
        tested_at: new Date().toISOString().slice(0, 16),
        o2_level: '20.9',
        lel_level: '0',
        h2s_level: '0',
        co_level: '0',
        result: 'pass',
        notes: '',
    });

    function applyFilter(result) {
        router.get(route('gas-test-records.index'), { result: result === 'all' ? null : result }, { preserveState: true, replace: true });
    }

    function submit(e) {
        e.preventDefault();
        if (!form.data.permit_to_work_id) return;
        form.post(route('permits-to-work.gas-tests.store', form.data.permit_to_work_id), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); setAddOpen(false); },
        });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Gas Test Records" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-bold tracking-tight text-graphite-900 dark:text-slate-50">Gas Test Records</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Every atmospheric reading recorded against a Permit To Work.</p>
                </div>
                <div className="flex items-center gap-2">
                    <Select value={filters.result || 'all'} onValueChange={applyFilter}>
                        <SelectTrigger className="w-36"><SelectValue placeholder="Result" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Results</SelectItem>
                            {results.map((r) => <SelectItem key={r} value={r} className="capitalize">{r}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    {can.manage && (
                        <Button onClick={() => setAddOpen(true)}><Plus className="h-4 w-4" /> Add Gas Test</Button>
                    )}
                </div>
            </div>

            <Card>
                <CardContent className="p-0">
                    {gasTests.data.length === 0 ? (
                        <EmptyState icon={FlaskConical} title="No gas test records" description={can.manage ? 'Click "Add Gas Test" to record the first reading.' : 'Readings are added from a Permit To Work\'s own page.'} />
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

            <Dialog open={addOpen} onOpenChange={(v) => { if (!v) { form.reset(); } setAddOpen(v); }}>
                <DialogContent>
                    <DialogHeader><DialogTitle>Add Gas Test</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-3">
                        <div className="space-y-1.5">
                            <Label>Permit To Work</Label>
                            <Select value={form.data.permit_to_work_id} onValueChange={(v) => form.setData('permit_to_work_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select a permit" /></SelectTrigger>
                                <SelectContent>
                                    {permits.length === 0 ? (
                                        <div className="px-2 py-1.5 text-xs text-graphite-400">No approved/active permits available.</div>
                                    ) : (
                                        permits.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.ptw_number}</SelectItem>)
                                    )}
                                </SelectContent>
                            </Select>
                            {form.errors.permit_to_work_id && <p className="text-xs text-red-600">{form.errors.permit_to_work_id}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <div className="col-span-2 space-y-1"><Label className="text-xs">Tested At</Label><Input type="datetime-local" value={form.data.tested_at} onChange={(e) => form.setData('tested_at', e.target.value)} /></div>
                            <div className="space-y-1"><Label className="text-xs">O2 %</Label><Input type="number" step="0.1" value={form.data.o2_level} onChange={(e) => form.setData('o2_level', e.target.value)} /></div>
                            <div className="space-y-1"><Label className="text-xs">LEL %</Label><Input type="number" step="0.1" value={form.data.lel_level} onChange={(e) => form.setData('lel_level', e.target.value)} /></div>
                            <div className="space-y-1"><Label className="text-xs">H2S ppm</Label><Input type="number" step="0.1" value={form.data.h2s_level} onChange={(e) => form.setData('h2s_level', e.target.value)} /></div>
                            <div className="space-y-1"><Label className="text-xs">CO ppm</Label><Input type="number" step="0.1" value={form.data.co_level} onChange={(e) => form.setData('co_level', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs">Result</Label>
                            <Select value={form.data.result} onValueChange={(v) => form.setData('result', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent><SelectItem value="pass">Pass</SelectItem><SelectItem value="fail">Fail</SelectItem></SelectContent>
                            </Select>
                        </div>
                        <Input placeholder="Notes (optional)" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setAddOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={form.processing || !form.data.permit_to_work_id}>Save</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
