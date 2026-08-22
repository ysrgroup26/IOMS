import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import { ArrowLeft } from 'lucide-react';

export default function TbmMeetingShow({ meeting: m, activities }) {
    return (
        <AuthenticatedLayout>
            <Head title={m.tbm_number} />

            <Link href={route('tbm-meetings.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to TBM
            </Link>

            <div className="mb-4">
                <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900">{m.tbm_number}</h1>
                <p className="text-xs text-graphite-500">{m.topic} · {new Date(m.meeting_date).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })}{m.location && ` · ${m.location}`}{m.project && ` · ${m.project.name}`}</p>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            {m.notes && <div><span className="text-xs uppercase text-graphite-400">Notes</span><p className="whitespace-pre-wrap">{m.notes}</p></div>}
                            <div><span className="text-xs uppercase text-graphite-400">Conducted By</span><p>{m.conductor?.name}</p></div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle>Attendees ({m.attendees.length})</CardTitle></CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            {m.attendees.map((a) => <Badge key={a.id} variant="outline">{a.full_name}</Badge>)}
                        </CardContent>
                    </Card>
                </div>
                <Card>
                    <CardHeader><CardTitle>Activity</CardTitle></CardHeader>
                    <CardContent><ActivityTimeline activities={activities} /></CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
