/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Loader2 } from 'lucide-react';
import { moduleLabel } from '@/lib/moduleLabels';

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
export default function NumberingTab({ numberingFormats }) {
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
                                        <TableCell className="font-medium">{moduleLabel(row.module_key)}</TableCell>
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
