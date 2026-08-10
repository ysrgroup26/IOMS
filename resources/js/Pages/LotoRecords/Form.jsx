import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';

const BLANK_POINT = { equipment: '', type: 'electrical', tag_number: '', location: '' };

export default function LotoRecordForm({ companies, permits, lotoNumber }) {
    const { data, setData, post, processing, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        permit_to_work_id: '',
        equipment_name: '',
        applied_at: new Date().toISOString().slice(0, 16),
        isolation_points: [{ ...BLANK_POINT }],
    });

    function updatePoint(i, field, value) {
        const points = [...data.isolation_points];
        points[i] = { ...points[i], [field]: value };
        setData('isolation_points', points);
    }

    function submit(e) {
        e.preventDefault();
        post(route('loto-records.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Apply LOTO" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('loto-records.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-xl">
                <Card>
                    <CardHeader><CardTitle>Apply LOTO -- {lotoNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Equipment Name</Label>
                            <Input value={data.equipment_name} onChange={(e) => setData('equipment_name', e.target.value)} />
                            {errors.equipment_name && <p className="text-xs text-red-600">{errors.equipment_name}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Applied At</Label>
                            <Input type="datetime-local" value={data.applied_at} onChange={(e) => setData('applied_at', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Linked Permit To Work (optional)</Label>
                            <Select value={data.permit_to_work_id || 'none'} onValueChange={(v) => setData('permit_to_work_id', v === 'none' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                <SelectContent><SelectItem value="none">None</SelectItem>{permits.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.ptw_number}</SelectItem>)}</SelectContent>
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

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label>Isolation Points</Label>
                                <Button type="button" variant="outline" size="sm" onClick={() => setData('isolation_points', [...data.isolation_points, { ...BLANK_POINT }])}><Plus className="h-4 w-4" /> Add</Button>
                            </div>
                            <Table>
                                <TableHeader><TableRow><TableHead>Equipment</TableHead><TableHead>Type</TableHead><TableHead>Tag #</TableHead><TableHead>Location</TableHead><TableHead /></TableRow></TableHeader>
                                <TableBody>
                                    {data.isolation_points.map((point, i) => (
                                        <TableRow key={i}>
                                            <TableCell><Input value={point.equipment} onChange={(e) => updatePoint(i, 'equipment', e.target.value)} /></TableCell>
                                            <TableCell><Input value={point.type} onChange={(e) => updatePoint(i, 'type', e.target.value)} /></TableCell>
                                            <TableCell><Input value={point.tag_number} onChange={(e) => updatePoint(i, 'tag_number', e.target.value)} /></TableCell>
                                            <TableCell><Input value={point.location} onChange={(e) => updatePoint(i, 'location', e.target.value)} /></TableCell>
                                            <TableCell><Button type="button" variant="ghost" size="icon" onClick={() => setData('isolation_points', data.isolation_points.filter((_, idx) => idx !== i))}><Trash2 className="h-4 w-4 text-red-500" /></Button></TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>

                        <Button type="submit" disabled={processing} className="w-full">Apply LOTO</Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
