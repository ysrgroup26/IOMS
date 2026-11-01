/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { useForm, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Loader2 } from 'lucide-react';
import { WORKSPACES } from '@/lib/workspaces';
import { MODULE_WORKSPACE } from './shared';

export default function ModulesTab() {
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
                                    <label key={key} className="flex items-center justify-between rounded-xl border border-steel-100 bg-white/60 px-3 py-2.5">
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
                                <label key={key} className="flex items-center justify-between rounded-xl border border-steel-100 bg-white/60 px-3 py-2.5">
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
