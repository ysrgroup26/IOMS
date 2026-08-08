import { Head, Link, useForm } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Button } from '@/Components/ui/button';
import { ArrowLeft, Loader2 } from 'lucide-react';

/**
 * Milestone 3 (UAT #4/#5). The Platform-side half of the module/workspace
 * grant system -- what a tenant's Company Admin is even ALLOWED to
 * enable (SettingsController::updateModules()/updateWorkspaces() enforce
 * the other half, on the tenant side). Deliberately plain checkboxes,
 * not a "designer" -- this is a grant list, not a page builder.
 */
export default function PlatformTenantGrants({ tenant, modules, workspaces }) {
    const { data, setData, put, processing } = useForm({
        module_ids: modules.filter((m) => m.granted).map((m) => m.id),
        workspace_ids: workspaces.filter((w) => w.granted).map((w) => w.id),
    });

    function toggle(key, id) {
        setData(key, data[key].includes(id) ? data[key].filter((i) => i !== id) : [...data[key], id]);
    }

    function submit(e) {
        e.preventDefault();
        put(route('platform.tenants.grants.update', tenant.id));
    }

    return (
        <PlatformLayout>
            <Head title={`${tenant.name} -- Grants`} />

            <Link href={route('platform.tenants')} className="mb-4 inline-flex items-center gap-1.5 text-sm text-graphite-500 hover:text-graphite-700">
                <ArrowLeft className="h-4 w-4" /> Back to Tenants
            </Link>

            <div className="mb-6">
                <h1 className="text-lg font-bold tracking-tight text-graphite-900">{tenant.name} -- Module &amp; Workspace Grants</h1>
                <p className="mt-1 text-sm text-graphite-500">
                    Only modules and workspaces checked here can be enabled by this tenant's own Administrator
                    (Settings &rarr; Module Visibility / Department Navigation). Unchecking one here immediately
                    hides it from that tenant, even if their Administrator had it turned on.
                </p>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Modules</CardTitle>
                        <CardDescription>{data.module_ids.length} of {modules.length} granted</CardDescription>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {modules.map((m) => (
                            <label key={m.id} className="flex items-center justify-between rounded-lg border border-graphite-100 px-3 py-2">
                                <span className="text-sm text-graphite-700">{m.label}</span>
                                <Checkbox checked={data.module_ids.includes(m.id)} onCheckedChange={() => toggle('module_ids', m.id)} />
                            </label>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Workspaces (Departments)</CardTitle>
                        <CardDescription>{data.workspace_ids.length} of {workspaces.length} granted</CardDescription>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {workspaces.map((w) => (
                            <label key={w.id} className="flex items-center justify-between rounded-lg border border-graphite-100 px-3 py-2">
                                <span className="text-sm text-graphite-700">{w.label}</span>
                                <Checkbox checked={data.workspace_ids.includes(w.id)} onCheckedChange={() => toggle('workspace_ids', w.id)} />
                            </label>
                        ))}
                    </CardContent>
                </Card>

                <Button type="submit" disabled={processing}>
                    {processing && <Loader2 className="h-4 w-4 animate-spin" />} Save Grants
                </Button>
            </form>
        </PlatformLayout>
    );
}
