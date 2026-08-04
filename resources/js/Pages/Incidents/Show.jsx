import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import { ArrowLeft, Search, CheckCircle2 } from 'lucide-react';

export default function IncidentShow({ incident: i, activities, canManage }) {
    function transition(status, confirmMessage) {
        if (!confirm(confirmMessage)) return;
        router.post(route('incidents.transition', i.id), { status });
    }

    return (
        <AuthenticatedLayout>
            <Head title={i.incident_number} />

            <Link href={route('incidents.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Incident Management
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-lg font-bold tracking-tight text-graphite-900">
                        {i.incident_number}
                        <StatusBadge value={i.severity} />
                        <StatusBadge value={i.status} />
                    </h1>
                    <p className="text-xs text-graphite-500">
                        {i.title} · {new Date(i.incident_date).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })}
                        {i.location && ` · ${i.location}`}
                        {i.project && ` · ${i.project.name}`}
                    </p>
                </div>
                {canManage && (
                    <div className="flex items-center gap-2">
                        {i.status === 'reported' && (
                            <Button variant="outline" onClick={() => transition('investigating', 'Start investigating this incident?')}>
                                <Search className="h-4 w-4" /> Start Investigation
                            </Button>
                        )}
                        {i.status !== 'closed' && (
                            <Button onClick={() => transition('closed', 'Close this incident?')}>
                                <CheckCircle2 className="h-4 w-4" /> Close
                            </Button>
                        )}
                    </div>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Category</span><p className="capitalize">{i.category.replace('_', ' ')}</p></div>
                        <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Description</span><p className="whitespace-pre-wrap">{i.description || '-'}</p></div>
                        <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Reported By</span><p>{i.reporter?.name}</p></div>
                        {i.company && <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Company</span><p>{i.company.name}</p></div>}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Activity</CardTitle></CardHeader>
                    <CardContent><ActivityTimeline activities={activities} /></CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
