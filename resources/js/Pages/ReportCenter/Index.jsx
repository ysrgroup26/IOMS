import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { FileText, FileSpreadsheet, FileDown, Eye, Clock, Trash2, CalendarClock } from 'lucide-react';

/**
 * Report Center (Milestone 3, Task #65). A generic renderer over
 * whatever the Analytics Framework's dataset registry currently exposes
 * (config/analytics.php) -- Preview + PDF/Excel/CSV download + Scheduled
 * Report, all real: Preview fetches the exact same data a download would
 * contain (App\Services\AnalyticsService::dataset()), and Scheduled
 * Report rows are genuine `report_schedules` records a real Artisan
 * command (reports:dispatch-scheduled, hourly) picks up and notifies the
 * owner about through the Notification Center.
 */
export default function ReportCenterIndex({ available, schedules }) {
    const [previewKey, setPreviewKey] = useState(null);
    const [previewData, setPreviewData] = useState(null);
    const [previewLoading, setPreviewLoading] = useState(false);

    const scheduleForm = useForm({ dataset_key: available?.[0]?.key ?? '', format: 'csv', frequency: 'weekly' });

    const openPreview = async (key) => {
        setPreviewKey(key);
        setPreviewLoading(true);
        setPreviewData(null);
        try {
            const res = await fetch(route('report-center.preview', key));
            setPreviewData(await res.json());
        } finally {
            setPreviewLoading(false);
        }
    };

    const submitSchedule = (e) => {
        e.preventDefault();
        scheduleForm.post(route('report-center.schedules.store'), { preserveScroll: true });
    };

    // v2.12.0 (Product Finalization pass, Part 19 -- destructive action
    // confirmation): fired instantly on click with no confirmation --
    // every other delete flow in this app confirms first.
    const deleteSchedule = (id) => {
        if (confirm('Hapus jadwal laporan ini?')) {
            router.delete(route('report-center.schedules.destroy', id), { preserveScroll: true });
        }
    };

    if (!available || available.length === 0) {
        return (
            <AuthenticatedLayout>
                <Head title="Report Center" />
                <PageHeader title="Report Center" subtitle="Download and schedule reports across every module." />
                <Card>
                    <CardContent>
                        <EmptyState icon={FileText} title="No reports available yet" description="Reports appear here once their module is enabled." />
                    </CardContent>
                </Card>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout>
            <Head title="Report Center" />

            <PageHeader
                title="Report Center"
                subtitle="Preview, download (PDF / Excel / CSV), and schedule any registered report dataset."
            />

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                {available.map(({ key, label }) => (
                    <Card key={key}>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">{label}</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" onClick={() => openPreview(key)} className="gap-1.5">
                                <Eye className="h-3.5 w-3.5" /> Preview
                            </Button>
                            <Button variant="outline" size="sm" asChild className="gap-1.5">
                                <a href={route('report-center.export.pdf', key)}><FileText className="h-3.5 w-3.5" /> PDF</a>
                            </Button>
                            <Button variant="outline" size="sm" asChild className="gap-1.5">
                                <a href={route('report-center.export.excel', key)}><FileSpreadsheet className="h-3.5 w-3.5" /> Excel</a>
                            </Button>
                            <Button variant="outline" size="sm" asChild className="gap-1.5">
                                <a href={route('report-center.export.csv', key)}><FileDown className="h-3.5 w-3.5" /> CSV</a>
                            </Button>
                        </CardContent>
                    </Card>
                ))}
            </div>

            {previewKey && (
                <Card className="mt-5">
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <div>
                            <CardTitle className="text-base">Preview -- {available.find((a) => a.key === previewKey)?.label}</CardTitle>
                            <CardDescription>Live query result, identical to what a download would contain.</CardDescription>
                        </div>
                        <Button variant="ghost" size="sm" onClick={() => setPreviewKey(null)}>Close</Button>
                    </CardHeader>
                    <CardContent>
                        {previewLoading && <p className="text-sm text-graphite-400">Loading…</p>}
                        {!previewLoading && previewData && (
                            (!previewData.labels || previewData.labels.length === 0) ? (
                                <p className="text-sm text-graphite-400">No data yet.</p>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-graphite-100 text-left text-xs text-graphite-400 dark:border-slate-800">
                                            <th className="py-1.5">Category</th>
                                            <th className="py-1.5 text-right">Count</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {previewData.labels.map((l, i) => (
                                            <tr key={l} className="border-b border-graphite-50 dark:border-slate-900">
                                                <td className="py-1.5">{l}</td>
                                                <td className="py-1.5 text-right">{previewData.values[i]}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )
                        )}
                    </CardContent>
                </Card>
            )}

            <Card className="mt-5">
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base"><CalendarClock className="h-4 w-4 text-graphite-400" /> Scheduled Reports</CardTitle>
                    <CardDescription>Runs automatically; you'll get a notification when a report is ready.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submitSchedule} className="mb-4 flex flex-wrap items-end gap-3">
                        <div className="min-w-[200px]">
                            <label className="mb-1 block text-xs font-medium text-graphite-500 dark:text-slate-400">Dataset</label>
                            <Select value={scheduleForm.data.dataset_key} onValueChange={(v) => scheduleForm.setData('dataset_key', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {available.map((a) => <SelectItem key={a.key} value={a.key}>{a.label}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-graphite-500 dark:text-slate-400">Format</label>
                            <Select value={scheduleForm.data.format} onValueChange={(v) => scheduleForm.setData('format', v)}>
                                <SelectTrigger className="w-32"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="csv">CSV</SelectItem>
                                    <SelectItem value="excel">Excel</SelectItem>
                                    <SelectItem value="pdf">PDF</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-graphite-500 dark:text-slate-400">Frequency</label>
                            <Select value={scheduleForm.data.frequency} onValueChange={(v) => scheduleForm.setData('frequency', v)}>
                                <SelectTrigger className="w-32"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="daily">Daily</SelectItem>
                                    <SelectItem value="weekly">Weekly</SelectItem>
                                    <SelectItem value="monthly">Monthly</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <Button type="submit" size="sm" disabled={scheduleForm.processing}>Add Schedule</Button>
                    </form>

                    {schedules.length === 0 ? (
                        <p className="py-4 text-center text-xs text-graphite-400 dark:text-slate-500">No scheduled reports yet.</p>
                    ) : (
                        <div className="space-y-1.5">
                            {schedules.map((s) => (
                                <div key={s.id} className="flex items-center justify-between gap-3 rounded-lg border border-graphite-100 px-3 py-2 dark:border-slate-800">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium text-graphite-800 dark:text-slate-100">
                                            {available.find((a) => a.key === s.dataset_key)?.label ?? s.dataset_key}
                                        </p>
                                        <p className="mt-0.5 flex items-center gap-1.5 text-xs text-graphite-400 dark:text-slate-500">
                                            <Clock className="h-3 w-3" />
                                            {s.frequency} &middot; {s.format.toUpperCase()}
                                            {s.next_run_at && <> &middot; next {new Date(s.next_run_at).toLocaleString()}</>}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        <Badge variant={s.is_active ? 'default' : 'secondary'}>{s.is_active ? 'Active' : 'Inactive'}</Badge>
                                        <Button variant="ghost" size="icon" onClick={() => deleteSchedule(s.id)}>
                                            <Trash2 className="h-3.5 w-3.5 text-red-500" />
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>
        </AuthenticatedLayout>
    );
}
