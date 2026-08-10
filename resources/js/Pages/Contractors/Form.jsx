import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft } from 'lucide-react';

export default function ContractorForm({ companies, contractorCode }) {
    const { data, setData, post, processing, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        company_name: '', address: '', pic_name: '', pic_contact: '', notes: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('contractors.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Register Contractor" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('contractors.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-xl">
                <Card>
                    <CardHeader><CardTitle>Register Contractor -- {contractorCode}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5"><Label>Company Name</Label><Input value={data.company_name} onChange={(e) => setData('company_name', e.target.value)} placeholder="e.g. PT ABC" />{errors.company_name && <p className="text-xs text-red-600">{errors.company_name}</p>}</div>
                        <div className="space-y-1.5"><Label>Address</Label><Textarea value={data.address} onChange={(e) => setData('address', e.target.value)} rows={2} /></div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>PIC Name</Label><Input value={data.pic_name} onChange={(e) => setData('pic_name', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>PIC Contact</Label><Input value={data.pic_contact} onChange={(e) => setData('pic_contact', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5"><Label>Notes</Label><Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} /></div>
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>
                        <Button type="submit" disabled={processing} className="w-full">Register Contractor</Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
