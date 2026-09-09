import { useRef } from 'react';
import { Head, useForm, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { FormSection, FormField, FormActions, ErrorSummary, SearchableSelect } from '@/Components/shared/form';
import { useUnsavedChanges } from '@/lib/useUnsavedChanges';
import { ArrowLeft, FolderKanban, CalendarClock } from 'lucide-react';

/**
 * v2.66.0 -- master data rollout.
 *
 * A SHORT FORM THAT DID NOT NEED MUCH, and this is the case worth being
 * explicit about: seven fields is not a structural problem, so almost
 * nothing here is restructured. What it gained is the contract -- required
 * marking that matches the server (company_id, name and status; NOT the
 * dates, which are nullable), errors that can be found, an action bar
 * reachable on a phone, and protection for work in progress.
 *
 * Two sections rather than one only because the dates answer a genuinely
 * different question from the identity fields, and `end_date` carries a
 * server rule (`after_or_equal:start_date`) that is worth stating in a
 * hint before somebody trips over it rather than after.
 *
 * `description` was a single-line Input for a field the server allows 2000
 * characters in. It is a Textarea now.
 */

const ERROR_LABELS = {
    company_id: 'Operating Unit',
    vessel_name: 'Vessel',
    start_date: 'Start date',
    end_date: 'End date',
};

export default function ProjectForm({ project, companies }) {
    const isEdit = !!project;
    const initial = useRef(null);

    const { data, setData, post, put, processing, errors } = useForm({
        company_id: project?.company_id ? String(project.company_id) : undefined,
        name: project?.name || '',
        vessel_name: project?.vessel_name || '',
        start_date: project?.start_date?.slice(0, 10) || '',
        end_date: project?.end_date?.slice(0, 10) || '',
        status: project?.status || 'planned',
        description: project?.description || '',
    });

    if (initial.current === null) initial.current = { ...data };

    const { release } = useUnsavedChanges(data, initial.current, !processing);

    function submit(e) {
        e.preventDefault();
        release();
        if (isEdit) {
            put(route('projects.update', project.id));
        } else {
            post(route('projects.store'));
        }
    }

    function cancel() {
        release();
        router.visit(route('projects.index'));
    }

    return (
        <AuthenticatedLayout>
            <Head title={isEdit ? 'Edit Project' : 'Add Project'} />

            <Link href={route('projects.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Projects
            </Link>

            <h1 className="mb-1 text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-100">
                {isEdit ? 'Edit Project' : 'Add Project'}
            </h1>
            <p className="mb-6 text-sm text-graphite-500 dark:text-slate-400">
                Permits, material requests, daily reports and manpower assignments are charged to a project.
            </p>

            <form onSubmit={submit} className="max-w-3xl space-y-6">
                <ErrorSummary errors={errors} labels={ERROR_LABELS} />

                <FormSection
                    title="Identity"
                    description="What the project is, and which Operating Unit is running it."
                    icon={FolderKanban}
                >
                    <FormField label="Project name" name="name" required error={errors.name} className="sm:col-span-2">
                        {(control) => (
                            <Input {...control} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Tanker drydocking — MT Sinar" />
                        )}
                    </FormField>

                    <FormField label="Operating Unit" name="company_id" required error={errors.company_id}>
                        {(control) => (
                            <SearchableSelect
                                {...control}
                                value={data.company_id ?? ''}
                                onChange={(v) => setData('company_id', v)}
                                options={companies.map((c) => ({ value: c.id, label: c.name }))}
                                placeholder="Select operating unit"
                            />
                        )}
                    </FormField>

                    <FormField label="Status" name="status" required error={errors.status}>
                        {(control) => (
                            <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                <SelectTrigger {...control}><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="planned">Planned</SelectItem>
                                    <SelectItem value="ongoing">Ongoing</SelectItem>
                                    <SelectItem value="completed">Completed</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                            </Select>
                        )}
                    </FormField>

                    <FormField
                        label="Vessel"
                        name="vessel_name"
                        error={errors.vessel_name}
                        hint="For marine and shipyard work. Leave blank for shore-based projects."
                        className="sm:col-span-2"
                    >
                        {(control) => (
                            <Input {...control} value={data.vessel_name} onChange={(e) => setData('vessel_name', e.target.value)} />
                        )}
                    </FormField>

                    <FormField label="Description" name="description" error={errors.description} className="sm:col-span-2">
                        {(control) => (
                            <Textarea {...control} value={data.description} onChange={(e) => setData('description', e.target.value)} rows={3} />
                        )}
                    </FormField>
                </FormSection>

                <FormSection title="Schedule" description="When the work is expected to run." icon={CalendarClock}>
                    <FormField label="Start date" name="start_date" error={errors.start_date}>
                        {(control) => (
                            <Input {...control} type="date" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} />
                        )}
                    </FormField>

                    <FormField
                        label="End date"
                        name="end_date"
                        error={errors.end_date}
                        hint="Must be on or after the start date."
                    >
                        {(control) => (
                            <Input {...control} type="date" min={data.start_date || undefined} value={data.end_date} onChange={(e) => setData('end_date', e.target.value)} />
                        )}
                    </FormField>
                </FormSection>

                <FormActions
                    submitLabel={isEdit ? 'Save changes' : 'Create project'}
                    onCancel={cancel}
                    processing={processing}
                />
            </form>
        </AuthenticatedLayout>
    );
}
