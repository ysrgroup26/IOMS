import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft } from 'lucide-react';

export default function VisitorForm({ employees, visitorNumber }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '', visitor_company: '', purpose: '', host_employee_id: '',
        visit_date: new Date().toISOString().slice(0, 10), contact_phone: '', contact_email: '', notes: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('visitors.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Register Visitor" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('visitors.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-xl">
                <Card>
                    <CardHeader><CardTitle>Register Visitor -- {visitorNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} />{errors.name && <p className="text-xs text-red-600">{errors.name}</p>}</div>
                            <div className="space-y-1.5"><Label>Company</Label><Input value={data.visitor_company} onChange={(e) => setData('visitor_company', e.target.value)} placeholder="e.g. PT XYZ" /></div>
                        </div>
                        <div className="space-y-1.5"><Label>Purpose</Label><Input value={data.purpose} onChange={(e) => setData('purpose', e.target.value)} /></div>
                        <div className="space-y-1.5">
                            <Label>Host Employee</Label>
                            <Select value={data.host_employee_id} onValueChange={(v) => setData('host_employee_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select host" /></SelectTrigger>
                                <SelectContent>{employees.map((e) => <SelectItem key={e.id} value={String(e.id)}>{e.full_name}</SelectItem>)}</SelectContent>
                            </Select>
                            {errors.host_employee_id && <p className="text-xs text-red-600">{errors.host_employee_id}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Visit Date</Label><Input type="date" value={data.visit_date} onChange={(e) => setData('visit_date', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Phone</Label><Input value={data.contact_phone} onChange={(e) => setData('contact_phone', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5"><Label>Email</Label><Input type="email" value={data.contact_email} onChange={(e) => setData('contact_email', e.target.value)} />{errors.contact_email && <p className="text-xs text-red-600">{errors.contact_email}</p>}</div>
                        <div className="space-y-1.5"><Label>Notes</Label><Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} /></div>
                        <Button type="submit" disabled={processing} className="w-full">Register Visitor</Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
