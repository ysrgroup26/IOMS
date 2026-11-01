/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { useForm, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Checkbox } from '@/Components/ui/checkbox';
import { Loader2 } from 'lucide-react';
import { WORKSPACES } from '@/lib/workspaces';

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
export default function WorkspaceLabelsCard() {
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
