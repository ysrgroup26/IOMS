import { Head, Link, useForm, router, usePage } from '@inertiajs/react';
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
import { Plus, Trash2, Pencil, Download, Upload, Loader2, Lock, Search } from 'lucide-react';
import { WORKSPACES } from '@/lib/workspaces';

// v2.31.0: added transition-colors so the active-tab tinted surface
// change reads as a smooth state change, not an instant snap -- the
// same micro-interaction convention this pass introduces on ModuleTabNav
// and Table row hover.
const TAB_CLASS = 'rounded-md px-4 py-1.5 text-sm font-medium text-graphite-500 transition-colors duration-150 hover:text-graphite-800 data-[state=active]:bg-brand-50 data-[state=active]:text-brand-700';

// v2.31.0 (Interior UI Transformation Phase 3, Part 9): a plain vertical
// rule between Settings' tab clusters -- purely visual grouping, not a
// Tabs.Trigger, so it carries no RBAC/routing behavior of its own.
function TabDivider() {
    return <span className="mx-1 hidden h-5 w-px shrink-0 bg-graphite-200 sm:block" aria-hidden="true" />;
}

// Milestone 3 (UAT #1/#3/#7 -- identity clarity): matches
// User::roleLabel()'s own mapping exactly -- see that method's doc
// comment for why "super_admin" reads "Administrator" here, not
// "Super Admin". Only the label changed; the stored role value is
// unchanged.
const ROLE_LABELS = {
    super_admin: 'Administrator',
    hse: 'HSE',
    hrd: 'HRD',
    manager: 'Manager',
    warehouse: 'Warehouse',
};

export default function SettingsIndex({ company, companies, departments, positions, kpiCategories, users, can, filters, roles, permissionCatalog, numberingFormats, approvalFlows, numberingModuleKeys, notificationPreferences, documentTemplates, documentModuleKeys, fieldMappings, subscription, invoices, ptwAccess }) {
    // System-level tabs (Companies, Users, Backup) are Super-Admin-only.
    // HSE sees only the operational tabs (Departments, Positions).
    const canSystem = can?.manage_system;
    // v2.19.0 (PTW Access Management Correction pass, Part 1/2): the
    // Users tab now also opens for HSE -- but ONLY to reach the Field &
    // PTW Access section inside it; UsersTab itself further restricts
    // the actual User Management table (create/edit/delete/role changes)
    // to canSystem, so this alone never grants HSE anything beyond PTW
    // Access toggling. Same server-side canManageHse() gate the route/
    // controller already enforce (see routes/web.php's own comment).
    const canPtwAccess = can?.manage_ptw_access;

    return (
        <AuthenticatedLayout>
            <Head title="Settings" />

            <div className="mb-6">
                <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900">Settings</h1>
                <p className="mt-1 text-sm text-graphite-500">Company info, master data, users, and backups.</p>
            </div>

            <Tabs.Root
                defaultValue={new URLSearchParams(window.location.search).get('tab') || (canSystem ? 'branding' : 'departments')}
                className="space-y-4"
            >
                {/* v2.31.0 (Interior UI Transformation Phase 3, Part 9):
                    16 tabs in one undifferentiated row read as a "giant
                    CRUD wall" -- exactly the problem this pass names.
                    Grouped into 5 logical clusters (Company, Master Data,
                    People & Access, Workflow & Documents, System) with a
                    visible divider between them -- same Tabs.Trigger
                    elements, same values, same conditional RBAC gates,
                    same tab bodies below; this changes only how the list
                    reads, not which tab does what or who can see it. */}
                <Tabs.List className="inline-flex flex-wrap items-center gap-x-1 gap-y-1 rounded-lg border border-graphite-200 bg-white p-1 shadow-sm">
                    {canSystem && <Tabs.Trigger value="branding" className={TAB_CLASS}>Branding</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="modules" className={TAB_CLASS}>Module Visibility</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="companies" className={TAB_CLASS}>Companies</Tabs.Trigger>}
                    {canSystem && <TabDivider />}

                    <Tabs.Trigger value="departments" className={TAB_CLASS}>Departments</Tabs.Trigger>
                    <Tabs.Trigger value="positions" className={TAB_CLASS}>Positions</Tabs.Trigger>
                    <Tabs.Trigger value="kpi-categories" className={TAB_CLASS}>KPI Categories</Tabs.Trigger>
                    <TabDivider />

                    <Tabs.Trigger value="authentication" className={TAB_CLASS}>Authentication</Tabs.Trigger>
                    {(canSystem || canPtwAccess) && <Tabs.Trigger value="users" className={TAB_CLASS}>Users</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="roles" className={TAB_CLASS}>Roles &amp; Permissions</Tabs.Trigger>}
                    {canSystem && <TabDivider />}

                    {canSystem && <Tabs.Trigger value="numbering" className={TAB_CLASS}>Numbering</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="approval-flows" className={TAB_CLASS}>Approval Flow</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="notifications" className={TAB_CLASS}>Notifications</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="documents" className={TAB_CLASS}>Documents</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="field-mapping" className={TAB_CLASS}>Import/Export Mapping</Tabs.Trigger>}
                    {canSystem && <TabDivider />}

                    {canSystem && <Tabs.Trigger value="subscription" className={TAB_CLASS}>Subscription</Tabs.Trigger>}
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
                {(canSystem || canPtwAccess) && <Tabs.Content value="users"><UsersTab users={users} roles={roles} ptwAccess={ptwAccess} canManageUsers={canSystem} canPtwAccess={canPtwAccess} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="roles"><RolesTab roles={roles} permissionCatalog={permissionCatalog} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="numbering"><NumberingTab numberingFormats={numberingFormats} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="approval-flows"><ApprovalFlowsTab approvalFlows={approvalFlows} moduleKeys={numberingModuleKeys} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="notifications"><NotificationPreferencesTab preferences={notificationPreferences} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="documents"><DocumentTemplatesTab documentTemplates={documentTemplates} moduleKeys={documentModuleKeys} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="field-mapping"><FieldMappingTab fieldMappings={fieldMappings} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="subscription"><SubscriptionTab subscription={subscription} invoices={invoices} /></Tabs.Content>}
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
        company_address: company.address || '',
        company_phone: company.phone || '',
        company_email: company.email || '',
        company_website: company.website || '',
        brand_color: company.brand_color || '#2563eb',
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
                    <div className="space-y-1.5">
                        <Label>Address (optional)</Label>
                        <Input value={data.company_address} onChange={(e) => setData('company_address', e.target.value)} placeholder="Used on future document letterheads" />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label>Phone (optional)</Label>
                            <Input value={data.company_phone} onChange={(e) => setData('company_phone', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Email (optional)</Label>
                            <Input type="email" value={data.company_email} onChange={(e) => setData('company_email', e.target.value)} />
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label>Website (optional)</Label>
                            <Input value={data.company_website} onChange={(e) => setData('company_website', e.target.value)} placeholder="https://" />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Brand Color</Label>
                            <div className="flex items-center gap-2">
                                <input type="color" value={data.brand_color} onChange={(e) => setData('brand_color', e.target.value)} className="h-8 w-10 cursor-pointer rounded border border-graphite-200" />
                                <Input value={data.brand_color} onChange={(e) => setData('brand_color', e.target.value)} className="w-24" />
                            </div>
                        </div>
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
// Milestone 3 (UAT #6): matches SettingsController::storeRole()/destroyRole()'s
// own built-in-name guard -- these 5 can't be renamed away or deleted
// from this UI, everything else is a genuinely custom, Company-Admin-
// created role.
const BUILT_IN_ROLES = ['super_admin', 'hse', 'hrd', 'manager', 'warehouse'];

function RolesTab({ roles, permissionCatalog }) {
    const [activeRoleId, setActiveRoleId] = useState(roles?.[0]?.id);
    const activeRole = (roles ?? []).find((r) => r.id === activeRoleId);

    const { data, setData, put, processing } = useForm({ permissions: activeRole?.permissions ?? [] });
    const createForm = useForm({ name: '' });

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

    function createRole(e) {
        e.preventDefault();
        createForm.post(route('settings.roles.store'), {
            preserveScroll: true,
            onSuccess: () => createForm.reset('name'),
        });
    }

    function deleteRole(role) {
        if (confirm(`Remove the "${role.name}" role? Users assigned to it keep their base role/capability.`)) {
            router.delete(route('settings.roles.destroy', role.id), { preserveScroll: true });
        }
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
                    Edit which permissions each role carries, or create an entirely new role for your
                    organization. <strong>Note:</strong> this updates the permission records themselves,
                    but no page in the app checks them yet for the 5 built-in roles -- those still use
                    each role's built-in capabilities. A custom role's permissions are already real and
                    live for anything that checks them.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    {(roles ?? []).map((role) => (
                        <div key={role.id} className="inline-flex items-center">
                            <button
                                type="button"
                                onClick={() => setActiveRoleId(role.id)}
                                className={`rounded-l-md px-3 py-1.5 text-sm font-medium ${role.id === activeRoleId ? 'bg-brand-50 text-brand-700' : 'bg-graphite-100 text-graphite-600 hover:bg-graphite-200'} ${BUILT_IN_ROLES.includes(role.name) ? 'rounded-r-md' : ''}`}
                            >
                                {ROLE_LABELS[role.name] ?? role.name}
                            </button>
                            {!BUILT_IN_ROLES.includes(role.name) && (
                                <button
                                    type="button"
                                    onClick={() => deleteRole(role)}
                                    className="rounded-r-md bg-graphite-100 px-1.5 py-1.5 text-graphite-400 hover:bg-red-50 hover:text-red-600"
                                    title="Delete this custom role"
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                </button>
                            )}
                        </div>
                    ))}
                </div>

                <form onSubmit={createRole} className="flex items-end gap-2 border-t border-graphite-100 pt-4">
                    <div className="space-y-1.5">
                        <Label>New Role Name</Label>
                        <Input
                            value={createForm.data.name}
                            onChange={(e) => createForm.setData('name', e.target.value)}
                            placeholder="e.g. Regional Supervisor"
                            className="w-56"
                        />
                        {createForm.errors.name && <p className="text-xs text-red-600">{createForm.errors.name}</p>}
                    </div>
                    <Button type="submit" disabled={createForm.processing || !createForm.data.name}>
                        <Plus className="h-4 w-4" /> Create Role
                    </Button>
                </form>

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
 * Milestone 3 (Company Settings completion, Task #62). Company-side
 * half of the Numbering Engine -- `App\Services\NumberGeneratorService`
 * already does the actual concurrency-safe generation; this is where a
 * Company Admin edits the FORMAT (prefix/pattern/padding/reset) for
 * each module, e.g. changing Material Request numbers from
 * `MR-{YEAR}-{SEQ}` to a company-specific scheme. Always saves a
 * tenant-scoped row -- never affects another tenant, see
 * NumberGeneratorService::resolveFormat()'s own doc comment.
 */
function NumberingTab({ numberingFormats }) {
    const { data, setData, post, processing } = useForm({ formats: numberingFormats ?? [] });

    function updateRow(moduleKey, patch) {
        setData('formats', data.formats.map((row) => (row.module_key === moduleKey ? { ...row, ...patch } : row)));
    }

    function submit(e) {
        e.preventDefault();
        post(route('settings.numbering'));
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Document Numbering</CardTitle>
                <CardDescription>
                    Customize how each module's document numbers are formatted, e.g. <code>MR-{'{YEAR}'}-{'{SEQ}'}</code>.
                    Available placeholders: <code>{'{PREFIX}'}</code>, <code>{'{YEAR}'}</code>, <code>{'{MONTH}'}</code>, <code>{'{SEQ}'}</code>.
                    Changes apply to new records only -- existing numbers never change.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-3">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Module</TableHead>
                                    <TableHead className="w-20">Prefix</TableHead>
                                    <TableHead className="w-40">Pattern</TableHead>
                                    <TableHead className="w-24">Padding</TableHead>
                                    <TableHead className="w-32">Reset</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.formats.map((row) => (
                                    <TableRow key={row.module_key}>
                                        <TableCell className="font-medium capitalize">{row.module_key.replace(/_/g, ' ')}</TableCell>
                                        <TableCell>
                                            <Input value={row.prefix} onChange={(e) => updateRow(row.module_key, { prefix: e.target.value })} />
                                        </TableCell>
                                        <TableCell>
                                            <Input value={row.pattern} onChange={(e) => updateRow(row.module_key, { pattern: e.target.value })} />
                                        </TableCell>
                                        <TableCell>
                                            <Input type="number" min={1} max={10} value={row.seq_padding} onChange={(e) => updateRow(row.module_key, { seq_padding: Number(e.target.value) })} />
                                        </TableCell>
                                        <TableCell>
                                            <Select value={row.reset_period} onValueChange={(v) => updateRow(row.module_key, { reset_period: v })}>
                                                <SelectTrigger><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="yearly">Yearly</SelectItem>
                                                    <SelectItem value="monthly">Monthly</SelectItem>
                                                    <SelectItem value="never">Never</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="h-4 w-4 animate-spin" />} Save Numbering
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

/**
 * Milestone 3 (Dynamic Document Engine, Task #66). Form-based template
 * management, deliberately NOT a drag-drop canvas -- one form per
 * module_key covering header/footer text and which of logo/QR/
 * signature/watermark to show. Rendered by App\Services\DocumentEngine;
 * see that class's doc comment for how it plugs into each module's
 * EXISTING PDF Blade view rather than a second rendering pipeline.
 */
function DocumentTemplatesTab({ documentTemplates, moduleKeys }) {
    const [editingId, setEditingId] = useState(null);
    const createForm = useForm({
        module_key: moduleKeys?.[0] ?? '',
        name: '',
        header_text: '',
        footer_text: '',
        show_logo: true,
        show_qr: false,
        show_signature: true,
        show_watermark: false,
        watermark_text: '',
    });
    const editForm = useForm({
        name: '', header_text: '', footer_text: '',
        show_logo: true, show_qr: false, show_signature: true, show_watermark: false, watermark_text: '',
    });

    function submitCreate(e) {
        e.preventDefault();
        createForm.post(route('settings.documents.store'), {
            preserveScroll: true,
            onSuccess: () => createForm.reset('name', 'header_text', 'footer_text', 'watermark_text'),
        });
    }

    function startEdit(t) {
        setEditingId(t.id);
        editForm.setData({
            name: t.name, header_text: t.header_text || '', footer_text: t.footer_text || '',
            show_logo: t.show_logo, show_qr: t.show_qr, show_signature: t.show_signature,
            show_watermark: t.show_watermark, watermark_text: t.watermark_text || '',
        });
    }

    function submitEdit(e, id) {
        e.preventDefault();
        editForm.put(route('settings.documents.update', id), {
            preserveScroll: true,
            onSuccess: () => setEditingId(null),
        });
    }

    // v2.12.0 (Product Finalization pass, Part 19 -- destructive action
    // confirmation): every sibling delete on this page already confirms
    // (companies/departments/positions/KPI categories/users) -- this one
    // was the sole exception, firing instantly on click.
    function destroy(id) {
        if (confirm('Hapus template dokumen ini?')) {
            router.delete(route('settings.documents.destroy', id), { preserveScroll: true });
        }
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Document Templates</CardTitle>
                <CardDescription>
                    One default template per module -- header/footer text and which of logo, QR verification text,
                    signature block, and watermark to show on that module's PDF. Applies automatically the moment
                    it's created; a module with no template here keeps its original built-in appearance.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
                {(documentTemplates ?? []).length === 0 ? (
                    <p className="text-sm text-graphite-400">No document templates yet -- create one below.</p>
                ) : (
                    <div className="space-y-2">
                        {documentTemplates.map((t) => (
                            <div key={t.id} className="rounded-lg border border-graphite-100 dark:border-slate-800">
                                {editingId === t.id ? (
                                    <form onSubmit={(e) => submitEdit(e, t.id)} className="space-y-3 p-4">
                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            <div>
                                                <Label>Name</Label>
                                                <Input value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} />
                                            </div>
                                            <div>
                                                <Label>Watermark Text</Label>
                                                <Input value={editForm.data.watermark_text} onChange={(e) => editForm.setData('watermark_text', e.target.value)} disabled={!editForm.data.show_watermark} />
                                            </div>
                                            <div>
                                                <Label>Header Text</Label>
                                                <Input value={editForm.data.header_text} onChange={(e) => editForm.setData('header_text', e.target.value)} />
                                            </div>
                                            <div>
                                                <Label>Footer Text</Label>
                                                <Input value={editForm.data.footer_text} onChange={(e) => editForm.setData('footer_text', e.target.value)} />
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap gap-4">
                                            {['show_logo', 'show_qr', 'show_signature', 'show_watermark'].map((key) => (
                                                <label key={key} className="flex items-center gap-1.5 text-sm">
                                                    <Checkbox checked={editForm.data[key]} onCheckedChange={(v) => editForm.setData(key, !!v)} />
                                                    {key.replace('show_', '').replace(/^./, (c) => c.toUpperCase())}
                                                </label>
                                            ))}
                                        </div>
                                        <div className="flex gap-2">
                                            <Button type="submit" size="sm" disabled={editForm.processing}>Save</Button>
                                            <Button type="button" size="sm" variant="outline" onClick={() => setEditingId(null)}>Cancel</Button>
                                        </div>
                                    </form>
                                ) : (
                                    <div className="flex items-center justify-between gap-3 p-4">
                                        <div>
                                            <p className="text-sm font-medium text-graphite-800 dark:text-slate-100">
                                                {t.name} <Badge variant="secondary" className="ml-1">{t.module_key.replace(/_/g, ' ')}</Badge>
                                            </p>
                                            <p className="mt-0.5 text-xs text-graphite-400">
                                                {[t.show_logo && 'Logo', t.show_qr && 'QR', t.show_signature && 'Signature', t.show_watermark && 'Watermark'].filter(Boolean).join(' · ') || 'No chrome enabled'}
                                            </p>
                                        </div>
                                        <div className="flex gap-1.5">
                                            <Button size="icon" variant="ghost" onClick={() => startEdit(t)}><Pencil className="h-3.5 w-3.5" /></Button>
                                            <Button size="icon" variant="ghost" onClick={() => destroy(t.id)}><Trash2 className="h-3.5 w-3.5 text-red-500" /></Button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}

                <form onSubmit={submitCreate} className="space-y-3 border-t border-graphite-100 pt-4 dark:border-slate-800">
                    <p className="text-sm font-semibold text-graphite-700 dark:text-slate-200">New Template</p>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label>Module</Label>
                            <Select value={createForm.data.module_key} onValueChange={(v) => createForm.setData('module_key', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {(moduleKeys ?? []).map((k) => <SelectItem key={k} value={k}>{k.replace(/_/g, ' ')}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Name</Label>
                            <Input value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} placeholder="Default Letterhead" />
                        </div>
                        <div>
                            <Label>Header Text</Label>
                            <Input value={createForm.data.header_text} onChange={(e) => createForm.setData('header_text', e.target.value)} />
                        </div>
                        <div>
                            <Label>Footer Text</Label>
                            <Input value={createForm.data.footer_text} onChange={(e) => createForm.setData('footer_text', e.target.value)} />
                        </div>
                        <div>
                            <Label>Watermark Text</Label>
                            <Input value={createForm.data.watermark_text} onChange={(e) => createForm.setData('watermark_text', e.target.value)} disabled={!createForm.data.show_watermark} placeholder="CONFIDENTIAL" />
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-4">
                        {['show_logo', 'show_qr', 'show_signature', 'show_watermark'].map((key) => (
                            <label key={key} className="flex items-center gap-1.5 text-sm">
                                <Checkbox checked={createForm.data[key]} onCheckedChange={(v) => createForm.setData(key, !!v)} />
                                {key.replace('show_', '').replace(/^./, (c) => c.toUpperCase())}
                            </label>
                        ))}
                    </div>
                    <Button type="submit" disabled={createForm.processing || !createForm.data.name}>
                        {createForm.processing && <Loader2 className="h-4 w-4 animate-spin" />} Create Template
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

/**
 * Milestone 3 (Import/Export Mapping, Task #67). One sub-form per
 * (module, direction) -- e.g. Employees/Import -- listing every field
 * from config/mapping_fields.php's catalog with an editable column
 * label + enabled toggle (export only; import always looks for every
 * field, an admin just points it at whatever header text their own
 * spreadsheet uses). Saving writes a full FieldMapping row per field via
 * `App\Services\FieldMappingService::upsert()`.
 */
function FieldMappingTab({ fieldMappings }) {
    const moduleKeys = Object.keys(fieldMappings ?? {});
    const [activeModule, setActiveModule] = useState(moduleKeys[0] ?? '');

    if (moduleKeys.length === 0) {
        return (
            <Card>
                <CardContent>
                    <p className="py-6 text-center text-sm text-graphite-400">No mappable modules registered yet.</p>
                </CardContent>
            </Card>
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex gap-2">
                {moduleKeys.map((k) => (
                    <Button key={k} size="sm" variant={activeModule === k ? 'default' : 'outline'} onClick={() => setActiveModule(k)} className="capitalize">
                        {k.replace(/_/g, ' ')}
                    </Button>
                ))}
            </div>
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <MappingDirectionCard
                    title="Import Mapping"
                    description="Point each field at the column header your own Excel file actually uses."
                    moduleKey={activeModule}
                    direction="import"
                    fields={fieldMappings[activeModule]?.import ?? {}}
                    showEnabledToggle={false}
                />
                <MappingDirectionCard
                    title="Export Mapping"
                    description="Rename, reorder, or omit columns in the exported Excel file."
                    moduleKey={activeModule}
                    direction="export"
                    fields={fieldMappings[activeModule]?.export ?? {}}
                    showEnabledToggle
                />
            </div>
        </div>
    );
}

function MappingDirectionCard({ title, description, moduleKey, direction, fields, showEnabledToggle }) {
    const { data, setData, post, processing } = useForm({
        module_key: moduleKey,
        direction,
        rows: Object.entries(fields).map(([field_key, f]) => ({ field_key, column_label: f.label, is_enabled: f.is_enabled })),
    });

    useEffect(() => {
        setData({
            module_key: moduleKey,
            direction,
            rows: Object.entries(fields).map(([field_key, f]) => ({ field_key, column_label: f.label, is_enabled: f.is_enabled })),
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [moduleKey]);

    function updateRow(fieldKey, patch) {
        setData('rows', data.rows.map((r) => (r.field_key === fieldKey ? { ...r, ...patch } : r)));
    }

    function submit(e) {
        e.preventDefault();
        post(route('settings.field-mapping'), { preserveScroll: true });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-2">
                    {data.rows.map((row) => (
                        <div key={row.field_key} className="flex items-center gap-2">
                            {showEnabledToggle && (
                                <Checkbox checked={row.is_enabled} onCheckedChange={(v) => updateRow(row.field_key, { is_enabled: !!v })} />
                            )}
                            <span className="w-40 shrink-0 truncate text-xs text-graphite-500 dark:text-slate-400">{row.field_key.replace(/_/g, ' ')}</span>
                            <Input
                                value={row.column_label}
                                onChange={(e) => updateRow(row.field_key, { column_label: e.target.value })}
                                disabled={showEnabledToggle && !row.is_enabled}
                            />
                        </div>
                    ))}
                    <Button type="submit" size="sm" disabled={processing} className="mt-2">
                        {processing && <Loader2 className="h-4 w-4 animate-spin" />} Save {title}
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

const APPROVAL_ROLE_OPTIONS = ['super_admin', 'hse', 'hrd', 'manager', 'warehouse'];

/**
 * Milestone 3 (Company Settings completion, Task #62). Company Admin's
 * own surface for `App\Services\ApprovalEngine`'s multi-level/parallel/
 * escalation capability (ADR-010) -- create a named flow for a module,
 * then define its steps. A module with no flow here keeps using the
 * legacy single-step approval (config('workflow.approvers')), unchanged.
 */
function ApprovalFlowsTab({ approvalFlows, moduleKeys }) {
    const [activeFlowId, setActiveFlowId] = useState(approvalFlows?.[0]?.id ?? null);
    const activeFlow = (approvalFlows ?? []).find((f) => f.id === activeFlowId);
    const createForm = useForm({ module_key: moduleKeys?.[0] ?? '', name: '' });
    const stepsForm = useForm({ steps: activeFlow?.steps ?? [] });

    useEffect(() => {
        stepsForm.setData('steps', (approvalFlows ?? []).find((f) => f.id === activeFlowId)?.steps ?? []);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activeFlowId]);

    function createFlow(e) {
        e.preventDefault();
        createForm.post(route('settings.approval-flows.store'), { preserveScroll: true, onSuccess: () => createForm.reset('name') });
    }

    function deleteFlow(flow) {
        if (confirm(`Remove the "${flow.name}" flow? ${flow.module_key} will go back to standard single-step approval.`)) {
            router.delete(route('settings.approval-flows.destroy', flow.id), { preserveScroll: true });
            if (activeFlowId === flow.id) setActiveFlowId(null);
        }
    }

    function addStep() {
        const nextStepNumber = (stepsForm.data.steps.at(-1)?.step_number ?? 0) + 1;
        stepsForm.setData('steps', [...stepsForm.data.steps, { step_number: nextStepNumber, mode: 'single', approver_role: 'manager', escalate_after_hours: '', escalate_to_role: '' }]);
    }

    function updateStep(index, patch) {
        stepsForm.setData('steps', stepsForm.data.steps.map((s, i) => (i === index ? { ...s, ...patch } : s)));
    }

    function removeStep(index) {
        stepsForm.setData('steps', stepsForm.data.steps.filter((_, i) => i !== index));
    }

    function saveSteps(e) {
        e.preventDefault();
        stepsForm.put(route('settings.approval-flows.steps', activeFlowId), { preserveScroll: true });
    }

    return (
        <div className="space-y-4">
            <Card>
                <CardHeader>
                    <CardTitle>Approval Flows</CardTitle>
                    <CardDescription>
                        Multi-level, parallel, or escalating approval chains per module. A module without a
                        flow here keeps its standard single-step approval.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex flex-wrap items-center gap-2">
                        {(approvalFlows ?? []).map((flow) => (
                            <div key={flow.id} className="inline-flex items-center">
                                <button
                                    type="button"
                                    onClick={() => setActiveFlowId(flow.id)}
                                    className={`rounded-l-md px-3 py-1.5 text-sm font-medium ${flow.id === activeFlowId ? 'bg-brand-50 text-brand-700' : 'bg-graphite-100 text-graphite-600 hover:bg-graphite-200'}`}
                                >
                                    {flow.name} <span className="text-xs text-graphite-400">({flow.module_key})</span>
                                </button>
                                <button type="button" onClick={() => deleteFlow(flow)} className="rounded-r-md bg-graphite-100 px-1.5 py-1.5 text-graphite-400 hover:bg-red-50 hover:text-red-600" title="Delete this flow">
                                    <Trash2 className="h-3.5 w-3.5" />
                                </button>
                            </div>
                        ))}
                        {(approvalFlows ?? []).length === 0 && <p className="text-sm text-graphite-400">No custom approval flows configured yet.</p>}
                    </div>

                    <form onSubmit={createFlow} className="flex items-end gap-2 border-t border-graphite-100 pt-4">
                        <div className="space-y-1.5">
                            <Label>Module</Label>
                            <Select value={createForm.data.module_key} onValueChange={(v) => createForm.setData('module_key', v)}>
                                <SelectTrigger className="w-48"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {(moduleKeys ?? []).map((k) => <SelectItem key={k} value={k}>{k.replace(/_/g, ' ')}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Flow Name</Label>
                            <Input value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} placeholder="e.g. Two-Level Approval" className="w-56" />
                        </div>
                        <Button type="submit" disabled={createForm.processing || !createForm.data.name}>
                            <Plus className="h-4 w-4" /> Create Flow
                        </Button>
                    </form>
                </CardContent>
            </Card>

            {activeFlow && (
                <Card>
                    <CardHeader>
                        <CardTitle>Steps for "{activeFlow.name}"</CardTitle>
                        <CardDescription>
                            Executed in order. Parallel steps: use the same step number on multiple rows --
                            "any" needs one approver, "all" needs every one of them.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={saveSteps} className="space-y-3">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-16">Step</TableHead>
                                            <TableHead className="w-32">Mode</TableHead>
                                            <TableHead className="w-32">Approver Role</TableHead>
                                            <TableHead className="w-28">Escalate After (h)</TableHead>
                                            <TableHead className="w-32">Escalate To</TableHead>
                                            <TableHead />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {stepsForm.data.steps.map((step, i) => (
                                            <TableRow key={i}>
                                                <TableCell>
                                                    <Input type="number" min={1} value={step.step_number} onChange={(e) => updateStep(i, { step_number: Number(e.target.value) })} />
                                                </TableCell>
                                                <TableCell>
                                                    <Select value={step.mode} onValueChange={(v) => updateStep(i, { mode: v })}>
                                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="single">Single</SelectItem>
                                                            <SelectItem value="parallel_any">Parallel (any)</SelectItem>
                                                            <SelectItem value="parallel_all">Parallel (all)</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell>
                                                    <Select value={step.approver_role} onValueChange={(v) => updateStep(i, { approver_role: v })}>
                                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                                        <SelectContent>
                                                            {APPROVAL_ROLE_OPTIONS.map((r) => <SelectItem key={r} value={r}>{ROLE_LABELS[r] ?? r}</SelectItem>)}
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell>
                                                    <Input type="number" min={1} value={step.escalate_after_hours ?? ''} onChange={(e) => updateStep(i, { escalate_after_hours: e.target.value ? Number(e.target.value) : null })} placeholder="none" />
                                                </TableCell>
                                                <TableCell>
                                                    <Select value={step.escalate_to_role ?? ''} onValueChange={(v) => updateStep(i, { escalate_to_role: v || null })}>
                                                        <SelectTrigger><SelectValue placeholder="none" /></SelectTrigger>
                                                        <SelectContent>
                                                            {APPROVAL_ROLE_OPTIONS.map((r) => <SelectItem key={r} value={r}>{ROLE_LABELS[r] ?? r}</SelectItem>)}
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell>
                                                    <Button type="button" variant="ghost" size="icon" onClick={() => removeStep(i)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                            <div className="flex gap-2">
                                <Button type="button" variant="outline" onClick={addStep}><Plus className="h-4 w-4" /> Add Step</Button>
                                <Button type="submit" disabled={stepsForm.processing}>
                                    {stepsForm.processing && <Loader2 className="h-4 w-4 animate-spin" />} Save Steps
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

const NOTIFICATION_CATEGORY_INFO = {
    approval: { label: 'Approval', description: 'Someone needs to decide, or a decision was made on your request.' },
    reminder: { label: 'Reminder', description: 'Upcoming deadlines and follow-ups.' },
    warning: { label: 'Warning', description: 'Rejections, cancellations, and escalations.' },
    success: { label: 'Success', description: 'Approvals and completions.' },
    information: { label: 'Information', description: 'General status updates.' },
};

/**
 * Milestone 3 (Company Settings completion, Task #62). Turns a
 * notification category off platform-wide -- `NotificationService::notify()`
 * checks this and skips creating the row entirely for a disabled
 * category, not just hiding it in the bell dropdown.
 */
function NotificationPreferencesTab({ preferences }) {
    const { data, setData, post, processing } = useForm({ preferences: preferences ?? {} });

    function toggle(category) {
        setData('preferences', { ...data.preferences, [category]: !data.preferences[category] });
    }

    function submit(e) {
        e.preventDefault();
        post(route('settings.notifications'));
    }

    return (
        <Card className="max-w-lg">
            <CardHeader>
                <CardTitle>Notification Preferences</CardTitle>
                <CardDescription>
                    Turn a category off to stop it from being created at all, for every user, not just hide
                    it in the bell dropdown.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-3">
                    {Object.entries(NOTIFICATION_CATEGORY_INFO).map(([key, { label, description }]) => (
                        <label key={key} className="flex items-center justify-between rounded-lg border border-graphite-100 px-3 py-2.5">
                            <div>
                                <p className="text-sm font-medium text-graphite-700">{label}</p>
                                <p className="text-xs text-graphite-400">{description}</p>
                            </div>
                            <Checkbox checked={data.preferences[key] !== false} onCheckedChange={() => toggle(key)} />
                        </label>
                    ))}
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="h-4 w-4 animate-spin" />} Save Preferences
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

    // Milestone 3 (UAT #4/#5): only workspaces Platform has actually
    // granted this tenant show up here at all -- `granted` comes from
    // HandleInertiaRequests' workspace_catalog prop, distinct from
    // `is_active` (which stays the admin's own on/off choice for a
    // granted workspace). Matches the same restriction
    // SettingsController::updateWorkspaces() already enforces server-side.
    const rows = WORKSPACES
        .map((workspace, index) => {
            const override = catalog?.[workspace.key];
            return {
                key: workspace.key,
                defaultLabel: workspace.label,
                label: override?.label ?? workspace.label,
                sort_order: override?.sort_order ?? index + 1,
                is_active: override?.granted ? (override?.is_active ?? true) : true,
                granted: override?.granted ?? false,
            };
        })
        .filter((row) => row.granted);

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
                    each row here corresponds to a department your plan includes. Need another one? Contact
                    the platform operator to have it granted to your organization.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <p className="py-6 text-center text-sm text-graphite-400">
                        No departments have been granted to your organization yet.
                    </p>
                ) : (
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
                )}
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
                    Show or hide modules your organization's plan includes, app-wide, grouped by workspace.
                    Home, Dashboard, and Settings are always visible and can't be hidden here. Turning off
                    every module in a workspace hides that whole workspace from the switcher. This controls{' '}
                    <strong>visibility only</strong> -- it does not grant new modules. Need a module you don't
                    see here? Contact the platform operator to have it granted to your organization.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {availableEntries.length === 0 ? (
                    <p className="py-6 text-center text-sm text-graphite-400">
                        No modules have been granted to your organization yet.
                    </p>
                ) : (
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
                )}
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
        if (confirm('Hapus perusahaan ini?')) router.delete(route('settings.companies.destroy', id));
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
        if (confirm('Hapus posisi ini?')) router.delete(route('settings.positions.destroy', id));
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
        if (confirm('Hapus kategori KPI ini? Hanya bisa jika belum ada data KPI yang tercatat.')) {
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

// v1.10.7. Department options for the "restrict this user to one
// department" field -- derived from the SAME `WORKSPACES` array the
// sidebar itself uses, filtered to `tier === 'department'` (this
// naturally excludes Reports/Administration, which aren't real
// assignable departments, without needing a second hardcoded list to
// keep in sync -- config('departments')'s own `assignableDepartmentKeys()`
// on the backend does the equivalent exclusion explicitly).
const DEPARTMENT_OPTIONS = WORKSPACES.filter((w) => w.tier === 'department').map((w) => ({ key: w.key, label: w.label }));

function DepartmentField({ value, onChange }) {
    return (
        <div className="space-y-1.5">
            <Label>Department Restriction</Label>
            <Select value={value || 'none'} onValueChange={(v) => onChange(v === 'none' ? '' : v)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="none">None -- Administrator (sees every department)</SelectItem>
                    {DEPARTMENT_OPTIONS.map((d) => <SelectItem key={d.key} value={d.key}>{d.label}</SelectItem>)}
                </SelectContent>
            </Select>
            <p className="text-xs text-graphite-400">
                Restricts this account to only the selected department's sidebar and routes -- enforced by the backend, not just hidden from view.
                Leave as "None" for a full Administrator (matches every account's behavior today).
            </p>
        </div>
    );
}

/**
 * v2.19.0 (PTW Access Management Correction pass, Part 1). Splits what
 * used to be one `<Card>` (User Management table with Field & PTW Access
 * bolted onto its bottom as a `border-t` sub-section) into two
 * INDEPENDENT cards -- the User Management card (create/edit/delete/role
 * changes) now renders ONLY for `canManageUsers` (Super Admin), while
 * the Field & PTW Access card renders for `canManageUsers ||
 * canPtwAccess` (Super Admin OR HSE). Previously this whole tab was
 * Super-Admin-only end to end, so there was no need for this split; now
 * that HSE can reach the Users tab specifically to manage PTW Access,
 * bolting it onto a Card that only Super Admin should see the CRUD
 * actions of would either hide PTW Access from HSE entirely (defeating
 * the point) or expose user create/edit/delete/role-change to HSE
 * (explicitly forbidden by this pass's own directive). Splitting the
 * cards is the smallest correct fix -- same components, same data, no
 * new page.
 */
function UsersTab({ users, roles, ptwAccess, canManageUsers, canPtwAccess }) {
    return (
        <div className="space-y-4">
            {canManageUsers && <UserManagementCard users={users} roles={roles} />}
            {(canManageUsers || canPtwAccess) && <FieldPtwAccessCard users={users} ptwAccess={ptwAccess} />}
        </div>
    );
}

function UserManagementCard({ users, roles }) {
    const [open, setOpen] = useState(false);
    const [editingRolesFor, setEditingRolesFor] = useState(null);
    const [editingUser, setEditingUser] = useState(null);
    const { data, setData, post, processing, reset, errors } = useForm({ name: '', email: '', password: '', role: 'hrd', department_key: '' });
    const customRoles = (roles ?? []).filter((r) => !BUILT_IN_ROLES.includes(r.name));

    function submit(e) {
        e.preventDefault();
        post(route('settings.users.store'), { onSuccess: () => { reset(); setOpen(false); } });
    }

    function destroy(id) {
        if (confirm('Hapus pengguna ini?')) router.delete(route('settings.users.destroy', id));
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <div><CardTitle>User Management</CardTitle><CardDescription>Administrator, HSE, HRD, or Manager.</CardDescription></div>
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogTrigger asChild><Button size="sm"><Plus className="h-4 w-4" /> Add User</Button></DialogTrigger>
                    <DialogContent>
                        <DialogHeader><DialogTitle>Create User</DialogTitle></DialogHeader>
                        <form onSubmit={submit} className="space-y-3">
                            {/* v1.10.8: was previously silent for any field
                                other than email/password -- a role or
                                department_key validation failure gave no
                                visible feedback at all, easy to mistake for
                                "saved successfully, just didn't apply." */}
                            {Object.keys(errors).length > 0 && (
                                <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">
                                    {Object.values(errors).map((msg, i) => <p key={i}>{msg}</p>)}
                                </div>
                            )}
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
                                        <SelectItem value="super_admin">Administrator</SelectItem>
                                        <SelectItem value="hse">HSE</SelectItem>
                                        <SelectItem value="hrd">HRD</SelectItem>
                                        <SelectItem value="manager">Manager</SelectItem>
                                        <SelectItem value="warehouse">Warehouse</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <DepartmentField value={data.department_key} onChange={(v) => setData('department_key', v)} />
                            <DialogFooter><Button type="submit" disabled={processing}>Create</Button></DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Email</TableHead><TableHead>Role</TableHead><TableHead>Department</TableHead><TableHead>Custom Roles</TableHead><TableHead>Status</TableHead><TableHead /></TableRow></TableHeader>
                    <TableBody>
                        {users.map((u) => {
                            const assignedCustom = customRoles.filter((r) => (u.role_ids ?? []).includes(r.id));
                            const deptLabel = DEPARTMENT_OPTIONS.find((d) => d.key === u.department_key)?.label;
                            return (
                                <TableRow key={u.id}>
                                    <TableCell className="font-medium">{u.name}</TableCell>
                                    <TableCell>{u.email}</TableCell>
                                    <TableCell><Badge variant={u.role === 'super_admin' ? 'default' : 'secondary'}>{ROLE_LABELS[u.role] ?? u.role}</Badge></TableCell>
                                    <TableCell>
                                        {deptLabel ? <Badge variant="outline">{deptLabel}</Badge> : <span className="text-xs text-graphite-400">Administrator (all)</span>}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap items-center gap-1">
                                            {assignedCustom.map((r) => <Badge key={r.id} variant="outline">{r.name}</Badge>)}
                                            {customRoles.length > 0 && (
                                                <button type="button" onClick={() => setEditingRolesFor(u)} className="text-xs text-brand-600 hover:underline">
                                                    Edit
                                                </button>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell><Badge variant={u.is_active ? 'success' : 'secondary'}>{u.is_active ? 'Active' : 'Inactive'}</Badge></TableCell>
                                    <TableCell className="flex items-center gap-1">
                                        <Button variant="ghost" size="icon" onClick={() => setEditingUser(u)}><Pencil className="h-4 w-4" /></Button>
                                        <Button variant="ghost" size="icon" onClick={() => destroy(u.id)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </CardContent>
            {editingRolesFor && (
                <UserRolesDialog user={editingRolesFor} customRoles={customRoles} onClose={() => setEditingRolesFor(null)} />
            )}
            {editingUser && (
                <EditUserDialog user={editingUser} onClose={() => setEditingUser(null)} />
            )}
        </Card>
    );
}

/**
 * v2.17.0 (PTW Field Workflow Foundation + Controlled PTW Access, Part
 * 3/4/7). "PTW Access" -- deliberately a SEPARATE card/concern from
 * ordinary User Management above (different endpoint, different
 * business rule: quota-checked, not just a profile edit). Reuses the
 * exact same search-input pattern already established elsewhere in this
 * codebase (e.g. Incidents/Index.jsx's filter bar) rather than inventing
 * a new one. Client-side filtering only (this tenant's own user list is
 * already small enough to have been sent whole by SettingsController::
 * index() -- no new endpoint needed for search).
 */
function FieldPtwAccessCard({ users, ptwAccess }) {
    const [search, setSearch] = useState('');
    const used = ptwAccess?.used ?? 0;
    const quota = ptwAccess?.quota ?? null;
    const quotaReached = quota !== null && used >= quota;

    const filtered = users.filter((u) => {
        const q = search.trim().toLowerCase();
        if (!q) return true;
        return u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q);
    });

    function toggle(user, next) {
        if (next && quotaReached && !user.ptw_access) {
            alert('Kuota pengguna PTW paket Anda telah tercapai.');
            return;
        }
        router.put(route('settings.users.ptw-access', user.id), { ptw_access: next }, { preserveScroll: true });
    }

    return (
        <Card>
            <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <CardTitle>Field &amp; PTW Access</CardTitle>
                    {/* Directive's own exact Indonesian description text --
                        deliberately verbatim, not paraphrased. */}
                    <CardDescription>User yang diizinkan membuat pengajuan PTW.</CardDescription>
                </div>
                <Badge variant={quotaReached ? 'warning' : 'outline'} className="w-fit shrink-0">
                    PTW Access {used}{quota !== null ? ` / ${quota}` : ''} {quota !== null ? 'users' : ''}
                </Badge>
            </CardHeader>
            <CardContent className="p-0">
                {quotaReached && (
                    <div className="mx-4 mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">
                        Kuota pengguna PTW paket Anda telah tercapai. Nonaktifkan salah satu pengguna, atau hubungi penyedia layanan untuk meningkatkan paket.
                    </div>
                )}
                <div className="px-4 pb-3">
                    <div className="relative max-w-sm">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search users..." value={search} onChange={(e) => setSearch(e.target.value)} />
                    </div>
                </div>
                <div className="divide-y divide-graphite-100 dark:divide-slate-800">
                    {filtered.length === 0 ? (
                        <p className="px-4 pb-4 text-sm text-graphite-400">No users match your search.</p>
                    ) : (
                        filtered.map((u) => {
                            const deptLabel = DEPARTMENT_OPTIONS.find((d) => d.key === u.department_key)?.label;
                            return (
                                <div
                                    key={u.id}
                                    className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 px-4 py-3"
                                >
                                    <div className="flex min-w-0 flex-1 items-center gap-2.5">
                                        <Checkbox checked={!!u.ptw_access} onCheckedChange={(v) => toggle(u, Boolean(v))} />
                                        <div className="min-w-0">
                                            <p className="truncate text-[13px] font-medium text-graphite-900 dark:text-slate-100">{u.name}</p>
                                            <p className="truncate text-[11px] font-medium uppercase tracking-wide text-graphite-400 dark:text-slate-500">
                                                {deptLabel || 'Administrator (all)'}
                                            </p>
                                        </div>
                                    </div>
                                    <span className="shrink-0 text-xs font-medium text-graphite-500 dark:text-slate-400">PTW Access</span>
                                </div>
                            );
                        })
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * v1.10.7. `settings.users.update` has existed since this controller was
 * first built, but nothing in this tab ever called it -- there was no way
 * to edit an existing user at all (rename, change role, reset password,
 * or set the Department Restriction this same release adds to Create).
 * Same field set as Create, pre-filled, password left blank (only sent if
 * actually changed -- matches UpdateUserRequest's own `nullable` rule).
 */
function EditUserDialog({ user, onClose }) {
    const { data, setData, put, processing, errors } = useForm({
        name: user.name,
        email: user.email,
        password: '',
        role: user.role,
        department_key: user.department_key || '',
        is_active: user.is_active,
    });

    function submit(e) {
        e.preventDefault();
        put(route('settings.users.update', user.id), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader><DialogTitle>Edit {user.name}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">
                            {Object.values(errors).map((msg, i) => <p key={i}>{msg}</p>)}
                        </div>
                    )}
                    <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} /></div>
                    <div className="space-y-1.5"><Label>Email</Label><Input type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        {errors.email && <p className="text-xs text-red-600">{errors.email}</p>}
                    </div>
                    <div className="space-y-1.5"><Label>New Password (leave blank to keep current)</Label><Input type="password" value={data.password} onChange={(e) => setData('password', e.target.value)} />
                        {errors.password && <p className="text-xs text-red-600">{errors.password}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label>Role</Label>
                        <Select value={data.role} onValueChange={(v) => setData('role', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="super_admin">Administrator</SelectItem>
                                <SelectItem value="hse">HSE</SelectItem>
                                <SelectItem value="hrd">HRD</SelectItem>
                                <SelectItem value="manager">Manager</SelectItem>
                                <SelectItem value="warehouse">Warehouse</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <DepartmentField value={data.department_key} onChange={(v) => setData('department_key', v)} />
                    <div className="flex items-center gap-2">
                        <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', Boolean(v))} />
                        <Label className="!mt-0">Active</Label>
                    </div>
                    <DialogFooter><Button type="submit" disabled={processing}>{processing && <Loader2 className="h-4 w-4 animate-spin" />} Save</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Milestone 3 (UAT #6). Assigns/unassigns a user's CUSTOM roles --
 * additive on top of their base `role` column, which this never touches
 * (see SettingsController::updateUserRoles()'s own doc comment).
 */
function UserRolesDialog({ user, customRoles, onClose }) {
    const { data, setData, put, processing } = useForm({ role_ids: user.role_ids ?? [] });

    function toggle(id) {
        setData('role_ids', data.role_ids.includes(id) ? data.role_ids.filter((i) => i !== id) : [...data.role_ids, id]);
    }

    function submit(e) {
        e.preventDefault();
        put(route('settings.users.roles', user.id), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader><DialogTitle>Custom Roles for {user.name}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    {customRoles.map((r) => (
                        <label key={r.id} className="flex items-center justify-between rounded-lg border border-graphite-100 px-3 py-2">
                            <span className="text-sm text-graphite-700">{r.name}</span>
                            <Checkbox checked={data.role_ids.includes(r.id)} onCheckedChange={() => toggle(r.id)} />
                        </label>
                    ))}
                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <Loader2 className="h-4 w-4 animate-spin" />} Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

const SUB_STATUS_VARIANT = {
    active: 'success', trial: 'default', grace_period: 'default',
    expired: 'destructive', suspended: 'destructive', cancelled: 'secondary',
};

const INVOICE_STATUS_VARIANT = { draft: 'secondary', issued: 'default', paid: 'success', overdue: 'destructive', void: 'secondary' };

/**
 * v1.11.0 (SaaS Finalization Pass, Part 19). Tenant Admin's read-only
 * view of their own commercial record -- changing plan/type/status stays
 * Platform Admin-only (PlatformController::updateSubscription()), this
 * tab only displays what SettingsController::index() already resolved.
 * If lifetime, explicitly shows "Lifetime License" and never a
 * recurring-renewal date, per the explicit product requirement.
 */
function SubscriptionTab({ subscription, invoices }) {
    if (!subscription) {
        return (
            <Card>
                <CardHeader><CardTitle>Subscription</CardTitle></CardHeader>
                {/* v2.25.0 (Global UX & Copywriting Polish pass): naturalized to Indonesian. */}
                <CardContent><p className="text-sm text-graphite-400">Belum ada data langganan untuk perusahaan Anda. Hubungi penyedia layanan.</p></CardContent>
            </Card>
        );
    }

    const formatDate = (v) => (v ? new Date(v).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '—');

    return (
        <div className="space-y-4">
            {/* v1.11.1, Part 15/16: "degraded" (expired-by-date, not yet
                explicitly suspended) is shown as a warning here -- access
                is NOT blocked for this state, only for an explicit
                suspended/cancelled status (see Subscription::isBlocked()'s
                own doc comment). */}
            {subscription.is_degraded && (
                <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">
                    Your subscription/trial period has passed its end date. Access has not been restricted, but please renew soon to avoid interruption -- contact your platform provider.
                </div>
            )}
            {!subscription.is_usable && (
                <div className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">
                    Your organization's access has been {subscription.status} by the platform provider. Contact them to restore access.
                </div>
            )}
            <Card>
                <CardHeader className="flex flex-row items-start justify-between gap-3">
                    <div>
                        <CardTitle>Subscription / License</CardTitle>
                        {/* v2.25.0 (Global UX & Copywriting Polish pass): naturalized to Indonesian, per this pass's own example phrasing. */}
                        <CardDescription>Kelola paket dan status langganan IOMS perusahaan Anda.</CardDescription>
                    </div>
                    {/* v2.14.0 (SaaS Productization, Part 8): links to the new
                        data-driven Plans page rather than duplicating a plan
                        comparison inline on this tab. */}
                    <Button variant="outline" size="sm" asChild><Link href={route('subscription.plans')}>Lihat Paket Lain</Link></Button>
                </CardHeader>
                <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="space-y-3 text-sm">
                        <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">Plan</span><span className="font-medium">{subscription.package_name ?? '—'}</span></div>
                        <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">License Type</span><span className="font-medium capitalize">{subscription.type ?? 'subscription'}</span></div>
                        <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">Status</span><Badge variant={SUB_STATUS_VARIANT[subscription.status] ?? 'secondary'} className="capitalize">{subscription.status?.replace('_', ' ')}</Badge></div>
                        <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">Seat Limit</span><span className="font-medium">{subscription.seat_limit ?? 'Unlimited'}</span></div>
                    </div>
                    <div className="space-y-3 text-sm">
                        <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">Start Date</span><span className="font-medium">{formatDate(subscription.starts_at)}</span></div>
                        {subscription.type === 'lifetime' ? (
                            <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">Expiry</span><Badge variant="success">Lifetime License -- no expiry</Badge></div>
                        ) : (
                            <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">{subscription.status === 'trial' ? 'Trial Ends' : 'Renewal / Expiry'}</span><span className="font-medium">{formatDate(subscription.status === 'trial' ? subscription.trial_ends_at : subscription.ends_at)}</span></div>
                        )}
                        <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">Billing Cycle</span><span className="font-medium capitalize">{subscription.type === 'lifetime' ? 'N/A' : subscription.billing_cycle}</span></div>
                        <div className="flex items-center justify-between pb-2"><span className="text-graphite-500">Currently Usable</span>{subscription.is_usable ? <Badge variant="success">Yes</Badge> : <Badge variant="destructive">No -- contact your provider</Badge>}</div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>Invoices</CardTitle><CardDescription>Billing history for your organization.</CardDescription></CardHeader>
                <CardContent className="p-0">
                    {invoices.length === 0 ? (
                        <p className="p-4 text-center text-sm text-graphite-400">No invoices yet.</p>
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Invoice #</TableHead><TableHead>Amount</TableHead><TableHead>Due</TableHead><TableHead>Status</TableHead><TableHead>Payment Date</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {invoices.map((inv) => (
                                    <TableRow key={inv.id}>
                                        <TableCell className="font-medium">{inv.invoice_number}</TableCell>
                                        <TableCell>{inv.currency} {Number(inv.amount).toLocaleString()}</TableCell>
                                        <TableCell>{formatDate(inv.due_date)}</TableCell>
                                        <TableCell><Badge variant={INVOICE_STATUS_VARIANT[inv.status] ?? 'secondary'}>{inv.status}</Badge></TableCell>
                                        <TableCell>{formatDate(inv.payment_date)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

function BackupTab() {
    const { data, setData, post, processing } = useForm({ backup_file: null });

    function restore(e) {
        e.preventDefault();
        if (!confirm('Ini akan menimpa database saat ini. Lanjutkan?')) return;
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
