/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { AVAILABLE_ICON_NAMES } from '@/lib/iconMap';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Plus, Trash2, Pencil } from 'lucide-react';

/**
 * Self-service credential change. Available to both Super Admin and HSE
 * (route is gated the same way, see routes/web.php) -- this only ever
 * changes the CURRENTLY LOGGED IN user's own email/password, never
 * another account (that's the separate, Super-Admin-only Users tab).
 * Future-ready: Email verification and 2FA fields can be added here
 * later without restructuring this form.
 */
/**
 * KPI categories are fully data-driven -- never hardcoded (v1.5.0). A
 * category with no Company selected is Global (applies to every company);
 * assigning a Company scopes it to only that company, so different
 * companies can run entirely different KPI sets. Reordering is via the
 * same numeric Display Order pattern used for Departments/Positions.
 */
export default function KpiCategoriesTab({ kpiCategories, companies }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset, errors } = useForm({
        name: '', short_label: '', description: '', company_id: '',
        is_negative: false, show_on_dashboard: true, supports_quick_attendance: false,
        requires_approval: false, count_in_dashboard_stats: true,
        icon: '', color: '', sort_order: 0, is_active: true,
    });

    function openCreate() {
        setEditing(null);
        reset();
        setOpen(true);
    }

    function openEdit(cat) {
        setEditing(cat);
        setData({
            name: cat.name,
            short_label: cat.short_label,
            description: cat.description ?? '',
            company_id: cat.company_id ? String(cat.company_id) : '',
            is_negative: cat.is_negative,
            show_on_dashboard: cat.show_on_dashboard,
            supports_quick_attendance: cat.supports_quick_attendance,
            requires_approval: cat.requires_approval,
            count_in_dashboard_stats: cat.count_in_dashboard_stats,
            icon: cat.icon ?? '',
            color: cat.color ?? '',
            sort_order: cat.sort_order ?? 0,
            is_active: cat.is_active,
        });
        setOpen(true);
    }

    function submit(e) {
        e.preventDefault();
        const options = { onSuccess: () => { reset(); setOpen(false); } };
        if (editing) {
            put(route('settings.kpi-categories.update', editing.id), options);
        } else {
            post(route('settings.kpi-categories.store'), options);
        }
    }

    /*
     * v2.119.0 -- THE DIALOG MUST PROMISE WHAT THE SERVER ACTUALLY DOES.
     *
     * A built-in (no company) is shared by every customer in the
     * installation, so it is never deleted: the server deactivates it for
     * THIS Operating Unit through an override. Saying "Hapus" for that
     * would describe an action nobody is taking.
     */
    function destroy(cat) {
        const isSharedDefault = !cat.company_id && !cat.source_default_id;

        if (confirm(isSharedDefault
            ? 'Nonaktifkan kategori KPI bawaan ini untuk unit operasi Anda? Kategori tetap tersedia bagi pelanggan lain, dan riwayat penilaian tidak dihapus.'
            : 'Hapus kategori KPI ini? Hanya bisa jika belum ada data KPI yang tercatat.')) {
            router.delete(route('settings.kpi-categories.destroy', cat.id));
        }
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <div>
                    <CardTitle>KPI Categories</CardTitle>
                    <CardDescription>
                        Configure the KPI categories your organization tracks. The Dashboard is generated entirely
                        from this configuration -- add a category and enable "Show on Dashboard" to see it appear
                        immediately, with no code changes. Kategori bawaan (Global) berlaku untuk seluruh
                        pelanggan IOMS: menyuntingnya akan menyimpan versi milik unit operasi Anda, dan kategori
                        bawaannya tidak berubah bagi pelanggan lain.
                    </CardDescription>
                </div>
                <Button size="sm" onClick={openCreate}><Plus className="h-4 w-4" /> Add KPI Category</Button>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Order</TableHead>
                            <TableHead>Name</TableHead>
                            <TableHead>Short Label</TableHead>
                            <TableHead>Scope</TableHead>
                            <TableHead>Type</TableHead>
                            <TableHead>Dashboard</TableHead>
                            <TableHead>Quick Attendance</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {kpiCategories.map((cat) => (
                            <TableRow key={cat.id}>
                                <TableCell className="text-graphite-400">{cat.sort_order}</TableCell>
                                <TableCell className="font-medium">{cat.name}</TableCell>
                                <TableCell>{cat.short_label}</TableCell>
                                <TableCell>
                                    <Badge variant={cat.company ? 'secondary' : 'outline'}>{cat.company?.name ?? 'Global'}</Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge variant={cat.is_negative ? 'destructive' : 'success'}>{cat.is_negative ? 'Incident' : 'Positive'}</Badge>
                                </TableCell>
                                <TableCell>{cat.show_on_dashboard ? 'Visible' : '-'}</TableCell>
                                <TableCell>{cat.supports_quick_attendance ? 'Yes' : '-'}</TableCell>
                                <TableCell><Badge variant={cat.is_active ? 'success' : 'secondary'}>{cat.is_active ? 'Active' : 'Inactive'}</Badge></TableCell>
                                <TableCell className="flex gap-1">
                                    <Button variant="ghost" size="icon" onClick={() => openEdit(cat)}><Pencil className="h-4 w-4" /></Button>
                                    <Button variant="ghost" size="icon" onClick={() => destroy(cat)} aria-label={!cat.company_id && !cat.source_default_id ? 'Deactivate KPI category' : 'Delete KPI category'}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[85vh] overflow-y-auto">
                    <DialogHeader><DialogTitle>{editing ? 'Edit KPI Category' : 'Add KPI Category'}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Name</Label>
                                <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Near Miss" />
                                {errors.name && <p className="text-xs text-red-600">{errors.name}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Short Label</Label>
                                <Input value={data.short_label} onChange={(e) => setData('short_label', e.target.value)} placeholder="e.g. Near Miss" maxLength={20} />
                                {errors.short_label && <p className="text-xs text-red-600">{errors.short_label}</p>}
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Description (optional)</Label>
                            <Input value={data.description} onChange={(e) => setData('description', e.target.value)} />
                        </div>

                        <div className="space-y-1.5">
                            <Label>Company Scope</Label>
                            <Select value={data.company_id || 'global'} onValueChange={(v) => setData('company_id', v === 'global' ? '' : v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="global">Global (all companies)</SelectItem>
                                    {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name} only</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Display Order</Label>
                                <Input type="number" min="0" value={data.sort_order} onChange={(e) => setData('sort_order', e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Card Color (optional)</Label>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="color"
                                        value={data.color || (data.is_negative ? '#dc2626' : '#2563eb')}
                                        onChange={(e) => setData('color', e.target.value)}
                                        className="h-9 w-9 shrink-0 cursor-pointer rounded-lg border border-input"
                                    />
                                    <Input value={data.color} onChange={(e) => setData('color', e.target.value)} placeholder="Defaults by type" />
                                </div>
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Icon (optional)</Label>
                            <Select value={data.icon || 'default'} onValueChange={(v) => setData('icon', v === 'default' ? '' : v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="default">Default (based on type)</SelectItem>
                                    {AVAILABLE_ICON_NAMES.map((name) => (
                                        <SelectItem key={name} value={name}>{name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-2 rounded-xl border border-steel-100 bg-white/60 p-3">
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={data.is_negative} onCheckedChange={(v) => setData('is_negative', !!v)} />
                                Incident Category (e.g. LTI, Fatality) -- shown in red when non-zero
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={data.show_on_dashboard} onCheckedChange={(v) => setData('show_on_dashboard', !!v)} />
                                Show on Dashboard
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={data.count_in_dashboard_stats} onCheckedChange={(v) => setData('count_in_dashboard_stats', !!v)} />
                                Count in Dashboard Statistics
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={data.supports_quick_attendance} onCheckedChange={(v) => setData('supports_quick_attendance', !!v)} />
                                Available in Quick Attendance (checklist-style bulk entry)
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={data.requires_approval} onCheckedChange={(v) => setData('requires_approval', !!v)} />
                                Requires Approval
                                <span className="text-xs text-graphite-400">(reserved for a future approval workflow -- not yet enforced)</span>
                            </label>
                            {editing && (
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', !!v)} />
                                    Active
                                </label>
                            )}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={processing}>Save</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
