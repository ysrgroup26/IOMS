import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { ArrowLeft, Image as ImageIcon, FileWarning } from 'lucide-react';

export default function InspectionRequestShow({ inspection: i, canManage }) {
    const resultForm = useForm({ result: 'passed', notes: '', photos: [] });

    function submit(e) {
        e.preventDefault();
        resultForm.post(route('inspection-requests.result', i.id), { forceFormData: true, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title={i.inspection_number} />

            <Link href={route('inspection-requests.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Inspection Requests
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-lg font-bold tracking-tight text-graphite-900">
                        {i.inspection_number}<StatusBadge value={i.status} />
                        {i.result && <StatusBadge value={i.result === 'passed' ? 'approved' : 'rejected'} label={i.result} />}
                    </h1>
                    <p className="text-xs text-graphite-500">{i.project?.name}{i.activity && ` · ${i.activity.name}`} · {new Date(i.inspection_date).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })}</p>
                </div>
                {canManage && i.result === 'failed' && (
                    <Button variant="outline" asChild><Link href={route('ncrs.create', { inspection: i.id })}><FileWarning className="h-4 w-4" /> Raise NCR</Link></Button>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {canManage && i.status === 'requested' && (
                        <Card>
                            <CardHeader><CardTitle>Record Result</CardTitle></CardHeader>
                            <CardContent>
                                <form onSubmit={submit} className="space-y-3">
                                    <Select value={resultForm.data.result} onValueChange={(v) => resultForm.setData('result', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent><SelectItem value="passed">Passed</SelectItem><SelectItem value="failed">Failed</SelectItem></SelectContent>
                                    </Select>
                                    <Textarea placeholder="Notes" rows={3} value={resultForm.data.notes} onChange={(e) => resultForm.setData('notes', e.target.value)} />
                                    <div className="space-y-1"><Label>Evidence Photos</Label><Input type="file" multiple accept="image/*" onChange={(e) => resultForm.setData('photos', Array.from(e.target.files))} /></div>
                                    <Button type="submit" disabled={resultForm.processing}>Save Result</Button>
                                </form>
                            </CardContent>
                        </Card>
                    )}

                    {i.status === 'completed' && (
                        <Card>
                            <CardHeader><CardTitle>Result</CardTitle></CardHeader>
                            <CardContent className="text-sm">
                                <p className="whitespace-pre-wrap">{i.notes || '-'}</p>
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader className="flex flex-row items-center gap-2"><ImageIcon className="h-4 w-4 text-graphite-400" /><CardTitle>Evidence</CardTitle></CardHeader>
                        <CardContent>
                            {(!i.evidence || i.evidence.length === 0) ? (
                                <EmptyState icon={ImageIcon} title="No evidence photos" />
                            ) : (
                                <div className="grid grid-cols-3 gap-3 sm:grid-cols-4">
                                    {i.evidence.map((e) => (
                                        <a key={e.id} href={e.url} target="_blank" rel="noreferrer" className="aspect-square overflow-hidden rounded-lg border border-graphite-200">
                                            <img src={e.url} alt="" className="h-full w-full object-cover" />
                                        </a>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
