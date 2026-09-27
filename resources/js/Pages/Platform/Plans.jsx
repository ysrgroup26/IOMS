import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Checkbox } from '@/Components/ui/checkbox';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import EmptyState from '@/Components/shared/EmptyState';
import { Tag, Plus, Pencil } from 'lucide-react';
import PageHeader from '@/Components/shared/PageHeader';

/**
 * v1.11.0 (SaaS Finalization Pass, Part 9/18). `Package` (Milestone 2)
 * already existed as the Plan/Edition model -- this is its first actual
 * management UI. Previously only ever read via `Package::active()` for a
 * dropdown inside Tenant create/edit; a Platform Admin had no way to
 * create a new plan or edit an existing one's price/limits at all.
 */
export default function PlatformPlans({ plans }) {
    const [dialogPlan, setDialogPlan] = useState(null); // null = closed, {} = create, {...} = edit

    return (
        <PlatformLayout>
            <Head title="Paket" />

            <PageHeader title="Paket / Edisi" subtitle="Tingkatan harga dan cakupan fitur yang dapat dilanggani tenant.">
                <Button size="sm" onClick={() => setDialogPlan({})}><Plus className="h-4 w-4" /> Paket baru</Button>
            </PageHeader>

            <Card>
                <CardHeader><CardTitle className="flex items-center gap-2"><Tag className="h-4 w-4" /> Katalog Paket</CardTitle><CardDescription>Hak modul dan departemen per paket diatur melalui Hak Akses tenant (Tenant &rarr; Hak akses), per tenant yang berlangganan paket tersebut.</CardDescription></CardHeader>
                <CardContent className="p-0">
                    {plans.length === 0 ? (
                        <EmptyState icon={Tag} title="Belum ada paket" />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Nama</TableHead><TableHead>Slug</TableHead><TableHead>Bulanan</TableHead><TableHead>Tahunan</TableHead><TableHead>Uji coba</TableHead><TableHead>Maks pengguna</TableHead><TableHead>Maks unit operasi</TableHead><TableHead>Status</TableHead><TableHead /></TableRow></TableHeader>
                            <TableBody>
                                {plans.map((p) => (
                                    <TableRow key={p.id}>
                                        <TableCell className="font-medium">{p.name}</TableCell>
                                        <TableCell><code className="text-xs">{p.slug}</code></TableCell>
                                        <TableCell>{p.is_custom ? 'Kustom' : (p.price_monthly ? `${p.currency} ${Number(p.price_monthly).toLocaleString('id-ID')}` : '—')}</TableCell>
                                        <TableCell>{p.is_custom ? 'Kustom' : (p.price_yearly ? `${p.currency} ${Number(p.price_yearly).toLocaleString('id-ID')}` : '—')}</TableCell>
                                        <TableCell>{p.trial_days ? `${p.trial_days} hari` : '—'}</TableCell>
                                        <TableCell>{p.max_users ?? 'Tanpa batas'}</TableCell>
                                        <TableCell>{p.max_companies ?? 'Tanpa batas'}</TableCell>
                                        <TableCell className="space-x-1">
                                            <Badge variant={p.is_active ? 'success' : 'secondary'}>{p.is_active ? 'Aktif' : 'Nonaktif'}</Badge>
                                            {!p.is_public && <Badge variant="outline">Internal</Badge>}
                                        </TableCell>
                                        <TableCell><Button variant="ghost" size="icon" onClick={() => setDialogPlan(p)}><Pencil className="h-4 w-4" /></Button></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {dialogPlan && <PlanDialog plan={dialogPlan.id ? dialogPlan : null} onClose={() => setDialogPlan(null)} />}
        </PlatformLayout>
    );
}

function PlanDialog({ plan, onClose }) {
    const editing = !!plan;
    const { data, setData, post, put, processing, errors } = useForm({
        name: plan?.name || '',
        slug: plan?.slug || '',
        description: plan?.description || '',
        price_monthly: plan?.price_monthly ?? '',
        price_yearly: plan?.price_yearly ?? '',
        currency: plan?.currency ?? 'IDR',
        trial_days: plan?.trial_days ?? '',
        max_users: plan?.max_users ?? '',
        max_companies: plan?.max_companies ?? '',
        is_active: plan?.is_active ?? true,
        is_public: plan?.is_public ?? true,
        is_custom: plan?.is_custom ?? false,
        sort_order: plan?.sort_order ?? 0,
    });

    function submit(e) {
        e.preventDefault();
        if (editing) {
            put(route('platform.plans.update', plan.id), { preserveScroll: true, onSuccess: onClose });
        } else {
            post(route('platform.plans.store'), { preserveScroll: true, onSuccess: onClose });
        }
    }

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader><DialogTitle>{editing ? `Ubah ${plan.name}` : 'Paket baru'}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5"><Label>Nama</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Slug</Label><Input value={data.slug} onChange={(e) => setData('slug', e.target.value)} /></div>
                    </div>
                    <div className="space-y-1.5"><Label>Deskripsi</Label><Textarea rows={2} value={data.description} onChange={(e) => setData('description', e.target.value)} /></div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5"><Label>Harga / bulan</Label><Input type="number" min="0" step="0.01" value={data.price_monthly} onChange={(e) => setData('price_monthly', e.target.value)} disabled={data.is_custom} /></div>
                        <div className="space-y-1.5"><Label>Harga / tahun</Label><Input type="number" min="0" step="0.01" value={data.price_yearly} onChange={(e) => setData('price_yearly', e.target.value)} disabled={data.is_custom} /></div>
                        <div className="space-y-1.5"><Label>Mata uang</Label><Input value={data.currency} onChange={(e) => setData('currency', e.target.value.toUpperCase())} maxLength={3} /></div>
                        <div className="space-y-1.5"><Label>Hari uji coba (kosong = tanpa uji coba)</Label><Input type="number" min="0" value={data.trial_days} onChange={(e) => setData('trial_days', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Maks pengguna (kosong = tanpa batas)</Label><Input type="number" min="1" value={data.max_users} onChange={(e) => setData('max_users', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Maks unit operasi (kosong = tanpa batas)</Label><Input type="number" min="1" value={data.max_companies} onChange={(e) => setData('max_companies', e.target.value)} /></div>
                    </div>
                    <div className="flex flex-wrap items-center gap-4">
                        <div className="flex items-center gap-2"><Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', Boolean(v))} /><Label className="!mt-0">Aktif</Label></div>
                        <div className="flex items-center gap-2"><Checkbox checked={data.is_public} onCheckedChange={(v) => setData('is_public', Boolean(v))} /><Label className="!mt-0">Tampilkan di halaman harga</Label></div>
                        <div className="flex items-center gap-2"><Checkbox checked={data.is_custom} onCheckedChange={(v) => setData('is_custom', Boolean(v))} /><Label className="!mt-0">Harga kustom (&quot;Hubungi kami&quot;)</Label></div>
                    </div>
                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">{Object.values(errors).map((m, i) => <p key={i}>{m}</p>)}</div>
                    )}
                    <DialogFooter><Button type="button" variant="outline" onClick={onClose}>Batal</Button><Button type="submit" disabled={processing}>Simpan</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
