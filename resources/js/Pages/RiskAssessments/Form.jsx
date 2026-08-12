import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { assessRisk } from '@/lib/riskMatrix';

const BLANK_ITEM = { activity: '', hazard: '', existing_control: '', likelihood: 1, severity: 1, additional_control: '', residual_likelihood: 1, residual_severity: 1, pic: '', target_date: '' };

/**
 * v1.10.9: risk_rating/residual_rating were always PART of this item's
 * intended shape (see the owning migration's own doc comment) but never
 * actually computed or shown anywhere in this form -- audited and
 * confirmed via a repo-wide search before writing this file, not
 * assumed. Uses the SAME shared `assessRisk()` JSA now uses too -- one
 * risk matrix, not two.
 */
function RiskBadge({ likelihood, severity }) {
    const { score, label, badge } = assessRisk(likelihood, severity);

    return <Badge variant={badge}>{score ?? '-'} {label !== '-' && `· ${label}`}</Badge>;
}

export default function RiskAssessmentForm({ riskAssessment, companies, projects, raNumber }) {
    const editing = !!riskAssessment;
    const { data, setData, post, put, processing, errors } = useForm({
        company_id: editing ? String(riskAssessment.company_id) : (companies[0]?.id ? String(companies[0].id) : ''),
        project_id: editing && riskAssessment.project_id ? String(riskAssessment.project_id) : '',
        title: riskAssessment?.title || '',
        location: riskAssessment?.location || '',
        assessment_date: riskAssessment?.assessment_date?.slice(0, 10) || new Date().toISOString().slice(0, 10),
        items: riskAssessment?.items?.length ? riskAssessment.items : [{ ...BLANK_ITEM }],
    });

    function updateItem(i, field, value) {
        const items = [...data.items];
        items[i] = { ...items[i], [field]: value };
        setData('items', items);
    }

    function addItem() {
        setData('items', [...data.items, { ...BLANK_ITEM }]);
    }

    function removeItem(i) {
        setData('items', data.items.filter((_, idx) => idx !== i));
    }

    function submit(e) {
        e.preventDefault();
        if (editing) {
            put(route('risk-assessments.update', riskAssessment.id));
        } else {
            post(route('risk-assessments.store'));
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title={editing ? riskAssessment.ra_number : 'New HIRADC'} />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('risk-assessments.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <Card>
                    <CardHeader><CardTitle>{editing ? riskAssessment.ra_number : `New HIRADC -- ${raNumber}`}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Title</Label>
                            <Input value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="e.g. Working at Height -- Tank Fabrication" />
                            {errors.title && <p className="text-xs text-red-600">{errors.title}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Assessment Date</Label>
                                <Input type="date" value={data.assessment_date} onChange={(e) => setData('assessment_date', e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Location</Label>
                                <Input value={data.location} onChange={(e) => setData('location', e.target.value)} />
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Company</Label>
                                <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                                </Select>
                                {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Project (optional)</Label>
                                <Select value={data.project_id || 'none'} onValueChange={(v) => setData('project_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="No project" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">No project</SelectItem>
                                        {projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>Hazard / Risk / Control</CardTitle>
                        <Button type="button" variant="outline" size="sm" onClick={addItem}><Plus className="h-4 w-4" /> Add Row</Button>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="min-w-[140px]">Activity</TableHead>
                                    <TableHead className="min-w-[140px]">Hazard</TableHead>
                                    <TableHead className="min-w-[140px]">Existing Control</TableHead>
                                    <TableHead className="w-14">L</TableHead>
                                    <TableHead className="w-14">S</TableHead>
                                    <TableHead className="min-w-[110px]">Initial Risk</TableHead>
                                    <TableHead className="min-w-[140px]">Additional Control</TableHead>
                                    <TableHead className="w-14">Res. L</TableHead>
                                    <TableHead className="w-14">Res. S</TableHead>
                                    <TableHead className="min-w-[110px]">Residual Risk</TableHead>
                                    <TableHead className="min-w-[120px]">PIC</TableHead>
                                    <TableHead className="min-w-[130px]">Target Date</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.items.map((item, i) => (
                                    <TableRow key={i}>
                                        <TableCell><Input value={item.activity} onChange={(e) => updateItem(i, 'activity', e.target.value)} /></TableCell>
                                        <TableCell><Input value={item.hazard} onChange={(e) => updateItem(i, 'hazard', e.target.value)} /></TableCell>
                                        <TableCell><Input value={item.existing_control} onChange={(e) => updateItem(i, 'existing_control', e.target.value)} /></TableCell>
                                        <TableCell><Input type="number" min="1" max="5" className="w-14" value={item.likelihood} onChange={(e) => updateItem(i, 'likelihood', e.target.value)} /></TableCell>
                                        <TableCell><Input type="number" min="1" max="5" className="w-14" value={item.severity} onChange={(e) => updateItem(i, 'severity', e.target.value)} /></TableCell>
                                        <TableCell><RiskBadge likelihood={item.likelihood} severity={item.severity} /></TableCell>
                                        <TableCell><Input value={item.additional_control} onChange={(e) => updateItem(i, 'additional_control', e.target.value)} /></TableCell>
                                        <TableCell><Input type="number" min="1" max="5" className="w-14" value={item.residual_likelihood} onChange={(e) => updateItem(i, 'residual_likelihood', e.target.value)} /></TableCell>
                                        <TableCell><Input type="number" min="1" max="5" className="w-14" value={item.residual_severity} onChange={(e) => updateItem(i, 'residual_severity', e.target.value)} /></TableCell>
                                        <TableCell><RiskBadge likelihood={item.residual_likelihood} severity={item.residual_severity} /></TableCell>
                                        <TableCell><Input value={item.pic} onChange={(e) => updateItem(i, 'pic', e.target.value)} /></TableCell>
                                        <TableCell><Input type="date" value={item.target_date || ''} onChange={(e) => updateItem(i, 'target_date', e.target.value)} /></TableCell>
                                        <TableCell><Button type="button" variant="ghost" size="icon" onClick={() => removeItem(i)}><Trash2 className="h-4 w-4 text-red-500" /></Button></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Button type="submit" disabled={processing}>{editing ? 'Save Changes' : 'Create HIRADC'}</Button>
            </form>
        </AuthenticatedLayout>
    );
}
