import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';

const BLANK_ITEM = { item: '', result: 'ok', remarks: '' };

export default function HseInspectionForm({ companies, projects, inspectionNumber, types }) {
    const { data, setData, post, processing, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        project_id: '',
        inspection_type: 'general',
        location: '',
        inspection_date: new Date().toISOString().slice(0, 10),
        notes: '',
        checklist_items: [{ ...BLANK_ITEM }],
    });

    function updateItem(i, field, value) {
        const items = [...data.checklist_items];
        items[i] = { ...items[i], [field]: value };
        setData('checklist_items', items);
    }

    function submit(e) {
        e.preventDefault();
        post(route('hse-inspections.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Record HSE Inspection" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('hse-inspections.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <Card>
                    <CardHeader><CardTitle>Record HSE Inspection -- {inspectionNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Inspection Type</Label>
                                <Select value={data.inspection_type} onValueChange={(v) => setData('inspection_type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{types.map((t) => <SelectItem key={t} value={t} className="capitalize">{t.replace('_', ' ')}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5"><Label>Date</Label><Input type="date" value={data.inspection_date} onChange={(e) => setData('inspection_date', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5"><Label>Location</Label><Input value={data.location} onChange={(e) => setData('location', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Notes (optional)</Label><Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} /></div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Project (optional)</Label>
                                <Select value={data.project_id || 'none'} onValueChange={(v) => setData('project_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="No project" /></SelectTrigger>
                                    <SelectContent><SelectItem value="none">No project</SelectItem>{projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Company</Label>
                                <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                                </Select>
                                {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>Checklist</CardTitle>
                        <Button type="button" variant="outline" size="sm" onClick={() => setData('checklist_items', [...data.checklist_items, { ...BLANK_ITEM }])}><Plus className="h-4 w-4" /> Add Item</Button>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <Table>
                            <TableHeader><TableRow><TableHead className="min-w-[220px]">Item</TableHead><TableHead className="w-32">Result</TableHead><TableHead className="min-w-[220px]">Remarks</TableHead><TableHead /></TableRow></TableHeader>
                            <TableBody>
                                {data.checklist_items.map((item, i) => (
                                    <TableRow key={i}>
                                        <TableCell><Input value={item.item} onChange={(e) => updateItem(i, 'item', e.target.value)} /></TableCell>
                                        <TableCell>
                                            <Select value={item.result} onValueChange={(v) => updateItem(i, 'result', v)}>
                                                <SelectTrigger><SelectValue /></SelectTrigger>
                                                <SelectContent><SelectItem value="ok">OK</SelectItem><SelectItem value="not_ok">Not OK</SelectItem><SelectItem value="na">N/A</SelectItem></SelectContent>
                                            </Select>
                                        </TableCell>
                                        <TableCell><Input value={item.remarks} onChange={(e) => updateItem(i, 'remarks', e.target.value)} /></TableCell>
                                        <TableCell><Button type="button" variant="ghost" size="icon" onClick={() => setData('checklist_items', data.checklist_items.filter((_, idx) => idx !== i))}><Trash2 className="h-4 w-4 text-red-500" /></Button></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Button type="submit" disabled={processing}>Save Inspection</Button>
            </form>
        </AuthenticatedLayout>
    );
}
