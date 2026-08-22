import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { ArrowLeft, Pencil, Trash2, Calendar, FolderKanban, ListChecks } from 'lucide-react';

export default function DailyReportShow({ report, can }) {
    function destroy() {
        if (confirm('Delete this daily report? This cannot be undone.')) {
            router.delete(route('daily-reports.destroy', report.id));
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title={`Daily Report — ${report.project.name}`} />

            <Link href={route('daily-reports.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Daily Reports
            </Link>

            <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="text-2xl font-bold tracking-tight text-graphite-900">
                            {new Date(report.report_date).toLocaleDateString('en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}
                        </h1>
                        <Badge variant={report.report_type === 'overtime' ? 'secondary' : 'outline'} className="capitalize">{report.report_type}</Badge>
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-graphite-500">
                        <span className="flex items-center gap-1"><FolderKanban className="h-3.5 w-3.5" /> {report.project.name}</span>
                        <span>{report.project.company?.name}</span>
                        <span>Department: {report.department_name}</span>
                    </div>
                </div>
                {can.manage && (
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={route('daily-reports.edit', report.id)}><Pencil className="h-4 w-4" /> Edit</Link>
                        </Button>
                        <Button variant="destructive" onClick={destroy}>
                            <Trash2 className="h-4 w-4" /> Delete
                        </Button>
                    </div>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader><CardTitle className="flex items-center gap-2"><ListChecks className="h-4 w-4" /> Activities</CardTitle></CardHeader>
                        <CardContent>
                            <ul className="space-y-2">
                                {report.activities.map((a) => (
                                    <li key={a.id} className="flex items-start gap-2 text-sm text-graphite-700">
                                        <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-primary" />
                                        {a.description}
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>

                    {report.findings && (
                        <Card>
                            <CardHeader><CardTitle>Findings</CardTitle></CardHeader>
                            <CardContent className="text-sm text-graphite-600">{report.findings}</CardContent>
                        </Card>
                    )}

                    {report.notes && (
                        <Card>
                            <CardHeader><CardTitle>Notes</CardTitle></CardHeader>
                            <CardContent className="text-sm text-graphite-600">{report.notes}</CardContent>
                        </Card>
                    )}

                    {report.photos.length > 0 && (
                        <Card>
                            <CardHeader><CardTitle>Documentation</CardTitle></CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                    {report.photos.map((p) => (
                                        <a key={p.id} href={p.url} target="_blank" rel="noreferrer">
                                            <img
                                                src={p.url}
                                                alt={p.caption || 'Report documentation'}
                                                className="aspect-square w-full rounded-lg border border-graphite-100 object-cover transition-opacity hover:opacity-90"
                                            />
                                        </a>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>

                <Card className="h-fit">
                    <CardHeader><CardTitle className="flex items-center gap-2"><Calendar className="h-4 w-4" /> Report Info</CardTitle></CardHeader>
                    <CardContent className="space-y-2.5 text-sm">
                        <InfoRow label="Project" value={report.project.name} />
                        <InfoRow label="Company" value={report.project.company?.name} />
                        <InfoRow label="Department" value={report.department_name} />
                        <InfoRow label="Type" value={report.report_type} capitalize />
                        <InfoRow label="Activities" value={`${report.activities.length} logged`} />
                        <InfoRow label="Photos" value={`${report.photos.length} attached`} />
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

function InfoRow({ label, value, capitalize }) {
    return (
        <div className="flex items-center justify-between border-b border-graphite-50 pb-2 last:border-0">
            <span className="text-graphite-400">{label}</span>
            <span className={`font-medium text-graphite-800 ${capitalize ? 'capitalize' : ''}`}>{value}</span>
        </div>
    );
}
