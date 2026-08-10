import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Checkbox } from '@/Components/ui/checkbox';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft } from 'lucide-react';

export default function TbmMeetingForm({ companies, projects, employees, tbmNumber }) {
    const { data, setData, post, processing, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        project_id: '',
        topic: '',
        meeting_date: new Date().toISOString().slice(0, 10),
        location: '',
        notes: '',
        attendee_ids: [],
    });

    function toggleAttendee(id) {
        setData('attendee_ids', data.attendee_ids.includes(id) ? data.attendee_ids.filter((x) => x !== id) : [...data.attendee_ids, id]);
    }

    function submit(e) {
        e.preventDefault();
        post(route('tbm-meetings.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Record TBM" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('tbm-meetings.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-xl">
                <Card>
                    <CardHeader><CardTitle>Record TBM -- {tbmNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Topic</Label>
                            <Input value={data.topic} onChange={(e) => setData('topic', e.target.value)} placeholder="e.g. Housekeeping and Manual Handling" />
                            {errors.topic && <p className="text-xs text-red-600">{errors.topic}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5"><Label>Date</Label><Input type="date" value={data.meeting_date} onChange={(e) => setData('meeting_date', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Location</Label><Input value={data.location} onChange={(e) => setData('location', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Notes (optional)</Label>
                            <Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} />
                        </div>
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

                        <div className="space-y-1.5">
                            <Label>Attendees ({data.attendee_ids.length} selected)</Label>
                            <div className="max-h-64 overflow-y-auto rounded-md border border-graphite-200 p-2">
                                {employees.map((e) => (
                                    <label key={e.id} className="flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-graphite-50">
                                        <Checkbox checked={data.attendee_ids.includes(e.id)} onCheckedChange={() => toggleAttendee(e.id)} />
                                        {e.full_name}
                                    </label>
                                ))}
                            </div>
                        </div>

                        <Button type="submit" disabled={processing} className="w-full">Save TBM</Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
