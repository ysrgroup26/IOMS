import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft } from 'lucide-react';

export default function InspectionRequestForm({ projects, activities, inspectionNumber }) {
    const { data, setData, post, processing, errors } = useForm({
        project_id: '', project_activity_id: '', inspection_date: new Date().toISOString().slice(0, 10),
    });

    const filteredActivities = activities.filter((a) => String(a.project_id) === data.project_id);

    function submit(e) {
        e.preventDefault();
        post(route('inspection-requests.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Request QC Inspection" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('inspection-requests.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-xl">
                <Card>
                    <CardHeader><CardTitle>Request QC Inspection -- {inspectionNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Project</Label>
                            <Select value={data.project_id} onValueChange={(v) => setData({ ...data, project_id: v, project_activity_id: '' })}>
                                <SelectTrigger><SelectValue placeholder="Select project" /></SelectTrigger>
                                <SelectContent>{projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                            </Select>
                            {errors.project_id && <p className="text-xs text-red-600">{errors.project_id}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Activity (optional)</Label>
                            <Select value={data.project_activity_id || 'none'} onValueChange={(v) => setData('project_activity_id', v === 'none' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                <SelectContent><SelectItem value="none">None</SelectItem>{filteredActivities.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5"><Label>Date</Label><Input type="date" value={data.inspection_date} onChange={(e) => setData('inspection_date', e.target.value)} /></div>
                        <Button type="submit" disabled={processing} className="w-full">Request Inspection</Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
