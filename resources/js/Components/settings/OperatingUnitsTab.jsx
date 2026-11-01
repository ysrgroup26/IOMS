/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { Link, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogTrigger } from '@/Components/ui/dialog';
import { Plus, Trash2, Pencil } from 'lucide-react';

/**
 * v2.54.0 -- OPERATING UNITS, the third level of
 * IOMS -> Organization -> Operating Unit -> Department.
 *
 * Was "Companies", which read as "the other businesses you own" and is
 * exactly the wrong idea: GAJ and MTC are two operating units of ONE
 * organization on ONE subscription, not two customers. Legal entity is
 * an optional attribute of a unit, shown as such, and is only ever
 * printed on documents -- nothing is scoped by it.
 *
 * The capacity line is drawn from the same EntitlementService that
 * enforces the limit on create, so the number here and the 422 the server
 * would return can never disagree.
 */
export default function OperatingUnitsTab({ companies, organization }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, processing, reset, errors } = useForm({ name: '', code: '', legal_entity_name: '', legal_entity_registration: '' });

    const used = organization?.operating_units?.used ?? companies.length;
    const limit = organization?.operating_units?.limit ?? null;
    const atLimit = limit !== null && used >= limit;

    function submit(e) {
        e.preventDefault();
        post(route('settings.companies.store'), { onSuccess: () => { reset(); setOpen(false); } });
    }

    function destroy(id) {
        if (confirm('Hapus Operating Unit ini?')) router.delete(route('settings.companies.destroy', id));
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between space-y-0">
                <div>
                    <CardTitle>Operating Units</CardTitle>
                    <CardDescription>
                        Unit operasional di dalam organisasi {organization?.name ? <span className="font-medium text-graphite-700">{organization.name}</span> : 'Anda'},
                        misalnya galangan, site, atau divisi. Setiap Department berada di bawah satu Operating Unit.
                    </CardDescription>
                </div>
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogTrigger asChild><Button size="sm" disabled={atLimit}><Plus className="h-4 w-4" /> Add</Button></DialogTrigger>
                    <DialogContent>
                        <DialogHeader><DialogTitle>Add Operating Unit</DialogTitle></DialogHeader>
                        <form onSubmit={submit} className="space-y-3">
                            {Object.keys(errors).length > 0 && (
                                <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">
                                    {Object.values(errors).map((msg, i) => <p key={i}>{msg}</p>)}
                                </div>
                            )}
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Galangan Utama" /></div>
                                <div className="space-y-1.5"><Label>Code</Label><Input value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="e.g. GAJ" uppercase /></div>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Legal Entity <span className="font-normal text-graphite-400">(opsional)</span></Label>
                                <Input value={data.legal_entity_name} onChange={(e) => setData('legal_entity_name', e.target.value)} placeholder="PT ..." />
                                <p className="text-xs text-graphite-500">Isi hanya jika unit ini beroperasi atas nama badan hukum yang berbeda. Digunakan pada dokumen, bukan untuk pemisahan data.</p>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Registration Number <span className="font-normal text-graphite-400">(opsional)</span></Label>
                                <Input value={data.legal_entity_registration} onChange={(e) => setData('legal_entity_registration', e.target.value)} placeholder="NPWP / NIB" />
                            </div>
                            <DialogFooter><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </CardHeader>
            <CardContent className="space-y-3">
                <div className="flex flex-wrap items-center gap-2 rounded-md border border-graphite-200 bg-graphite-50 px-3 py-2 text-xs text-graphite-600">
                    <span className="font-medium text-graphite-700">{used}{limit !== null ? ` / ${limit}` : ''} Operating Unit{(limit ?? used) === 1 ? '' : 's'}</span>
                    <span className="text-graphite-400">·</span>
                    <span>{limit === null ? 'Paket Anda tidak membatasi jumlah unit.' : atLimit ? 'Kapasitas paket sudah terpakai penuh. Tingkatkan paket untuk menambah unit.' : 'Termasuk dalam paket Anda.'}</span>
                    {atLimit && <Link href={route('subscription.plans')} className="font-medium text-brand-600 hover:underline">Lihat paket</Link>}
                </div>
                <Table>
                    <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Code</TableHead><TableHead>Legal Entity</TableHead><TableHead>Departments</TableHead><TableHead>Employees</TableHead><TableHead /></TableRow></TableHeader>
                    <TableBody>
                        {companies.map((c) => (
                            <TableRow key={c.id}>
                                <TableCell className="font-medium">{c.name}</TableCell>
                                <TableCell>{c.code ?? '-'}</TableCell>
                                <TableCell>
                                    {c.legal_entity_name
                                        ? <span>{c.legal_entity_name}{c.legal_entity_registration ? <span className="ml-1 text-xs text-graphite-400">{c.legal_entity_registration}</span> : null}</span>
                                        : <span className="text-xs text-graphite-400">Organisasi</span>}
                                </TableCell>
                                <TableCell>{c.departments_count}</TableCell>
                                <TableCell>{c.employees_count}</TableCell>
                                <TableCell className="flex items-center gap-1">
                                    <Button variant="ghost" size="icon" onClick={() => setEditing(c)}><Pencil className="h-4 w-4" /></Button>
                                    <Button variant="ghost" size="icon" onClick={() => destroy(c.id)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
            {editing && <EditOperatingUnitDialog company={editing} onClose={() => setEditing(null)} />}
        </Card>
    );
}


function EditOperatingUnitDialog({ company, onClose }) {
    const { data, setData, put, processing, errors } = useForm({
        name: company.name ?? '',
        code: company.code ?? '',
        is_active: !!company.is_active,
        legal_entity_name: company.legal_entity_name ?? '',
        legal_entity_registration: company.legal_entity_registration ?? '',
    });

    function submit(e) {
        e.preventDefault();
        put(route('settings.companies.update', company.id), { onSuccess: onClose });
    }

    return (
        <Dialog open onOpenChange={onClose}>
            <DialogContent>
                <DialogHeader><DialogTitle>Edit Operating Unit</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">
                            {Object.values(errors).map((msg, i) => <p key={i}>{msg}</p>)}
                        </div>
                    )}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Code</Label><Input value={data.code} onChange={(e) => setData('code', e.target.value)} uppercase /></div>
                    </div>
                    <div className="space-y-1.5">
                        <Label>Legal Entity <span className="font-normal text-graphite-400">(opsional)</span></Label>
                        <Input value={data.legal_entity_name} onChange={(e) => setData('legal_entity_name', e.target.value)} placeholder="PT ..." />
                    </div>
                    <div className="space-y-1.5">
                        <Label>Registration Number <span className="font-normal text-graphite-400">(opsional)</span></Label>
                        <Input value={data.legal_entity_registration} onChange={(e) => setData('legal_entity_registration', e.target.value)} placeholder="NPWP / NIB" />
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', !!v)} />
                        <span>Active</span>
                    </label>
                    <DialogFooter><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
