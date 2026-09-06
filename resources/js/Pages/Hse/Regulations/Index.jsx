import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Scale, Plus, Paperclip, AlertTriangle, Search, Pencil, Trash2 } from 'lucide-react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import FilterBar from '@/Components/shared/FilterBar';
import StatCard from '@/Components/shared/StatCard';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/Components/ui/dialog';

/**
 * v2.52.0 -- Regulations & Standards Register.
 *
 * The register of legal and normative requirements the Health, Safety &
 * Environment function must identify, keep current, and evidence
 * compliance against. Every HSE management system asks for one; before
 * this it lived in a spreadsheet nobody reviewed.
 *
 * "Due for review" is given its own stat card and its own filter on
 * purpose: it is what makes this a LIVING register rather than a list
 * typed once. A regulation whose review date has passed may well have
 * been superseded without anyone noticing, and that is exactly the gap an
 * auditor finds.
 *
 * Category and document type are free text with suggestions, not fixed
 * dropdowns — a provincial regulation, a pressure-vessel rule, an ISO
 * standard and an internal company standard all have to fit.
 */
export default function RegulationsIndex({
    registers, stats, filters = {}, categories = [], documentTypes = [],
    statuses = [], companies = [], employees = [], canManage,
}) {
    const { flash = {} } = usePage().props;
    const [search, setSearch] = useState(filters.search || '');
    const [editing, setEditing] = useState(null);
    const [open, setOpen] = useState(false);

    const apply = (next) => {
        router.get(route('hse-regulations.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    };

    const fmt = (v) => (v ? new Date(v).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

    const startCreate = () => { setEditing(null); setOpen(true); };
    const startEdit = (row) => { setEditing(row); setOpen(true); };

    const remove = (row) => {
        if (! confirm(`Hapus entri "${row.title}" dari register?`)) return;
        router.delete(route('hse-regulations.destroy', row.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Regulations & Standards" />

            <PageHeader
                icon={Scale}
                title="Regulations & Standards"
                subtitle="Register peraturan dan standar yang berlaku bagi operasi perusahaan Anda."
            >
                {canManage && (
                    <Button onClick={startCreate}><Plus className="h-4 w-4" /> Add Entry</Button>
                )}
            </PageHeader>

            {flash.success && (
                <div className="mb-4 rounded-lg border border-success/20 bg-success/[0.07] p-4 text-sm text-emerald-900">{flash.success}</div>
            )}

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard icon={Scale} label="Total Entries" value={stats.total} accent="brand" />
                <StatCard icon={Scale} label="Active" value={stats.active} accent="green" />
                <StatCard icon={AlertTriangle} label="Due for Review" value={stats.due_for_review} accent="amber" />
                <StatCard icon={Scale} label="Superseded" value={stats.superseded} accent="purple" />
            </div>

            <FilterBar>
                <FilterBar.Search
                    value={search}
                    onChange={(e) => { setSearch(e.target.value); apply({ search: e.target.value }); }}
                    placeholder="Search title, number, or authority..."
                />
                <select
                    value={filters.category || ''}
                    onChange={(e) => apply({ category: e.target.value || null })}
                    className="h-9 rounded-md border border-steel-200 bg-white px-3 text-sm text-navy-900"
                >
                    <option value="">All categories</option>
                    {categories.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
                <select
                    value={filters.status || ''}
                    onChange={(e) => apply({ status: e.target.value || null })}
                    className="h-9 rounded-md border border-steel-200 bg-white px-3 text-sm text-navy-900"
                >
                    <option value="">All statuses</option>
                    {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
                <Button
                    variant={filters.due_for_review ? 'default' : 'outline'}
                    onClick={() => apply({ due_for_review: filters.due_for_review ? null : 1 })}
                >
                    <AlertTriangle className="h-4 w-4" /> Due for review
                </Button>
            </FilterBar>

            <Card>
                <CardContent className="p-0">
                    {registers.data.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                icon={Scale}
                                title="No entries yet"
                                description="Tambahkan peraturan, standar, atau persyaratan pelanggan yang berlaku bagi operasi Anda."
                            />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Regulation / Standard</TableHead>
                                        <TableHead>Category</TableHead>
                                        <TableHead>Issuing Authority</TableHead>
                                        <TableHead>Effective</TableHead>
                                        <TableHead>Review</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {registers.data.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell>
                                                <span className="font-medium text-navy-900">{row.title}</span>
                                                <span className="block text-[11px] text-graphite-400">
                                                    {row.citation || row.document_type}
                                                    {row.register_number && ` · ${row.register_number}`}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-graphite-600">{row.category}</TableCell>
                                            <TableCell className="text-graphite-600">{row.issuing_authority || '—'}</TableCell>
                                            <TableCell className="whitespace-nowrap text-graphite-500">{fmt(row.effective_date)}</TableCell>
                                            <TableCell className="whitespace-nowrap">
                                                <span className={row.is_due_for_review ? 'font-semibold text-danger' : 'text-graphite-500'}>
                                                    {fmt(row.review_date)}
                                                </span>
                                            </TableCell>
                                            <TableCell><StatusBadge value={row.status} /></TableCell>
                                            <TableCell className="whitespace-nowrap text-right">
                                                {row.document_url && (
                                                    <a href={row.document_url} className="mr-2 inline-flex items-center text-brand-700 hover:underline" title="Unduh dokumen">
                                                        <Paperclip className="h-4 w-4" />
                                                    </a>
                                                )}
                                                {canManage && (
                                                    <>
                                                        <button onClick={() => startEdit(row)} className="mr-2 text-graphite-500 hover:text-navy-800" title="Ubah">
                                                            <Pencil className="h-4 w-4" />
                                                        </button>
                                                        <button onClick={() => remove(row)} className="text-graphite-400 hover:text-danger" title="Hapus">
                                                            <Trash2 className="h-4 w-4" />
                                                        </button>
                                                    </>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            </Card>

            {canManage && (
                <RegulationDialog
                    /* Remounts per record: useForm seeds its state once, so
                       without a key the dialog would keep the previous
                       entry's values when you open a different row. */
                    key={editing?.id || 'new'}
                    open={open}
                    onOpenChange={setOpen}
                    editing={editing}
                    categories={categories}
                    documentTypes={documentTypes}
                    statuses={statuses}
                    companies={companies}
                    employees={employees}
                />
            )}
        </AuthenticatedLayout>
    );
}

function RegulationDialog({ open, onOpenChange, editing, categories, documentTypes, statuses, companies, employees }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        company_id: editing?.company_id || companies[0]?.id || '',
        category: editing?.category || '',
        document_type: editing?.document_type || '',
        regulation_number: editing?.regulation_number || '',
        year: editing?.year || '',
        title: editing?.title || '',
        issuing_authority: editing?.issuing_authority || '',
        status: editing?.status || 'active',
        effective_date: editing?.effective_date?.slice(0, 10) || '',
        review_date: editing?.review_date?.slice(0, 10) || '',
        applicability: editing?.applicability || '',
        scope: editing?.scope || '',
        owner_employee_id: editing?.owner_employee_id || '',
        source_reference: editing?.source_reference || '',
        compliance_reference: editing?.compliance_reference || '',
        notes: editing?.notes || '',
        document: null,
    });

    const submit = (e) => {
        e.preventDefault();
        // Both create and update POST, because a file upload cannot ride a
        // PUT through Inertia without method spoofing gymnastics.
        const url = editing ? route('hse-regulations.update', editing.id) : route('hse-regulations.store');
        post(url, { forceFormData: true, preserveScroll: true, onSuccess: () => { reset(); onOpenChange(false); } });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{editing ? 'Ubah Entri Register' : 'Tambah Regulasi / Standar'}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label>Judul <span className="text-danger">*</span></Label>
                        <Input value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="mis. Keselamatan dan Kesehatan Kerja Lingkungan Kerja" />
                        {errors.title && <p className="text-xs text-danger">{errors.title}</p>}
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label>Kategori <span className="text-danger">*</span></Label>
                            <Input list="reg-categories" value={data.category} onChange={(e) => setData('category', e.target.value)} placeholder="Occupational Safety" />
                            <datalist id="reg-categories">{categories.map((c) => <option key={c} value={c} />)}</datalist>
                            {errors.category && <p className="text-xs text-danger">{errors.category}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Jenis Dokumen <span className="text-danger">*</span></Label>
                            <Input list="reg-types" value={data.document_type} onChange={(e) => setData('document_type', e.target.value)} placeholder="Peraturan Menteri" />
                            <datalist id="reg-types">{documentTypes.map((t) => <option key={t} value={t} />)}</datalist>
                            {errors.document_type && <p className="text-xs text-danger">{errors.document_type}</p>}
                        </div>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="space-y-1.5">
                            <Label>Nomor</Label>
                            <Input value={data.regulation_number} onChange={(e) => setData('regulation_number', e.target.value)} placeholder="5" />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Tahun</Label>
                            <Input type="number" value={data.year} onChange={(e) => setData('year', e.target.value)} placeholder="2018" />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Status</Label>
                            <select value={data.status} onChange={(e) => setData('status', e.target.value)} className="h-9 w-full rounded-md border border-steel-200 bg-white px-3 text-sm">
                                {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label>Instansi Penerbit</Label>
                        <Input value={data.issuing_authority} onChange={(e) => setData('issuing_authority', e.target.value)} placeholder="Kementerian Ketenagakerjaan" />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label>Tanggal Berlaku</Label>
                            <Input type="date" value={data.effective_date} onChange={(e) => setData('effective_date', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Tanggal Tinjau Ulang</Label>
                            <Input type="date" value={data.review_date} onChange={(e) => setData('review_date', e.target.value)} />
                        </div>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label>Perusahaan</Label>
                            <select value={data.company_id} onChange={(e) => setData('company_id', e.target.value)} className="h-9 w-full rounded-md border border-steel-200 bg-white px-3 text-sm">
                                {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Penanggung Jawab (PIC)</Label>
                            <select value={data.owner_employee_id} onChange={(e) => setData('owner_employee_id', e.target.value)} className="h-9 w-full rounded-md border border-steel-200 bg-white px-3 text-sm">
                                <option value="">—</option>
                                {employees.map((e2) => <option key={e2.id} value={e2.id}>{e2.full_name}</option>)}
                            </select>
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label>Keberlakuan / Ruang Lingkup</Label>
                        <Textarea rows={2} value={data.applicability} onChange={(e) => setData('applicability', e.target.value)} placeholder="Bagian operasi mana yang terikat oleh persyaratan ini." />
                    </div>

                    <div className="space-y-1.5">
                        <Label>Bukti Kepatuhan</Label>
                        <Textarea rows={2} value={data.compliance_reference} onChange={(e) => setData('compliance_reference', e.target.value)} placeholder="Prosedur, izin, atau catatan yang membuktikan kepatuhan." />
                    </div>

                    <div className="space-y-1.5">
                        <Label>Sumber / Tautan</Label>
                        <Input value={data.source_reference} onChange={(e) => setData('source_reference', e.target.value)} placeholder="https://..." />
                    </div>

                    <div className="space-y-1.5">
                        <Label>Dokumen (opsional)</Label>
                        <Input type="file" onChange={(e) => setData('document', e.target.files[0] || null)} accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" />
                        <p className="text-[11px] text-graphite-500">
                            Disimpan pada penyimpanan privat dan hanya dapat diunduh oleh pengguna perusahaan Anda.
                        </p>
                        {errors.document && <p className="text-xs text-danger">{errors.document}</p>}
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Batal</Button>
                        <Button type="submit" disabled={processing}>{editing ? 'Simpan' : 'Tambah'}</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
