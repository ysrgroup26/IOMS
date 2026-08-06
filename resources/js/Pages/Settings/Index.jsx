import { Head, useForm, router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import * as Tabs from '@radix-ui/react-tabs';
import ImageUploadField from '@/Components/shared/ImageUploadField';
import { AVAILABLE_ICON_NAMES } from '@/lib/iconMap';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogTrigger } from '@/Components/ui/dialog';
import { Plus, Trash2, Pencil, Download, Upload, Loader2, Lock } from 'lucide-react';
import { WORKSPACES } from '@/lib/workspaces';

const TAB_CLASS = 'rounded-md px-4 py-1.5 text-sm font-medium text-graphite-500 data-[state=active]:bg-brand-50 data-[state=active]:text-brand-700';

const ROLE_LABELS = {
    super_admin: 'Super Admin',
    hse: 'HSE',
    hrd: 'HRD',
    manager: 'Manager',
    warehouse: 'Warehouse',
};

export default function SettingsIndex({ company, companies, departments, positions, kpiCategories, users, can, filters, roles, permissionCatalog }) {
    // System-level tabs (Companies, Users, Backup) are Super-Admin-only.
    // HSE sees only the operational tabs (Departments, Positions).
    const canSystem = can?.manage_system;

    return (
        <AuthenticatedLayout>
            <Head title="Settings" />

            <div className="mb-6">
                <h1 className="text-lg font-bold tracking-tight text-graphite-900">Settings</h1>
                <p className="mt-1 text-sm text-graphite-500">Company info, master data, users, and backups.</p>
            </div>

            <Tabs.Root
                defaultValue={new URLSearchParams(window.location.search).get('tab') || (canSystem ? 'branding' : 'departments')}
                className="space-y-4"
            >
                <Tabs.List className="inline-flex flex-wrap rounded-lg border border-graphite-200 bg-white p-1 shadow-sm">
                    {canSystem && <Tabs.Trigger value="branding" className={TAB_CLASS}>Branding</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="modules" className={TAB_CLASS}>Module Visibility</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="companies" className={TAB_CLASS}>Companies</Tabs.Trigger>}
                    <Tabs.Trigger value="departments" className={TAB_CLASS}>Departments</Tabs.Trigger>
                    <Tabs.Trigger value="positions" className={TAB_CLASS}>Positions</Tabs.Trigger>
                    <Tabs.Trigger value="kpi-categories" className={TAB_CLASS}>KPI Categories</Tabs.Trigger>
                    <Tabs.Trigger value="authentication" className={TAB_CLASS}>Authentication</Tabs.Trigger>
                    {canSystem && <Tabs.Trigger value="users" className={TAB_CLASS}>Users</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="roles" className={TAB_CLASS}>Roles &amp; Permissions</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="backup" className={TAB_CLASS}>Backup &amp; Restore</Tabs.Trigger>}
                </Tabs.List>

                {canSystem && <Tabs.Content value="branding"><BrandingTab company={company} /></Tabs.Content>}
                {canSystem && (
                    <Tabs.Content value="modules" className="space-y-4">
                        <WorkspaceLabelsCard />
                        <ModulesTab />
                    </Tabs.Content>
                )}
                {canSystem && <Tabs.Content value="companies"><CompaniesTab companies={companies} /></Tabs.Content>}
                <Tabs.Content value="departments"><DepartmentsTab departments={departments} companies={companies} filters={filters} /></Tabs.Content>
                <Tabs.Content value="positions"><PositionsTab positions={positions} departments={departments} companies={companies} filters={filters} /></Tabs.Content>
                <Tabs.Content value="kpi-categories"><KpiCategoriesTab kpiCategories={kpiCategories} companies={companies} /></Tabs.Content>
                <Tabs.Content value="authentication"><AuthenticationTab /></Tabs.Content>
                {canSystem && <Tabs.Content value="users"><UsersTab users={users} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="roles"><RolesTab roles={roles} permissionCatalog={permissionCatalog} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="backup"><BackupTab /></Tabs.Content>}
            </Tabs.Root>
        </AuthenticatedLayout>
    );
}

function BrandingTab({ company }) {
    const { data, setData, post, processing } = useForm({
        company_name: company.name,
        company_subtitle: company.subtitle || '',
        company_short_name: company.short_name || '',
        footer_copyright: company.footer_copyright || '',
        logo: null,
        favicon: null,
    });

    function submit(e) {
        e.preventDefault();
        post(route('settings.company'), { forceFormData: true });
    }

    return (
        <Card className="max-w-lg">
            <CardHeader><CardTitle>Application Branding</CardTitle><CardDescription>Name, subtitle, logo, and favicon shown across the app.</CardDescription></CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label>Application Name</Label>
                        <Input value={data.company_name} onChange={(e) => setData('company_name', e.target.value)} />
                    </div>
                    <div className="space-y-1.5">
                        <Label>Subtitle</Label>
                        <Input value={data.company_subtitle} onChange={(e) => setData('company_subtitle', e.target.value)} placeholder="Industrial Operations Platform" />
                    </div>
                    <div className="space-y-1.5">
                        <Label>Company Short Name (optional)</Label>
                        <Input value={data.company_short_name} onChange={(e) => setData('company_short_name', e.target.value)} placeholder="e.g. GAJ" maxLength={50} />
                    </div>
                    <div className="space-y-1.5">
                        <Label>Footer Copyright Text (optional)</Label>
                        <Input value={data.footer_copyright} onChange={(e) => setData('footer_copyright', e.target.value)} placeholder="Leave blank to use the default" />
                    </div>
                    <ImageUploadField
                        label="Logo (SVG or PNG)"
                        existingUrl={company.logo_url}
                        file={data.logo}
                        onChange={(file) => setData('logo', file)}
                        accept="image/png,image/jpeg,image/jpg,image/webp,image/svg+xml"
                        shape="square"
                    />
                    <ImageUploadField
                        label="Favicon (optional)"
                        existingUrl={company.favicon_url}
                        file={data.favicon}
                        onChange={(file) => setData('favicon', file)}
                        accept="image/png,image/x-icon,image/svg+xml"
                        shape="square"
                    />
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="h-4 w-4 animate-spin" />} Save Changes
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

// Each toggleable module belongs to exactly one workspace (see
// resources/js/lib/workspaces.js) -- built once, keyed by moduleKey, so
// ModulesTab can group its checkboxes under the same workspace headers
// the sidebar switcher uses, without duplicating the workspace->module
// mapping in two places.
const MODULE_WORKSPACE = WORKSPACES.reduce((map, workspace) => {
    for (const item of workspace.items) {
        if (item.moduleKey) map[item.moduleKey] = workspace;
    }
    return map;
}, {});

/**
 * Toggles which sidebar modules are visible app-wide (v1.5.0, grouped by
 * workspace in v1.7.0). Backed by config/modules.php (the registry of
 * modules that actually exist) and the `enabled_modules` CompanySetting.
 * Core modules (Home, Dashboard, Settings) aren't listed here -- they're
 * never toggleable. This is a navigation-visibility toggle, not a hard
 * access-control boundary: it changes what appears in the sidebar for
 * everyone, but existing routes for a disabled module are intentionally
 * left reachable by direct URL (matching how other nav-driven visibility
 * already works elsewhere in the app), so nothing existing can be
 * accidentally locked out.
 *
 * Grouping by workspace here is display-only -- turning off every module
 * under a workspace (e.g. all of HSE) is exactly how an admin "disables a
 * workspace": the workspace switcher already hides any workspace left
 * with zero visible items, so no separate workspace-level toggle or
 * CompanySetting is needed.
 */
function humanizePermissionGroup(prefix) {
    return prefix.split('_').map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
}

/**
 * Milestone 2 (RBAC UI, Task #45). Edit which permissions each
 * tenant-side Role carries -- `spatie/laravel-permission` roles seeded
 * by RolePermissionSeeder with a sensible default set per role. This is
 * genuinely functional (writes to the `role_has_permissions` pivot,
 * `->hasRole()`/`->can()` reflect it immediately), but no existing
 * controller checks permissions yet -- every controller still runs on
 * the `role` column + isX()/canX() methods (see
 * docs/ADR/008-tenancy-foundation.md). Said plainly in the UI itself so
 * an admin editing this doesn't assume it already changes what a role
 * can do in the app today.
 */
function RolesTab({ roles, permissionCatalog }) {
    const [activeRoleId, setActiveRoleId] = useState(roles?.[0]?.id);
    const activeRole = (roles ?? []).find((r) => r.id === activeRoleId);

    const { data, setData, put, processing } = useForm({ permissions: activeRole?.permissions ?? [] });

    useEffect(() => {
        setData('permissions', (roles ?? []).find((r) => r.id === activeRoleId)?.permissions ?? []);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activeRoleId]);

    function toggle(permission) {
        setData('permissions', data.permissions.includes(permission)
            ? data.permissions.filter((p) => p !== permission)
            : [...data.permissions, permission]);
    }

    function submit(e) {
        e.preventDefault();
        put(route('settings.roles.update', activeRoleId));
    }

    const groups = (permissionCatalog ?? []).reduce((acc, permission) => {
        const prefix = permission.split('.')[0];
        (acc[prefix] ??= []).push(permission);
        return acc;
    }, {});

    return (
        <Card className="max-w-2xl">
            <CardHeader>
                <CardTitle>Roles &amp; Permissions</CardTitle>
                <CardDescription>
                    Edit which permissions each role carries. <strong>Note:</strong> this updates the
                    permission records themselves, but no page in the app checks them yet -- every page
                    still uses each role's built-in capabilities. Changes here take effect once that
                    migration happens.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="flex flex-wrap gap-2">
                    {(roles ?? []).map((role) => (
                        <button
                            key={role.id}
                            type="button"
                            onClick={() => setActiveRoleId(role.id)}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium ${role.id === activeRoleId ? 'bg-brand-50 text-brand-700' : 'bg-graphite-100 text-graphite-600 hover:bg-graphite-200'}`}
                        >
                            {ROLE_LABELS[role.name] ?? role.name}
                        </button>
                    ))}
                </div>

                <form onSubmit={submit} className="space-y-5">
                    {Object.entries(groups).map(([prefix, permissions]) => (
                        <div key={prefix} className="space-y-2">
                            <div className="text-xs font-semibold uppercase tracking-wide text-graphite-400">
                                {humanizePermissionGroup(prefix)}
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                {permissions.map((permission) => (
                                    <label key={permission} className="flex items-center justify-between rounded-lg border border-graphite-100 px-3 py-2">
                                        <span className="text-sm text-graphite-700">{permission.split('.').slice(1).join('.')}</span>
                                        <Checkbox checked={data.permissions.includes(permission)} onCheckedChange={() => toggle(permission)} />
                                    </label>
                                ))}
                            </div>
                        </div>
                    ))}
                    <Button type="submit" disabled={processing || !activeRoleId}>
                        {processing && <Loader2 className="h-4 w-4 animate-spin" />} Save Permissions
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

/**
 * Milestone 2 (Dynamic Workspace system, Task #43). Rename/reorder/
 * enable-disable existing department & global navigation entries without
 * a code deploy -- writes to the `workspaces` DB table, which
 * `resources/js/lib/workspaces.js`'s `applyCatalog()` merges onto the
 * hardcoded WORKSPACES array's label/icon/order everywhere the sidebar
 * and Department Selector read it. Same "labeling only" boundary as
 * ModulesTab below: this can't add a new working department, only
 * rename/reorder/hide ones that already have real pages built for them.
 */
function WorkspaceLabelsCard() {
    const { workspace_catalog: catalog } = usePage().props;

    const rows = WORKSPACES.map((workspace, index) => {
        const override = catalog?.[workspace.key];
        return {
            key: workspace.key,
            defaultLabel: workspace.label,
            label: override?.label ?? workspace.label,
            sort_order: override?.sort_order ?? index + 1,
            is_active: override?.is_active ?? true,
        };
    });

    const { data, setData, post, processing } = useForm({ workspaces: rows });

    function updateRow(key, patch) {
        setData('workspaces', data.workspaces.map((row) => (row.key === key ? { ...row, ...patch } : row)));
    }

    function submit(e) {
        e.preventDefault();
        post(route('settings.workspaces'));
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Department Navigation</CardTitle>
                <CardDescription>
                    Rename, reorder, or hide existing departments in the sidebar switcher. This controls{' '}
                    <strong>labels and order only</strong> -- it does not create a new working department;
                    each row here corresponds to a department that already exists in the app.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-3">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Label</TableHead>
                                    <TableHead className="w-24">Order</TableHead>
                                    <TableHead className="w-20">Active</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.workspaces.map((row) => (
                                    <TableRow key={row.key}>
                                        <TableCell>
                                            <Input
                                                value={row.label}
                                                placeholder={row.defaultLabel}
                                                onChange={(e) => updateRow(row.key, { label: e.target.value })}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                type="number"
                                                min={0}
                                                value={row.sort_order}
                                                onChange={(e) => updateRow(row.key, { sort_order: Number(e.target.value) })}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Checkbox
                                                checked={row.is_active}
                                                onCheckedChange={(checked) => updateRow(row.key, { is_active: !!checked })}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="h-4 w-4 animate-spin" />} Save Departments
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function ModulesTab() {
    const { modules } = usePage().props;
    const { data, setData, post, processing } = useForm({
        enabled_modules: modules?.enabled ?? [],
    });

    function toggle(key) {
        setData('enabled_modules', data.enabled_modules.includes(key)
            ? data.enabled_modules.filter((k) => k !== key)
            : [...data.enabled_modules, key]);
    }

    function submit(e) {
        e.preventDefault();
        post(route('settings.modules'));
    }

    const availableEntries = Object.entries(modules?.available ?? {});
    const groups = WORKSPACES
        .map((workspace) => ({
            workspace,
            entries: availableEntries.filter(([key]) => MODULE_WORKSPACE[key]?.key === workspace.key),
        }))
        .filter((group) => group.entries.length > 0);
    // Any module not (yet) mapped to a workspace still renders, ungrouped, at the end --
    // safer than silently hiding a real toggle if the two lists ever drift apart.
    const ungrouped = availableEntries.filter(([key]) => !MODULE_WORKSPACE[key]);

    return (
        <Card className="max-w-lg">
            <CardHeader>
                <CardTitle>Module Visibility</CardTitle>
                <CardDescription>
                    Show or hide existing modules in the sidebar, app-wide, grouped by workspace. Home,
                    Dashboard, and Settings are always visible and can't be hidden here. Turning off every
                    module in a workspace hides that whole workspace from the switcher. This controls{' '}
                    <strong>visibility only</strong> -- it does not create new modules. Building an entirely
                    new module (e.g. Asset Management, Permit to Work) still requires development work; once
                    built, it would appear in this list like any other module.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-5">
                    {groups.map(({ workspace, entries }) => (
                        <div key={workspace.key} className="space-y-2">
                            <div className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-graphite-400">
                                <workspace.icon className="h-3.5 w-3.5" />
                                {workspace.label}
                            </div>
                            <div className="space-y-2">
                                {entries.map(([key, label]) => (
                                    <label key={key} className="flex items-center justify-between rounded-lg border border-graphite-100 px-3 py-2.5">
                                        <span className="text-sm font-medium text-graphite-700">{label}</span>
                                        <Checkbox checked={data.enabled_modules.includes(key)} onCheckedChange={() => toggle(key)} />
                                    </label>
                                ))}
                            </div>
                        </div>
                    ))}
                    {ungrouped.length > 0 && (
                        <div className="space-y-2">
                            {ungrouped.map(([key, label]) => (
                                <label key={key} className="flex items-center justify-between rounded-lg border border-graphite-100 px-3 py-2.5">
                                    <span className="text-sm font-medium text-graphite-700">{label}</span>
                                    <Checkbox checked={data.enabled_modules.includes(key)} onCheckedChange={() => toggle(key)} />
                                </label>
                            ))}
                        </div>
                    )}
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="h-4 w-4 animate-spin" />} Save Modules
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function CompaniesTab({ companies }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, reset, errors } = useForm({ name: '', code: '' });

    function submit(e) {
        e.preventDefault();
        post(route('settings.companies.store'), { onSuccess: () => { reset(); setOpen(false); } });
    }

    function destroy(id) {
        if (confirm('Remove this company?')) router.delete(route('settings.companies.destroy', id));
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <div><CardTitle>Companies</CardTitle><CardDescription>Business entities (e.g. GAJ, Maintenance).</CardDescription></div>
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogTrigger asChild><Button size="sm"><Plus className="h-4 w-4" /> Add</Button></DialogTrigger>
                    <DialogContent>
                        <DialogHeader><DialogTitle>Add Company</DialogTitle></DialogHeader>
                        <form onSubmit={submit} className="space-y-3">
                            <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} />
                                {errors.name && <p className="text-xs text-red-600">{errors.name}</p>}
                            </div>
                            <div className="space-y-1.5"><Label>Code</Label><Input value={data.code} onChange={(e) => setData('code', e.target.value)} /></div>
                            <DialogFooter><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Code</TableHead><TableHead>Departments</TableHead><TableHead>Employees</TableHead><TableHead /></TableRow></TableHeader>
                    <TableBody>
                        {companies.map((c) => (
                            <TableRow key={c.id}>
                                <TableCell className="font-medium">{c.name}</TableCell>
                                <TableCell>{c.code ?? '—'}</TableCell>
                                <TableCell>{c.departments_count}</TableCell>
                                <TableCell>{c.employees_count}</TableCell>
                                <TableCell>
                                    <Button variant="ghost" size="icon" onClick={() => destroy(c.id)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

function DepartmentsTab({ departments, companies, filters }) {
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
        if (confirm('Remove this department?')) router.delete(route('settings.departments.destroy', id));
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
                    <Label className="text-xs text-graphite-500">Company</Label>
                    <Select value={filters?.company_id ? String(filters.company_id) : "all"} onValueChange={filterByCompany}>
                        <SelectTrigger className="w-48"><SelectValue placeholder="All Companies" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Companies</SelectItem>
                            {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </div>
                <Table>
                    <TableHeader><TableRow><TableHead>Order</TableHead><TableHead>Name</TableHead><TableHead>Company</TableHead><TableHead>Code</TableHead><TableHead>Employees</TableHead><TableHead /></TableRow></TableHeader>
                    <TableBody>
                        {departments.map((d) => (
                            <TableRow key={d.id}>
                                <TableCell className="text-graphite-400">{d.sort_order}</TableCell>
                                <TableCell className="font-medium">{d.name}</TableCell>
                                <TableCell>{d.company?.name ?? '—'}</TableCell>
                                <TableCell>{d.code ?? '—'}</TableCell>
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
                    <form onSubmit={submit} className="space-y-3">
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select company" /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>
                        <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            {errors.name && <p className="text-xs text-red-600">{errors.name}</p>}
                        </div>
                        <div className="space-y-1.5"><Label>Code</Label><Input value={data.code} onChange={(e) => setData('code', e.target.value)} /></div>
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

function PositionsTab({ positions, departments, companies, filters }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset } = useForm({ name: '', description: '', company_id: undefined, department_id: '', sort_order: 0 });

    function filterByCompany(companyId) {
        router.get(route('settings.index'), { ...filters, company_id: companyId === 'all' ? null : companyId, tab: 'positions' }, { preserveState: true, preserveScroll: true, replace: true });
    }

    const availableDepartments = data.company_id ? departments.filter((d) => d.company_id === Number(data.company_id)) : departments;

    function openCreate() {
        setEditing(null);
        reset();
        setOpen(true);
    }

    function openEdit(position) {
        setEditing(position);
        setData({ name: position.name, description: position.description ?? '', company_id: String(position.company_id), department_id: position.department_id ? String(position.department_id) : '', sort_order: position.sort_order ?? 0 });
        setOpen(true);
    }

    function submit(e) {
        e.preventDefault();
        const options = { onSuccess: () => { reset(); setOpen(false); } };
        if (editing) {
            put(route('settings.positions.update', editing.id), options);
        } else {
            post(route('settings.positions.store'), options);
        }
    }

    function destroy(id) {
        if (confirm('Remove this position?')) router.delete(route('settings.positions.destroy', id));
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <div>
                    <CardTitle>Positions</CardTitle>
                    <CardDescription>Manage job positions per department. Display Order controls seniority ordering throughout the app.</CardDescription>
                </div>
                <Button size="sm" onClick={openCreate}><Plus className="h-4 w-4" /> Add</Button>
            </CardHeader>
            <CardContent>
                <div className="mb-3 flex items-center gap-2">
                    <Label className="text-xs text-graphite-500">Company</Label>
                    <Select value={filters?.company_id ? String(filters.company_id) : 'all'} onValueChange={filterByCompany}>
                        <SelectTrigger className="w-48"><SelectValue placeholder="All Companies" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Companies</SelectItem>
                            {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </div>
                <Table>
                    <TableHeader><TableRow><TableHead>Order</TableHead><TableHead>Name</TableHead><TableHead>Department</TableHead><TableHead /></TableRow></TableHeader>
                    <TableBody>
                        {positions.map((p) => (
                            <TableRow key={p.id}>
                                <TableCell className="text-graphite-400">{p.sort_order}</TableCell>
                                <TableCell className="font-medium">{p.name}</TableCell>
                                <TableCell>{p.department?.name ?? '—'}</TableCell>
                                <TableCell className="flex gap-1">
                                    <Button variant="ghost" size="icon" onClick={() => openEdit(p)}><Pencil className="h-4 w-4" /></Button>
                                    <Button variant="ghost" size="icon" onClick={() => destroy(p.id)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit Position' : 'Add Position'}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-3">
                        <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} /></div>
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData((d) => ({ ...d, company_id: v, department_id: '' }))}>
                                <SelectTrigger><SelectValue placeholder="Select company" /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Department (optional)</Label>
                            <Select value={data.department_id} onValueChange={(v) => setData('department_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select department" /></SelectTrigger>
                                <SelectContent>{availableDepartments.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5"><Label>Description (optional)</Label><Input value={data.description} onChange={(e) => setData('description', e.target.value)} /></div>
                        <div className="space-y-1.5">
                            <Label>Display Order</Label>
                            <Input type="number" min="0" value={data.sort_order} onChange={(e) => setData('sort_order', e.target.value)} />
                            <p className="text-xs text-graphite-400">Lower numbers appear first (e.g. General Manager before Staff).</p>
                        </div>
                        <DialogFooter><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

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
function KpiCategoriesTab({ kpiCategories, companies }) {
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

    function destroy(id) {
        if (confirm('Remove this KPI category? Only possible if no KPI data has been recorded against it.')) {
            router.delete(route('settings.kpi-categories.destroy', id));
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
                        immediately, with no code changes. Global categories apply to every company; assign a
                        Company to scope one to just that company.
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
                                <TableCell>{cat.show_on_dashboard ? 'Visible' : '—'}</TableCell>
                                <TableCell>{cat.supports_quick_attendance ? 'Yes' : '—'}</TableCell>
                                <TableCell><Badge variant={cat.is_active ? 'success' : 'secondary'}>{cat.is_active ? 'Active' : 'Inactive'}</Badge></TableCell>
                                <TableCell className="flex gap-1">
                                    <Button variant="ghost" size="icon" onClick={() => openEdit(cat)}><Pencil className="h-4 w-4" /></Button>
                                    <Button variant="ghost" size="icon" onClick={() => destroy(cat.id)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
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

                        <div className="space-y-2 rounded-lg border border-graphite-100 p-3">
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

function AuthenticationTab() {
    const { auth } = usePage().props;
    const { data, setData, post, processing, reset, errors } = useForm({
        current_password: '',
        email: auth?.user?.email || '',
        password: '',
        password_confirmation: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('settings.authentication'), {
            onSuccess: () => reset('current_password', 'password', 'password_confirmation'),
        });
    }

    return (
        <Card className="max-w-lg">
            <CardHeader>
                <CardTitle className="flex items-center gap-2"><Lock className="h-4 w-4" /> Authentication</CardTitle>
                <CardDescription>Change your own login email and password. Future-ready for email verification and 2FA.</CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label>Current Password</Label>
                        <Input type="password" value={data.current_password} onChange={(e) => setData('current_password', e.target.value)} />
                        {errors.current_password && <p className="text-xs text-red-600">{errors.current_password}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label>Username (Email)</Label>
                        <Input type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        {errors.email && <p className="text-xs text-red-600">{errors.email}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label>New Password (optional)</Label>
                        <Input type="password" value={data.password} onChange={(e) => setData('password', e.target.value)} placeholder="Leave blank to keep your current password" />
                        {errors.password && <p className="text-xs text-red-600">{errors.password}</p>}
                    </div>
                    {data.password && (
                        <div className="space-y-1.5">
                            <Label>Confirm New Password</Label>
                            <Input type="password" value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} />
                        </div>
                    )}
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="h-4 w-4 animate-spin" />} Update Credentials
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function UsersTab({ users }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, reset, errors } = useForm({ name: '', email: '', password: '', role: 'hrd' });

    function submit(e) {
        e.preventDefault();
        post(route('settings.users.store'), { onSuccess: () => { reset(); setOpen(false); } });
    }

    function destroy(id) {
        if (confirm('Remove this user?')) router.delete(route('settings.users.destroy', id));
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <div><CardTitle>User Management</CardTitle><CardDescription>Super Admin, HSE, HRD, or Manager.</CardDescription></div>
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogTrigger asChild><Button size="sm"><Plus className="h-4 w-4" /> Add User</Button></DialogTrigger>
                    <DialogContent>
                        <DialogHeader><DialogTitle>Create User</DialogTitle></DialogHeader>
                        <form onSubmit={submit} className="space-y-3">
                            <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Email</Label><Input type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                                {errors.email && <p className="text-xs text-red-600">{errors.email}</p>}
                            </div>
                            <div className="space-y-1.5"><Label>Password</Label><Input type="password" value={data.password} onChange={(e) => setData('password', e.target.value)} />
                                {errors.password && <p className="text-xs text-red-600">{errors.password}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Role</Label>
                                <Select value={data.role} onValueChange={(v) => setData('role', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="super_admin">Super Admin</SelectItem>
                                        <SelectItem value="hse">HSE</SelectItem>
                                        <SelectItem value="hrd">HRD</SelectItem>
                                        <SelectItem value="manager">Manager</SelectItem>
                                        <SelectItem value="warehouse">Warehouse</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <DialogFooter><Button type="submit" disabled={processing}>Create</Button></DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Email</TableHead><TableHead>Role</TableHead><TableHead>Status</TableHead><TableHead /></TableRow></TableHeader>
                    <TableBody>
                        {users.map((u) => (
                            <TableRow key={u.id}>
                                <TableCell className="font-medium">{u.name}</TableCell>
                                <TableCell>{u.email}</TableCell>
                                <TableCell><Badge variant={u.role === 'super_admin' ? 'default' : 'secondary'}>{ROLE_LABELS[u.role] ?? u.role}</Badge></TableCell>
                                <TableCell><Badge variant={u.is_active ? 'success' : 'secondary'}>{u.is_active ? 'Active' : 'Inactive'}</Badge></TableCell>
                                <TableCell>
                                    <Button variant="ghost" size="icon" onClick={() => destroy(u.id)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

function BackupTab() {
    const { data, setData, post, processing } = useForm({ backup_file: null });

    function restore(e) {
        e.preventDefault();
        if (!confirm('This will overwrite the current database. Continue?')) return;
        post(route('settings.restore'), { forceFormData: true });
    }

    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Card>
                <CardHeader><CardTitle>Backup Database</CardTitle><CardDescription>Download a full SQL dump of the database.</CardDescription></CardHeader>
                <CardContent>
                    <Button asChild><a href={route('settings.backup')}><Download className="h-4 w-4" /> Download Backup</a></Button>
                </CardContent>
            </Card>
            <Card>
                <CardHeader><CardTitle>Restore Database</CardTitle><CardDescription>Upload a .sql backup file. This will overwrite current data.</CardDescription></CardHeader>
                <CardContent>
                    <form onSubmit={restore} className="space-y-3">
                        <Input type="file" accept=".sql" onChange={(e) => setData('backup_file', e.target.files[0])} />
                        <Button type="submit" variant="destructive" disabled={processing || !data.backup_file}>
                            {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                            <Upload className="h-4 w-4" /> Restore
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </div>
    );
}
