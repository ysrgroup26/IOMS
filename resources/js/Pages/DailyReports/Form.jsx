import { Head, useForm, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Button } from '@/Components/ui/button';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import MultiImageUpload from '@/Components/shared/MultiImageUpload';
import Combobox from '@/Components/shared/Combobox';
import { ArrowLeft, Loader2, Plus, X } from 'lucide-react';

export default function DailyReportForm({ report, projects, departmentSuggestions = [] }) {
    const isEdit = !!report;

    const { data, setData, post, processing, errors, transform } = useForm({
        project_id: report?.project_id ? String(report.project_id) : '',
        department_name: report?.department_name || '',
        report_date: report?.report_date?.slice(0, 10) || new Date().toISOString().slice(0, 10),
        report_type: report?.report_type || 'normal',
        findings: report?.findings || '',
        notes: report?.notes || '',
        activities: report?.activities?.length ? report.activities.map((a) => a.description) : [''],
        photos: [],
    });

    function updateActivity(index, value) {
        const next = [...data.activities];
        next[index] = value;
        setData('activities', next);
    }

    function addActivity() {
        setData('activities', [...data.activities, '']);
    }

    function removeActivity(index) {
        setData('activities', data.activities.filter((_, i) => i !== index));
    }

    function removeExistingPhoto(photoId) {
        if (confirm('Remove this photo? This cannot be undone.')) {
            router.delete(route('daily-reports.photos.destroy', [report.id, photoId]), { preserveScroll: true });
        }
    }

    function submit(e) {
        e.preventDefault();
        const payload = { ...data, activities: data.activities.filter((a) => a.trim() !== '') };

        if (isEdit) {
            transform((d) => ({ ...d, activities: payload.activities, _method: 'put' }));
            post(route('daily-reports.update', report.id), { forceFormData: true });
        } else {
            transform((d) => ({ ...d, activities: payload.activities }));
            post(route('daily-reports.store'), { forceFormData: true });
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title={isEdit ? 'Edit Daily Report' : 'New Daily Report'} />

            <Link href={route('daily-reports.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Daily Reports
            </Link>

            <h1 className="mb-6 text-lg font-bold tracking-tight text-graphite-900">
                {isEdit ? 'Edit Daily Report' : 'New Daily Report'}
            </h1>

            <form onSubmit={submit} className="max-w-2xl space-y-4">
                <Card>
                    <CardHeader><CardTitle>Report Details</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Project" error={errors.project_id}>
                                <Select
                                    value={data.project_id}
                                    onValueChange={(v) => setData('project_id', v)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Select project" /></SelectTrigger>
                                    <SelectContent>
                                        {projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field label="Date" error={errors.report_date}>
                                <Input type="date" value={data.report_date} onChange={(e) => setData('report_date', e.target.value)} />
                            </Field>
                        </div>

                        <Field label="Department" error={errors.department_name}>
                            <Combobox
                                value={data.department_name}
                                onChange={(v) => setData('department_name', v)}
                                suggestions={departmentSuggestions}
                                placeholder="e.g. Operations"
                            />
                        </Field>

                        <Field label="Report Type" error={errors.report_type}>
                            <Select value={data.report_type} onValueChange={(v) => setData('report_type', v)}>
                                <SelectTrigger className="w-48"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="normal">Normal</SelectItem>
                                    <SelectItem value="overtime">Overtime</SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Activities</CardTitle>
                        <CardDescription>e.g. Blasting supervision, Bottom cutting supervision, Lifting supervision</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {data.activities.map((activity, i) => (
                            <div key={i} className="flex items-center gap-2">
                                <Input
                                    value={activity}
                                    onChange={(e) => updateActivity(i, e.target.value)}
                                    placeholder="e.g. Welding supervision"
                                />
                                {data.activities.length > 1 && (
                                    <Button type="button" variant="ghost" size="icon" onClick={() => removeActivity(i)}>
                                        <X className="h-4 w-4 text-graphite-400" />
                                    </Button>
                                )}
                            </div>
                        ))}
                        {errors.activities && <p className="text-xs text-red-600">{errors.activities}</p>}
                        <Button type="button" variant="outline" size="sm" onClick={addActivity}>
                            <Plus className="h-4 w-4" /> Add Activity
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Findings &amp; Notes</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <Field label="Findings" error={errors.findings}>
                            <textarea
                                className="flex w-full rounded-lg border border-input bg-white px-3 py-2 text-sm shadow-sm placeholder:text-graphite-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1"
                                rows={3}
                                value={data.findings}
                                onChange={(e) => setData('findings', e.target.value)}
                                placeholder="Any findings from today..."
                            />
                        </Field>
                        <Field label="Notes" error={errors.notes}>
                            <textarea
                                className="flex w-full rounded-lg border border-input bg-white px-3 py-2 text-sm shadow-sm placeholder:text-graphite-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1"
                                rows={2}
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                            />
                        </Field>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Documentation</CardTitle><CardDescription>Upload supporting photos (optional).</CardDescription></CardHeader>
                    <CardContent>
                        <MultiImageUpload
                            label=""
                            existingImages={isEdit ? (report.photos || []).map((p) => ({ id: p.id, url: p.url })) : []}
                            onRemoveExisting={isEdit ? removeExistingPhoto : undefined}
                            files={data.photos}
                            onFilesChange={(files) => setData('photos', files)}
                            error={errors.photos}
                        />
                    </CardContent>
                </Card>

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" asChild>
                        <Link href={route('daily-reports.index')}>Cancel</Link>
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                        {isEdit ? 'Save Changes' : 'Submit Report'}
                    </Button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}

function Field({ label, error, children }) {
    return (
        <div className="space-y-1.5">
            <Label>{label}</Label>
            {children}
            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    );
}
