import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Button } from '@/Components/ui/button';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft, Loader2 } from 'lucide-react';

export default function ProjectForm({ project, companies }) {
    const isEdit = !!project;

    const { data, setData, post, put, processing, errors } = useForm({
        company_id: project?.company_id ? String(project.company_id) : undefined,
        name: project?.name || '',
        vessel_name: project?.vessel_name || '',
        start_date: project?.start_date?.slice(0, 10) || '',
        end_date: project?.end_date?.slice(0, 10) || '',
        status: project?.status || 'planned',
        description: project?.description || '',
    });

    function submit(e) {
        e.preventDefault();
        if (isEdit) {
            put(route('projects.update', project.id));
        } else {
            post(route('projects.store'));
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title={isEdit ? 'Edit Project' : 'Add Project'} />

            <Link href={route('projects.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Projects
            </Link>

            <h1 className="mb-6 text-lg font-bold tracking-tight text-graphite-900">
                {isEdit ? 'Edit Project' : 'Add Project'}
            </h1>

            <Card className="max-w-2xl">
                <CardHeader><CardTitle>Project Information</CardTitle></CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Project Name" error={errors.name}>
                                <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Shutdown Maintenance" />
                            </Field>
                            <Field label="Company" error={errors.company_id}>
                                <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                    <SelectTrigger><SelectValue placeholder="Select company" /></SelectTrigger>
                                    <SelectContent>
                                        {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </Field>
                        </div>

                        <Field label="Location" error={errors.vessel_name}>
                            <Input value={data.vessel_name} onChange={(e) => setData('vessel_name', e.target.value)} placeholder="e.g. Area A" />
                        </Field>

                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Start Date" error={errors.start_date}>
                                <Input type="date" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} />
                            </Field>
                            <Field label="End Date" error={errors.end_date}>
                                <Input type="date" value={data.end_date} onChange={(e) => setData('end_date', e.target.value)} />
                            </Field>
                        </div>

                        <Field label="Status" error={errors.status}>
                            <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="planned">Planned</SelectItem>
                                    <SelectItem value="ongoing">Ongoing</SelectItem>
                                    <SelectItem value="completed">Completed</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>

                        <Field label="Description" error={errors.description}>
                            <textarea
                                className="flex w-full rounded-lg border border-input bg-white px-3 py-2 text-sm shadow-sm placeholder:text-graphite-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1"
                                rows={3}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder="Brief notes about this project..."
                            />
                        </Field>

                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline" asChild>
                                <Link href={route('projects.index')}>Cancel</Link>
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                                {isEdit ? 'Save Changes' : 'Create Project'}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
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
