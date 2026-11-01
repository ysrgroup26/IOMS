/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { useForm } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Checkbox } from '@/Components/ui/checkbox';
import { Loader2 } from 'lucide-react';
import { moduleLabel } from '@/lib/moduleLabels';
import { humanizeToken } from '@/lib/humanizeToken';

/**
 * Milestone 3 (Import/Export Mapping, Task #67). One sub-form per
 * (module, direction) -- e.g. Employees/Import -- listing every field
 * from config/mapping_fields.php's catalog with an editable column
 * label + enabled toggle (export only; import always looks for every
 * field, an admin just points it at whatever header text their own
 * spreadsheet uses). Saving writes a full FieldMapping row per field via
 * `App\Services\FieldMappingService::upsert()`.
 */
export default function FieldMappingTab({ fieldMappings }) {
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
                        {moduleLabel(k)}
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
                            <span className="w-40 shrink-0 truncate text-xs text-graphite-500 dark:text-slate-400">{humanizeToken(row.field_key)}</span>
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
