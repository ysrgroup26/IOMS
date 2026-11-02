/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Plus, Trash2, Pencil } from 'lucide-react';
import { FormField, FormActions, ErrorSummary, SearchableSelect } from '@/Components/shared/form';

export default function DepartmentsTab({ departments, companies, filters }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset, errors } = useForm({ company_id: undefined, name: '', description: '', code: '', sort_order: 0 });

    function filterByCompany(companyId) {
        router.get(route('settings.index'), { ...filters, company_id: companyId === 'all' ? null : companyId, tab: 'departments' }, { preserveState: true, preserveScroll: true, replace: true });
    }

    function openCreate() {
        setEditing(null);
        reset();
        setOpen(true);
    }

    function openEdit(dept) {
        setEditing(dept);
        setData({ company_id: String(dept.company_id), name: dept.name, description: dept.description ?? '', code: dept.code ?? '', sort_order: dept.sort_order ?? 0 });
        setOpen(true);
    }

    function submit(e) {
        e.preventDefault();
        const options = { onSuccess: () => { reset(); setOpen(false); } };
        if (editing) {
            put(route('settings.departments.update', editing.id), options);
        } else {
            post(route('settings.departments.store'), options);
        }
    }

    function destroy(id) {
        if (confirm('Hapus departemen ini?')) router.delete(route('settings.departments.destroy', id));
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <div>
                    <CardTitle>Departments</CardTitle>
                    <CardDescription>Manage department master data per company. Display Order controls where each department appears throughout the app.</CardDescription>
                </div>
                <Button size="sm" onClick={openCreate}><Plus className="h-4 w-4" /> Add</Button>
            </CardHeader>
            <CardContent>
                <div className="mb-3 flex items-center gap-2">
                    <Label className="text-xs text-graphite-500">Operating Unit</Label>
                    <Select value={filters?.company_id ? String(filters.company_id) : "all"} onValueChange={filterByCompany}>
                        <SelectTrigger className="w-48"><SelectValue placeholder="All Operating Units" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Operating Units</SelectItem>
                            {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </div>
                <Table>
                    <TableHeader><TableRow><TableHead>Order</TableHead><TableHead>Name</TableHead><TableHead>Operating Unit</TableHead><TableHead>Code</TableHead><TableHead>Employees</TableHead><TableHead /></TableRow></TableHeader>
                    <TableBody>
                        {departments.map((d) => (
                            <TableRow key={d.id}>
                                <TableCell className="text-graphite-400">{d.sort_order}</TableCell>
                                <TableCell className="font-medium">{d.name}</TableCell>
                                <TableCell>{d.company?.name ?? '-'}</TableCell>
                                <TableCell>{d.code ?? '-'}</TableCell>
                                <TableCell>{d.employees_count}</TableCell>
                                <TableCell className="flex gap-1">
                                    <Button variant="ghost" size="icon" onClick={() => openEdit(d)}><Pencil className="h-4 w-4" /></Button>
                                    <Button variant="ghost" size="icon" onClick={() => destroy(d.id)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit Department' : 'Add Department'}</DialogTitle></DialogHeader>
                    {/* v2.66.0 -- a DIALOG uses FormField only: no ErrorSummary
                        (everything is already in view) and no FormActions
                        (DialogFooter is the action area). See
                        Components/shared/form/index.js for the rule. */}
                    <form onSubmit={submit} className="space-y-3">
                        <FormField label="Operating Unit" name="company_id" required error={errors.company_id}>
                            {(control) => (
                                <SearchableSelect
                                    {...control}
                                    value={data.company_id ?? ''}
                                    onChange={(v) => setData('company_id', v)}
                                    options={companies.map((c) => ({ value: c.id, label: c.name }))}
                                    placeholder="Select operating unit"
                                />
                            )}
                        </FormField>
                        <FormField label="Name" name="name" required error={errors.name}>
                            {(control) => <Input {...control} value={data.name} onChange={(e) => setData('name', e.target.value)} />}
                        </FormField>
                        <FormField label="Code" name="code" error={errors.code} hint="Short identifier used on documents and exports.">
                            {(control) => <Input {...control} value={data.code} onChange={(e) => setData('code', e.target.value)} uppercase />}
                        </FormField>
                        <div className="space-y-1.5">
                            <Label>Display Order</Label>
                            <Input type="number" min="0" value={data.sort_order} onChange={(e) => setData('sort_order', e.target.value)} />
                            <p className="text-xs text-graphite-400">Lower numbers appear first throughout the app.</p>
                            {errors.sort_order && <p className="text-xs text-red-600">{errors.sort_order}</p>}
                        </div>
                        <DialogFooter><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
