import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Settings2, Plus, Pencil } from 'lucide-react';

const STATUS_VARIANT = {
    active: 'success',
    trial: 'default',
    suspended: 'destructive',
    expired: 'secondary',
};

// Mirrors the server-side regex in Store/UpdateTenantRequest -- lowercase
// letters, numbers, and hyphens only. Purely a typing convenience (the
// server is still the source of truth for validation); a Master can
// still hand-edit the slug field freely, this just seeds a sensible
// default from the name so a fresh "Add Tenant" isn't left blank.
function slugify(value) {
    return value
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

export default function PlatformTenants({ tenants, packages }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [slugTouched, setSlugTouched] = useState(false);

    const { data, setData, post, put, processing, reset, errors, clearErrors } = useForm({
        name: '',
        slug: '',
        package_id: '',
        status: 'trial',
        admin_name: '',
        admin_email: '',
        admin_password: '',
        admin_password_confirmation: '',
    });

    function changeStatus(tenant, status) {
        router.put(route('platform.tenants.update-status', tenant.id), { status }, { preserveScroll: true });
    }

    function openCreate() {
        setEditing(null);
        setSlugTouched(false);
        clearErrors();
        reset();
        setOpen(true);
    }

    function openEdit(tenant) {
        setEditing(tenant);
        setSlugTouched(true); // editing an existing slug should never be silently overwritten by the name field
        clearErrors();
        setData({
            name: tenant.name,
            slug: tenant.slug,
            package_id: tenant.subscription?.package_id ? String(tenant.subscription.package_id) : '',
            status: tenant.status,
            admin_name: '',
            admin_email: '',
            admin_password: '',
            admin_password_confirmation: '',
        });
        setOpen(true);
    }

    function onNameChange(value) {
        setData((prev) => ({
            ...prev,
            name: value,
            slug: slugTouched ? prev.slug : slugify(value),
        }));
    }

    function submit(e) {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) {
            put(route('platform.tenants.update', editing.id), options);
        } else {
            post(route('platform.tenants.store'), options);
        }
    }

    return (
        <PlatformLayout>
            <Head title="Tenants" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900">Tenants</h1>
                    <p className="mt-1 text-sm text-graphite-500">
                        Every paying customer organization on this platform. Suspending a tenant is the coarse
                        platform-level kill switch -- it does not delete any of that tenant's data.
                    </p>
                </div>
                <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Tenant</Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>All Tenants</CardTitle>
                    <CardDescription>{tenants?.length ?? 0} total -- click a tenant's name to view its details</CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Slug</TableHead>
                                    <TableHead>Companies</TableHead>
                                    <TableHead>Users</TableHead>
                                    <TableHead>Package</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {(tenants ?? []).map((tenant) => (
                                    <TableRow key={tenant.id}>
                                        <TableCell className="font-medium">
                                            <Link
                                                href={route('platform.tenants.show', tenant.id)}
                                                className="text-graphite-800 underline-offset-2 hover:text-brand-600 hover:underline"
                                            >
                                                {tenant.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-graphite-500">{tenant.slug}</TableCell>
                                        <TableCell>{tenant.companies_count}</TableCell>
                                        <TableCell>{tenant.users_count}</TableCell>
                                        <TableCell>{tenant.subscription?.package?.name ?? '—'}</TableCell>
                                        <TableCell>
                                            <Select value={tenant.status} onValueChange={(value) => changeStatus(tenant, value)}>
                                                <SelectTrigger className="w-32">
                                                    <SelectValue>
                                                        <Badge variant={STATUS_VARIANT[tenant.status] ?? 'secondary'}>{tenant.status}</Badge>
                                                    </SelectValue>
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="trial">Trial</SelectItem>
                                                    <SelectItem value="active">Active</SelectItem>
                                                    <SelectItem value="suspended">Suspended</SelectItem>
                                                    <SelectItem value="expired">Expired</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </TableCell>
                                        <TableCell className="flex gap-1">
                                            <Button variant="outline" size="sm" onClick={() => openEdit(tenant)}>
                                                <Pencil className="h-3.5 w-3.5" /> Edit
                                            </Button>
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={route('platform.tenants.grants', tenant.id)}>
                                                    <Settings2 className="h-3.5 w-3.5" /> Grants
                                                </Link>
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-xl">
                    <DialogHeader><DialogTitle>{editing ? 'Edit Tenant' : 'Add Tenant'}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="max-h-[70vh] space-y-5 overflow-y-auto pr-1">
                        <div className="space-y-4">
                            <div className="space-y-1.5">
                                <Label>Tenant Name</Label>
                                <Input
                                    value={data.name}
                                    onChange={(e) => onNameChange(e.target.value)}
                                    placeholder="e.g. PT Galangan Aliran Jaya"
                                />
                                {errors.name && <p className="text-xs text-red-600">{errors.name}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Slug</Label>
                                <Input
                                    value={data.slug}
                                    onChange={(e) => { setSlugTouched(true); setData('slug', e.target.value); }}
                                    placeholder="e.g. pt-galangan-aliran-jaya"
                                />
                                {errors.slug && <p className="text-xs text-red-600">{errors.slug}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Package</Label>
                                <Select value={data.package_id ? String(data.package_id) : ''} onValueChange={(v) => setData('package_id', v)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a package" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(packages ?? []).map((pkg) => (
                                            <SelectItem key={pkg.id} value={String(pkg.id)}>{pkg.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.package_id && <p className="text-xs text-red-600">{errors.package_id}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Status</Label>
                                <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="trial">Trial</SelectItem>
                                        <SelectItem value="active">Active</SelectItem>
                                        <SelectItem value="suspended">Suspended</SelectItem>
                                        <SelectItem value="expired">Expired</SelectItem>
                                    </SelectContent>
                                </Select>
                                {errors.status && <p className="text-xs text-red-600">{errors.status}</p>}
                            </div>
                        </div>

                        {/* Initial Administrator -- only on create. A tenant is created together
                            with its first login; editing a tenant later never touches this
                            account (use the tenant's own Settings > Users once logged in). */}
                        {!editing && (
                            <div className="space-y-4 border-t border-graphite-200 pt-4 dark:border-slate-700">
                                <div>
                                    <h3 className="text-sm font-semibold text-graphite-800 dark:text-slate-100">Initial Administrator</h3>
                                    <p className="text-xs text-graphite-500">This tenant's first login -- created with the Super Admin role.</p>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Full Name</Label>
                                    <Input
                                        value={data.admin_name}
                                        onChange={(e) => setData('admin_name', e.target.value)}
                                        placeholder="e.g. John Doe"
                                    />
                                    {errors.admin_name && <p className="text-xs text-red-600">{errors.admin_name}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Email</Label>
                                    <Input
                                        type="email"
                                        value={data.admin_email}
                                        onChange={(e) => setData('admin_email', e.target.value)}
                                        placeholder="e.g. admin@galanganaliranjaya.co.id"
                                    />
                                    {errors.admin_email && <p className="text-xs text-red-600">{errors.admin_email}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Password</Label>
                                    <Input
                                        type="password"
                                        value={data.admin_password}
                                        onChange={(e) => setData('admin_password', e.target.value)}
                                        placeholder="At least 8 characters"
                                    />
                                    {errors.admin_password && <p className="text-xs text-red-600">{errors.admin_password}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Confirm Password</Label>
                                    <Input
                                        type="password"
                                        value={data.admin_password_confirmation}
                                        onChange={(e) => setData('admin_password_confirmation', e.target.value)}
                                    />
                                </div>
                            </div>
                        )}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={processing}>Save</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </PlatformLayout>
    );
}
