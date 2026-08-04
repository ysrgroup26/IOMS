import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft } from 'lucide-react';

/** Leave request creation (v1.10.0). No edit route -- a leave request is either a Draft (delete and recreate) or already submitted into the Approval Engine, matching this module's deliberately minimal scope. */
export default function LeaveForm({ employees, leaveNumber, types }) {
    const { data, setData, post, processing, errors } = useForm({
        employee_id: '',
        leave_type: 'annual',
        start_date: '',
        end_date: '',
        reason: '',
        status: 'submitted',
    });

    function submit(e) {
        e.preventDefault();
        post(route('leave-requests.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="New Leave Request" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('leave-requests.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-xl">
                <Card>
                    <CardHeader><CardTitle>New Leave Request -- {leaveNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Employee</Label>
                            <Select value={String(data.employee_id)} onValueChange={(v) => setData('employee_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select employee" /></SelectTrigger>
                                <SelectContent>
                                    {employees.map((e) => (
                                        <SelectItem key={e.id} value={String(e.id)}>{e.full_name} ({e.employee_id})</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.employee_id && <p className="text-xs text-red-600">{errors.employee_id}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Leave Type</Label>
                            <Select value={data.leave_type} onValueChange={(v) => setData('leave_type', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {types.map((t) => <SelectItem key={t} value={t} className="capitalize">{t}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Start Date</Label>
                                <Input type="date" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} />
                                {errors.start_date && <p className="text-xs text-red-600">{errors.start_date}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>End Date</Label>
                                <Input type="date" value={data.end_date} onChange={(e) => setData('end_date', e.target.value)} />
                                {errors.end_date && <p className="text-xs text-red-600">{errors.end_date}</p>}
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Reason</Label>
                            <Textarea value={data.reason} onChange={(e) => setData('reason', e.target.value)} rows={3} />
                        </div>

                        <div className="space-y-1.5">
                            <Label>Save As</Label>
                            <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="draft">Draft</SelectItem>
                                    <SelectItem value="submitted">Submit for Approval</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <Button type="submit" disabled={processing} className="w-full">Save Leave Request</Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
