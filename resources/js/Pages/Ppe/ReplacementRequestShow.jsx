import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { ArrowLeft, FileDown, Printer } from 'lucide-react';

const STATUS_VARIANT = { draft: 'secondary', submitted: 'success' };

export default function PpeReplacementRequestShow({ replacementRequest: rr }) {
    return (
        <AuthenticatedLayout>
            <Head title={rr.request_number} />

            <Link href={route('ppe.replacement-requests.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Replacement Requests
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-lg font-bold tracking-tight text-graphite-900">
                        {rr.request_number}
                        <Badge variant={STATUS_VARIANT[rr.status]}>{rr.status}</Badge>
                    </h1>
                    <p className="text-xs text-graphite-500">
                        {new Date(rr.request_date).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })} &middot; Requested by {rr.requester?.name}
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button variant="outline" asChild>
                        <a href={route('ppe.replacement-requests.pdf', rr.id)} target="_blank" rel="noopener noreferrer"><Printer className="h-4 w-4" /> Print</a>
                    </Button>
                    <Button asChild>
                        <a href={route('ppe.replacement-requests.pdf', rr.id)} target="_blank" rel="noopener noreferrer"><FileDown className="h-4 w-4" /> PDF</a>
                    </Button>
                </div>
            </div>

            <Card>
                <CardHeader><CardTitle>Items</CardTitle></CardHeader>
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Employee</TableHead>
                                <TableHead>ID / NIK</TableHead>
                                <TableHead>Department</TableHead>
                                <TableHead>Project</TableHead>
                                <TableHead>PPE Item</TableHead>
                                <TableHead>Qty</TableHead>
                                <TableHead>Documentation</TableHead>
                                <TableHead>Remarks</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rr.items.map((item) => (
                                <TableRow key={item.id}>
                                    <TableCell className="font-medium text-graphite-800">{item.employee_ppe.employee.full_name}</TableCell>
                                    <TableCell>{item.employee_ppe.employee.employee_id}{item.employee_ppe.employee.nik ? ` / ${item.employee_ppe.employee.nik}` : ''}</TableCell>
                                    <TableCell>{item.employee_ppe.employee.department?.name || '-'}</TableCell>
                                    <TableCell>{item.project?.name || '-'}</TableCell>
                                    <TableCell>{item.employee_ppe.ppe_type.name}</TableCell>
                                    <TableCell>{item.quantity}</TableCell>
                                    <TableCell>
                                        {item.documentation_photo_url ? (
                                            <a href={item.documentation_photo_url} target="_blank" rel="noopener noreferrer">
                                                <img src={item.documentation_photo_url} className="h-10 w-10 rounded object-cover" alt="" />
                                            </a>
                                        ) : '-'}
                                    </TableCell>
                                    <TableCell>{item.remarks || '-'}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            {rr.notes && (
                <Card className="mt-4">
                    <CardHeader><CardTitle>Notes</CardTitle></CardHeader>
                    <CardContent className="text-[13px] text-graphite-600">{rr.notes}</CardContent>
                </Card>
            )}
        </AuthenticatedLayout>
    );
}
