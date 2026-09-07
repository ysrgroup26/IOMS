import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { FileSignature, Plus, Info } from 'lucide-react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import FilterBar from '@/Components/shared/FilterBar';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';

/**
 * v2.52.0 -- BAST (Berita Acara Serah Terima).
 *
 * A FORMAL HANDOVER INSTRUMENT, and deliberately not a Goods Receipt.
 * Two named parties record that defined work, goods or services were
 * handed over and accepted; both sign; it is later produced as evidence
 * against a contract or a payment.
 *
 * A Goods Receipt is a warehouse transaction whose consequence is a stock
 * level. A completed Work Order handed to the asset owner needs a BAST
 * and moves no stock at all. The note at the top of this page says so,
 * because the two were previously one document and users will have
 * learned the wrong model.
 */
export default function HandoverRecordsIndex({
    records, filters = {}, statuses = [], types = [], companies = [], employees = [], sources = {}, canManage,
}) {
    const { flash = {} } = usePage().props;
    const [search, setSearch] = useState(filters.search || '');
    const [open, setOpen] = useState(false);

    const apply = (next) =>
        router.get(route('handover-records.index'), { ...filters, ...next }, { preserveState: true, replace: true });

    const fmt = (v) => (v ? new Date(v).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

    return (
        <AuthenticatedLayout>
            <Head title="BAST" />

            <PageHeader
                icon={FileSignature}
                title="Berita Acara Serah Terima"
                subtitle="Dokumen serah terima formal antara dua pihak atas pekerjaan, barang, atau jasa."
            >
                {canManage && <Button onClick={() => setOpen(true)}><Plus className="h-4 w-4" /> New BAST</Button>}
            </PageHeader>

            {flash.success && (
                <div className="mb-4 rounded-lg border border-success/20 bg-success/[0.07] p-4 text-sm text-emerald-900">{flash.success}</div>
            )}

            {/* The distinction users most need stated, because these two
                documents used to be one. */}
            <div className="mb-4 flex items-start gap-2.5 rounded-lg border border-steel-200/70 bg-steel-50/70 p-3.5 text-xs leading-relaxed text-graphite-600">
                <Info className="mt-0.5 h-4 w-4 shrink-0 text-graphite-400" />
                <span>
                    <strong className="text-navy-800">BAST berbeda dengan Goods Receipt.</strong> Goods Receipt mencatat
                    penerimaan fisik barang ke gudang dan memperbarui stok. BAST adalah dokumen serah terima formal antara
                    dua pihak dan menjadi bukti kontraktual — misalnya penyelesaian pekerjaan berdasarkan SPK. Sebuah BAST
                    boleh merujuk pada Goods Receipt, tetapi keduanya bukan dokumen yang sama.
                </span>
            </div>

            <FilterBar>
                <FilterBar.Search
                    value={search}
                    onChange={(e) => { setSearch(e.target.value); apply({ search: e.target.value }); }}
                    placeholder="Search number, subject, or second party..."
                />
                <select
                    value={filters.handover_type || ''}
                    onChange={(e) => apply({ handover_type: e.target.value || null })}
                    className="h-9 rounded-md border border-steel-200 bg-white px-3 text-sm text-navy-900"
                >
                    <option value="">All types</option>
                    {types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </select>
                <select
                    value={filters.status || ''}
                    onChange={(e) => apply({ status: e.target.value || null })}
                    className="h-9 rounded-md border border-steel-200 bg-white px-3 text-sm text-navy-900"
                >
                    <option value="">All statuses</option>
                    {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
            </FilterBar>

            <Card>
                <CardContent className="p-0">
                    {records.data.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                icon={FileSignature}
                                title="No BAST records yet"
                                description="Buat Berita Acara Serah Terima ketika pekerjaan, barang, atau jasa diserahkan secara formal kepada pihak lain."
                            />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Number</TableHead>
                                        <TableHead>Subject</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Second Party</TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {records.data.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell className="whitespace-nowrap">
                                                <Link href={route('handover-records.show', row.id)} className="font-medium text-brand-700 hover:underline">
                                                    {row.bast_number}
                                                </Link>
                                                {row.reference_number && (
                                                    <span className="block text-[11px] text-graphite-400">Ref: {row.reference_number}</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-navy-900">{row.title}</TableCell>
                                            <TableCell className="text-graphite-600">{row.type_label}</TableCell>
                                            <TableCell className="text-graphite-600">
                                                {row.second_party_name}
                                                {row.second_party_organization && (
                                                    <span className="block text-[11px] text-graphite-400">{row.second_party_organization}</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap text-graphite-500">{fmt(row.handover_date)}</TableCell>
                                            <TableCell><StatusBadge value={row.status} /></TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            </Card>

            {canManage && (
                <HandoverDialog
                    open={open}
                    onOpenChange={setOpen}
                    types={types}
                    statuses={statuses}
                    companies={companies}
                    employees={employees}
                    sources={sources}
                />
            )}
        </AuthenticatedLayout>
    );
}

function HandoverDialog({ open, onOpenChange, types, statuses, companies, employees, sources }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        company_id: companies[0]?.id || '',
        handover_type: 'work_completion',
        title: '',
        handover_date: new Date().toISOString().slice(0, 10),
        first_party_name: '',
        first_party_position: '',
        first_party_organization: '',
        first_party_employee_id: '',
        second_party_name: '',
        second_party_position: '',
        second_party_organization: '',
        source_key: '',
        source_id: '',
        reference_number: '',
        scope: '',
        acceptance_statement: '',
        status: 'draft',
        notes: '',
    });

    const sourceOptions = data.source_key ? (sources[data.source_key] || []) : [];

    const submit = (e) => {
        e.preventDefault();
        post(route('handover-records.store'), {
            onSuccess: () => { reset(); onOpenChange(false); },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
                <DialogHeader><DialogTitle>Buat Berita Acara Serah Terima</DialogTitle></DialogHeader>

                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label>Jenis Serah Terima</Label>
                            <select value={data.handover_type} onChange={(e) => setData('handover_type', e.target.value)} className="h-9 w-full rounded-md border border-steel-200 bg-white px-3 text-sm">
                                {types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                            </select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Tanggal <span className="text-danger">*</span></Label>
                            <Input type="date" value={data.handover_date} onChange={(e) => setData('handover_date', e.target.value)} />
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label>Perihal <span className="text-danger">*</span></Label>
                        <Input value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="mis. Penyelesaian Overhaul Crane #4" />
                        {errors.title && <p className="text-xs text-danger">{errors.title}</p>}
                    </div>

                    {/* Reuse, not duplication: the BAST points at the record that
                        already holds the business data. */}
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="space-y-1.5">
                            <Label>Dokumen Sumber</Label>
                            <select value={data.source_key} onChange={(e) => { setData('source_key', e.target.value); setData('source_id', ''); }} className="h-9 w-full rounded-md border border-steel-200 bg-white px-3 text-sm">
                                <option value="">—</option>
                                <option value="work_order">Work Order (SPK)</option>
                                <option value="purchase_order">Purchase Order</option>
                                <option value="project">Proyek</option>
                            </select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Pilih Dokumen</Label>
                            <select value={data.source_id} onChange={(e) => setData('source_id', e.target.value)} disabled={! data.source_key} className="h-9 w-full rounded-md border border-steel-200 bg-white px-3 text-sm disabled:opacity-50">
                                <option value="">—</option>
                                {sourceOptions.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
                            </select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>No. Kontrak / Referensi</Label>
                            <Input value={data.reference_number} onChange={(e) => setData('reference_number', e.target.value)} placeholder="Bila di luar IOMS" />
                        </div>
                    </div>

                    <fieldset className="rounded-lg border border-steel-200/70 p-3.5">
                        <legend className="px-1 text-[11px] font-semibold uppercase tracking-wide text-brand-700">Pihak Pertama</legend>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Nama <span className="text-danger">*</span></Label>
                                <Input value={data.first_party_name} onChange={(e) => setData('first_party_name', e.target.value)} />
                                {errors.first_party_name && <p className="text-xs text-danger">{errors.first_party_name}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Jabatan</Label>
                                <Input value={data.first_party_position} onChange={(e) => setData('first_party_position', e.target.value)} />
                            </div>
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label>Karyawan (opsional)</Label>
                                <select value={data.first_party_employee_id} onChange={(e) => setData('first_party_employee_id', e.target.value)} className="h-9 w-full rounded-md border border-steel-200 bg-white px-3 text-sm">
                                    <option value="">—</option>
                                    {employees.map((emp) => <option key={emp.id} value={emp.id}>{emp.full_name}</option>)}
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset className="rounded-lg border border-steel-200/70 p-3.5">
                        <legend className="px-1 text-[11px] font-semibold uppercase tracking-wide text-brand-700">Pihak Kedua</legend>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Nama <span className="text-danger">*</span></Label>
                                <Input value={data.second_party_name} onChange={(e) => setData('second_party_name', e.target.value)} />
                                {errors.second_party_name && <p className="text-xs text-danger">{errors.second_party_name}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Jabatan</Label>
                                <Input value={data.second_party_position} onChange={(e) => setData('second_party_position', e.target.value)} />
                            </div>
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label>Instansi / Perusahaan</Label>
                                <Input value={data.second_party_organization} onChange={(e) => setData('second_party_organization', e.target.value)} />
                            </div>
                        </div>
                    </fieldset>

                    <div className="space-y-1.5">
                        <Label>Uraian / Ruang Lingkup</Label>
                        <Textarea rows={3} value={data.scope} onChange={(e) => setData('scope', e.target.value)} />
                    </div>

                    <div className="space-y-1.5">
                        <Label>Pernyataan Serah Terima (opsional)</Label>
                        <Textarea rows={2} value={data.acceptance_statement} onChange={(e) => setData('acceptance_statement', e.target.value)} placeholder="Kosongkan untuk memakai pernyataan baku IOMS." />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label>Operating Unit</Label>
                            <select value={data.company_id} onChange={(e) => setData('company_id', e.target.value)} className="h-9 w-full rounded-md border border-steel-200 bg-white px-3 text-sm">
                                {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Status</Label>
                            <select value={data.status} onChange={(e) => setData('status', e.target.value)} className="h-9 w-full rounded-md border border-steel-200 bg-white px-3 text-sm">
                                {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Batal</Button>
                        <Button type="submit" disabled={processing}>Buat BAST</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
