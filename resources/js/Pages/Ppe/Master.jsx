import { Head, useForm, router, Link } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogTrigger } from '@/Components/ui/dialog';
import { Checkbox } from '@/Components/ui/checkbox';
import { Plus, Trash2, Pencil } from 'lucide-react';
import PpeTabNav from '@/Components/shared/PpeTabNav';

export default function PpeMaster({ ppeTypes, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const { data, setData, post, put, processing, reset, errors } = useForm({
        name: '',
        replacement_interval_months: '',
        is_active: true,
    });

    function openCreate() {
        setEditing(null);
        reset();
        setOpen(true);
    }

    function openEdit(type) {
        setEditing(type);
        setData({
            name: type.name,
            replacement_interval_months: type.replacement_interval_months ?? '',
            is_active: type.is_active,
        });
        setOpen(true);
    }

    function submit(e) {
        e.preventDefault();
        const options = { onSuccess: () => { reset(); setOpen(false); } };
        if (editing) {
            put(route('ppe-types.update', editing.id), options);
        } else {
            post(route('ppe-types.store'), options);
        }
    }

    function destroy(id) {
        if (confirm('Remove this PPE type? This is only possible if it has never been issued.')) {
            router.delete(route('ppe-types.destroy', id));
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title="PPE Master" />

            <PpeTabNav />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900">PPE Master</h1>
                    <p className="mt-1 text-sm text-graphite-500">
                        Data konfigurasi, bukan operasional harian -- atur jenis APD dan interval penggantian di sini. Untuk mengeluarkan/mengganti APD karyawan, buka tab Employee PPE.
                    </p>
                </div>
                {can.manage && (
                    <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add PPE Type</Button>
                )}
            </div>

            <Card>
                <CardHeader><CardTitle>PPE Types</CardTitle><CardDescription>Request-based equipment (no interval) is still tracked in history.</CardDescription></CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Replacement Interval</TableHead>
                                <TableHead>Issued Count</TableHead>
                                <TableHead>Status</TableHead>
                                {can.manage && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {ppeTypes.map((t) => (
                                <TableRow key={t.id}>
                                    <TableCell className="font-medium">{t.name}</TableCell>
                                    <TableCell>
                                        {t.replacement_interval_months
                                            ? `${t.replacement_interval_months} months`
                                            : <span className="text-graphite-400">Request-based (no interval)</span>}
                                    </TableCell>
                                    <TableCell>{t.assignments_count}</TableCell>
                                    <TableCell>
                                        <Badge variant={t.is_active ? 'success' : 'secondary'}>{t.is_active ? 'Active' : 'Inactive'}</Badge>
                                    </TableCell>
                                    {can.manage && (
                                        <TableCell className="flex gap-1">
                                            <Button variant="ghost" size="icon" onClick={() => openEdit(t)}><Pencil className="h-4 w-4" /></Button>
                                            <Button variant="ghost" size="icon" onClick={() => destroy(t.id)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit PPE Type' : 'Add PPE Type'}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Name</Label>
                            <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Safety Helmet" />
                            {errors.name && <p className="text-xs text-red-600">{errors.name}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Replacement Interval (months)</Label>
                            <Input
                                type="number" min="1" max="120"
                                value={data.replacement_interval_months}
                                onChange={(e) => setData('replacement_interval_months', e.target.value)}
                                placeholder="Leave empty for request-based equipment (e.g. Harness)"
                            />
                            {errors.replacement_interval_months && <p className="text-xs text-red-600">{errors.replacement_interval_months}</p>}
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', !!v)} />
                            Active
                        </label>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={processing}>Save</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
