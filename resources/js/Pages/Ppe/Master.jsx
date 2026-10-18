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
import { FormField } from '@/Components/shared/form';
import { Checkbox } from '@/Components/ui/checkbox';
import { Plus, Trash2, Pencil } from 'lucide-react';
import PpeTabNav from '@/Components/shared/PpeTabNav';
import PageHeader from '@/Components/shared/PageHeader';

export default function PpeMaster({ ppeTypes, can, companies = [], selectedCompanyId, complianceRoles = {}, complianceGaps = [] }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [companyId, setCompanyId] = useState(String(selectedCompanyId ?? ''));

    const { data, setData, post, put, processing, reset, errors } = useForm({
        name: '',
        // v2.121.0: which completeness requirement this type satisfies.
        // '' means unclassified; 'additional' says so explicitly.
        compliance_role: '',
        replacement_interval_months: '',
        is_active: true,
        company_id: selectedCompanyId,
    });

    function openCreate() {
        setEditing(null);
        reset();
        setData('company_id', selectedCompanyId);
        setOpen(true);
    }

    function openEdit(type) {
        setEditing(type);
        setData({
            name: type.name,
            // The role IN FORCE, including one inherited from a template, so
            // saving an edit cannot silently drop a requirement.
            compliance_role: type.effective_compliance_role ?? '',
            replacement_interval_months: type.replacement_interval_months ?? '',
            is_active: type.is_active,
            company_id: selectedCompanyId,
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

    function destroy(type) {
        if (confirm(type.source_default_id || !type.company_id
            ? 'Deactivate this default PPE type for this Operating Unit? Existing issue history will be preserved.'
            : 'Delete this PPE type? Deletion is allowed only when there is no issue history.')) {
            router.delete(route('ppe-types.destroy', type.id), { data: { company_id: companyId } });
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title="PPE Master" />

            <PpeTabNav />

            <PageHeader title="PPE Master" subtitle="Data konfigurasi, bukan operasional harian -- atur jenis APD dan interval penggantian di sini. Untuk mengeluarkan/mengganti APD karyawan, buka tab Employee PPE.">
                {can.manage && (
                    <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add PPE Type</Button>
                )}
            </PageHeader>

            {companies.length > 1 && (
                <div className="mb-4 max-w-sm space-y-1.5">
                    <Label htmlFor="ppe-company">Operating Unit</Label>
                    <select id="ppe-company" className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm" value={companyId}
                        onChange={(e) => { setCompanyId(e.target.value); setData('company_id', Number(e.target.value)); router.get(route('ppe.master'), { company_id: e.target.value }, { preserveState: true, preserveScroll: true }); }}>
                        {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
                    </select>
                </div>
            )}

            {complianceGaps.length > 0 && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-relaxed text-amber-900">
                    <p className="font-semibold">
                        {complianceGaps.map((r) => complianceRoles[r] ?? r).join(', ')} belum memiliki jenis PPE aktif.
                    </p>
                    <p className="mt-1">
                        Selama syarat ini belum ada jenisnya, tidak ada karyawan yang dapat berstatus Aman. Tandai
                        jenis yang sesuai lewat kolom &ldquo;Counts as&rdquo; pada Edit, atau tambahkan jenisnya.
                    </p>
                </div>
            )}

            <Card>
                <CardHeader><CardTitle>PPE Types</CardTitle><CardDescription>Request-based equipment (no interval) is still tracked in history.</CardDescription></CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Requirement</TableHead>
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
                                        {t.effective_compliance_role
                                            ? <Badge variant="outline">{complianceRoles[t.effective_compliance_role] ?? t.effective_compliance_role}</Badge>
                                            : <span className="text-xs text-graphite-400">Additional</span>}
                                    </TableCell>
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
                                            <Button variant="ghost" size="icon" onClick={() => destroy(t)} aria-label={t.source_default_id || !t.company_id ? 'Deactivate PPE type' : 'Delete PPE type'}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                            {ppeTypes.length === 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell colSpan={6} className="py-10 text-center">
                                        <span className="text-sm font-semibold text-navy-800">No PPE types yet</span>
                                        <span className="mt-0.5 block text-xs text-graphite-500">Tambahkan jenis APD agar dapat dipilih saat pembagian.</span>
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit PPE Type' : 'Add PPE Type'}</DialogTitle></DialogHeader>
                    {/* v2.66.0 -- DIALOG FORMS USE FormField AND NOTHING ELSE
                        from the form system. A dialog is short and entirely in
                        view, so an ErrorSummary would restate what is already
                        on screen, and FormActions would compete with
                        DialogFooter, which IS the action area. What a dialog
                        genuinely lacked was the field contract: requiredness,
                        aria wiring, and an error slot that cannot be omitted.
                        See Components/shared/form/index.js. */}
                    <form onSubmit={submit} className="space-y-4">
                        <FormField label="Name" name="name" required error={errors.name}>
                            {(control) => (
                                <Input {...control} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Safety Helmet" />
                            )}
                        </FormField>

                        {/*
                            v2.121.0 -- WITHOUT THIS THE RULE CANNOT BE
                            CONFIGURED. A type is identified in the database
                            only by its display name, and ADR 050 lets a
                            customer rename one through an override, so the
                            completeness rule reads this declared role rather
                            than matching a string. A customer who issues
                            uniforms adds the type and marks it here.
                        */}
                        <FormField
                            label="Counts as"
                            name="compliance_role"
                            error={errors.compliance_role}
                            hint="Menentukan apakah jenis ini memenuhi salah satu syarat kelengkapan APD. Pakaian kerja dapat dipenuhi oleh lebih dari satu jenis -- Work Uniform atau Coverall, salah satu saja cukup."
                        >
                            {(control) => (
                                <select
                                    {...control}
                                    className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                                    value={data.compliance_role}
                                    onChange={(e) => setData('compliance_role', e.target.value)}
                                >
                                    <option value="">Additional equipment (not required)</option>
                                    {Object.entries(complianceRoles)
                                        .filter(([key]) => key !== 'additional')
                                        .map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                                    <option value="additional">Additional equipment (stated explicitly)</option>
                                </select>
                            )}
                        </FormField>

                        <FormField
                            label="Replacement interval (months)"
                            name="replacement_interval_months"
                            error={errors.replacement_interval_months}
                            hint="Leave empty for request-based equipment such as a harness. Drives the PPE expiry date at issue."
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    type="number" inputMode="numeric" min="1" max="120"
                                    value={data.replacement_interval_months}
                                    onChange={(e) => setData('replacement_interval_months', e.target.value)}
                                />
                            )}
                        </FormField>
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
