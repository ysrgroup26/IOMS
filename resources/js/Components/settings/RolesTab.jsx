/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { useForm, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Checkbox } from '@/Components/ui/checkbox';
import { Plus, Trash2, Loader2 } from 'lucide-react';
import { ROLE_LABELS, BUILT_IN_ROLES } from './shared';

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


export default function RolesTab({ roles, permissionCatalog }) {
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
                                    <label key={permission} className="flex items-center justify-between rounded-xl border border-steel-100 bg-white/60 px-3 py-2">
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
