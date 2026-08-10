import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft } from 'lucide-react';

export default function ControlledDocumentForm({ companies, departments, documentNumber }) {
    const { data, setData, post, processing, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        title: '', category: '', department_id: '', file: null,
    });

    function submit(e) {
        e.preventDefault();
        post(route('controlled-documents.store'), { forceFormData: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="New Controlled Document" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('controlled-documents.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-xl">
                <Card>
                    <CardHeader><CardTitle>New Document -- {documentNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5"><Label>Title</Label><Input value={data.title} onChange={(e) => setData('title', e.target.value)} />{errors.title && <p className="text-xs text-red-600">{errors.title}</p>}</div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Category</Label><Input value={data.category} onChange={(e) => setData('category', e.target.value)} placeholder="e.g. SOP, Policy, Drawing" /></div>
                            <div className="space-y-1.5">
                                <Label>Department (optional)</Label>
                                <Select value={data.department_id || 'none'} onValueChange={(v) => setData('department_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                    <SelectContent><SelectItem value="none">None</SelectItem>{departments.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-1.5"><Label>File (version 1.0, optional)</Label><Input type="file" onChange={(e) => setData('file', e.target.files[0])} /></div>
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>
                        <Button type="submit" disabled={processing} className="w-full">Create Document</Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
