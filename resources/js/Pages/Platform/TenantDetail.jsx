import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import EmptyState from '@/Components/shared/EmptyState';
import { ArrowLeft, Pencil, Settings2, FileText, Plus, CheckCircle2 } from 'lucide-react';

const STATUS_VARIANT = {
    active: 'success',
    trial: 'default',
    grace_period: 'default',
    suspended: 'destructive',
    expired: 'secondary',
    cancelled: 'secondary',
};

const INVOICE_STATUS_VARIANT = {
    draft: 'secondary',
    issued: 'default',
    paid: 'success',
    overdue: 'destructive',
    void: 'secondary',
};

function formatDate(value, withTime = true) {
    if (!value) return '—';
    return new Date(value).toLocaleString(undefined, withTime ? { dateStyle: 'medium', timeStyle: 'short' } : { dateStyle: 'medium' });
}

/**
 * Master -> Tenant Management. Read-only Tenant Info + Administrator
 * cards (unchanged), PLUS (v1.11.0) an editable Subscription/License card
 * and an Invoices card -- Part 18/19's "Platform Admin can assign plan,
 * assign license type, view billing records, create/issue invoice,
 * manually record payment" requirement.
 */
export default function PlatformTenantDetail({ tenant, subscription, administrator, packages, subscriptionTypes, subscriptionStatuses, invoices }) {
    const [subOpen, setSubOpen] = useState(false);
    const [invoiceOpen, setInvoiceOpen] = useState(false);

    return (
        <PlatformLayout>
            <Head title={tenant.name} />

            <Link href={route('platform.tenants')} className="mb-4 inline-flex items-center gap-1.5 text-sm text-graphite-500 hover:text-graphite-700">
                <ArrowLeft className="h-4 w-4" /> Back to Tenants
            </Link>

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="text-lg font-bold tracking-tight text-graphite-900">{tenant.name}</h1>
                        <Badge variant={STATUS_VARIANT[tenant.status] ?? 'secondary'}>{tenant.status}</Badge>
                    </div>
                    <p className="mt-1 text-sm text-graphite-500">/{tenant.slug} -- Tenant #{tenant.id}</p>
                </div>
                <div className="flex gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('platform.tenants')}>
                            <Pencil className="h-3.5 w-3.5" /> Edit Tenant
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('platform.tenants.grants', tenant.id)}>
                            <Settings2 className="h-3.5 w-3.5" /> Manage Grants
                        </Link>
                    </Button>
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Tenant Information</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <Row label="Name" value={tenant.name} />
                        <Row label="Slug" value={tenant.slug} />
                        <Row label="Status" value={<Badge variant={STATUS_VARIANT[tenant.status] ?? 'secondary'}>{tenant.status}</Badge>} />
                        <Row label="Companies" value={tenant.companies_count} />
                        <Row label="Users" value={tenant.users_count} />
                        <Row label="Created" value={formatDate(tenant.created_at)} />
                        <Row label="Last Updated" value={formatDate(tenant.updated_at)} />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <div>
                            <CardTitle>Subscription / License</CardTitle>
                            <CardDescription>Commercial access record -- plan, license type, and status.</CardDescription>
                        </div>
                        <Button variant="outline" size="sm" onClick={() => setSubOpen(true)}><Pencil className="h-3.5 w-3.5" /> Edit</Button>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        {subscription ? (
                            <>
                                <Row label="Package" value={subscription.package_name ?? '—'} />
                                <Row label="License Type" value={<span className="capitalize">{subscription.type ?? 'subscription'}</span>} />
                                <Row label="Status" value={<Badge variant={STATUS_VARIANT[subscription.status] ?? 'secondary'}>{subscription.status}</Badge>} />
                                {subscription.type !== 'lifetime' && <Row label="Billing Cycle" value={subscription.billing_cycle} />}
                                <Row label="Starts" value={formatDate(subscription.starts_at, false)} />
                                {subscription.type === 'lifetime' ? (
                                    <Row label="Expiry" value={<Badge variant="success">Lifetime -- no expiry</Badge>} />
                                ) : (
                                    <Row label="Ends / Renewal" value={formatDate(subscription.ends_at, false)} />
                                )}
                                <Row label="Seat Limit" value={subscription.seat_limit ?? 'Unlimited'} />
                                {subscription.license_key && <Row label="License Key" value={<code className="text-xs">{subscription.license_key}</code>} />}
                                <Row label="Currently Usable" value={subscription.is_usable ? <Badge variant="success">Yes</Badge> : <Badge variant="destructive">No</Badge>} />
                            </>
                        ) : (
                            <p className="text-graphite-400">No subscription on record for this tenant.</p>
                        )}
                    </CardContent>
                </Card>

                <Card className="md:col-span-2">
                    <CardHeader>
                        <CardTitle>Administrator</CardTitle>
                        <CardDescription>This tenant's own Super Admin account -- created together with the tenant.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        {administrator ? (
                            <>
                                <Row label="Name" value={administrator.name} />
                                <Row label="Email" value={administrator.email} />
                                <Row label="Account Status" value={administrator.is_active ? <Badge variant="success">Active</Badge> : <Badge variant="secondary">Inactive</Badge>} />
                                <Row label="Created" value={formatDate(administrator.created_at)} />
                            </>
                        ) : (
                            <p className="text-graphite-400">
                                No Administrator account found for this tenant yet. This can happen for a tenant that
                                predates the Initial Administrator step (e.g. the seeded Default Tenant before its
                                first login was ever assigned a Super Admin account).
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card className="md:col-span-2">
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <div>
                            <CardTitle className="flex items-center gap-2"><FileText className="h-4 w-4" /> Invoices</CardTitle>
                            <CardDescription>Manually recorded billing documents -- no payment gateway is connected; payments are marked here after being confirmed elsewhere.</CardDescription>
                        </div>
                        <Button variant="outline" size="sm" onClick={() => setInvoiceOpen(true)}><Plus className="h-3.5 w-3.5" /> Issue Invoice</Button>
                    </CardHeader>
                    <CardContent>
                        {invoices.length === 0 ? (
                            <EmptyState icon={FileText} title="No invoices yet" />
                        ) : (
                            <Table>
                                <TableHeader><TableRow><TableHead>Invoice #</TableHead><TableHead>Amount</TableHead><TableHead>Due</TableHead><TableHead>Status</TableHead><TableHead>Payment Date</TableHead><TableHead /></TableRow></TableHeader>
                                <TableBody>
                                    {invoices.map((inv) => <InvoiceRow key={inv.id} invoice={inv} />)}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>

            {subOpen && (
                <SubscriptionDialog tenant={tenant} subscription={subscription} packages={packages} types={subscriptionTypes} statuses={subscriptionStatuses} onClose={() => setSubOpen(false)} />
            )}
            {invoiceOpen && <InvoiceDialog tenant={tenant} onClose={() => setInvoiceOpen(false)} />}
        </PlatformLayout>
    );
}

function Row({ label, value }) {
    return (
        <div className="flex items-center justify-between gap-4 border-b border-graphite-100 pb-2 last:border-0 last:pb-0 dark:border-slate-800">
            <span className="text-graphite-500">{label}</span>
            <span className="font-medium text-graphite-800 dark:text-slate-100">{value}</span>
        </div>
    );
}

function InvoiceRow({ invoice }) {
    const { post, processing } = useForm({});

    function markPaid() {
        if (!confirm(`Mark invoice ${invoice.invoice_number} as paid?`)) return;
        post(route('platform.invoices.mark-paid', invoice.id), { method: 'put', preserveScroll: true });
    }

    return (
        <TableRow>
            <TableCell className="font-medium">{invoice.invoice_number}</TableCell>
            <TableCell>{invoice.currency} {Number(invoice.amount).toLocaleString()}</TableCell>
            <TableCell>{formatDate(invoice.due_date, false)}</TableCell>
            <TableCell><Badge variant={INVOICE_STATUS_VARIANT[invoice.status] ?? 'secondary'}>{invoice.status}</Badge></TableCell>
            <TableCell>{formatDate(invoice.payment_date, false)}</TableCell>
            <TableCell>
                {invoice.status !== 'paid' && invoice.status !== 'void' && (
                    <Button variant="outline" size="sm" disabled={processing} onClick={markPaid}><CheckCircle2 className="h-3.5 w-3.5" /> Mark Paid</Button>
                )}
            </TableCell>
        </TableRow>
    );
}

function SubscriptionDialog({ tenant, subscription, packages, types, statuses, onClose }) {
    const { data, setData, put, processing, errors } = useForm({
        package_id: subscription?.package_id ? String(subscription.package_id) : (packages[0]?.id ? String(packages[0].id) : ''),
        type: subscription?.type || 'subscription',
        status: subscription?.status || 'active',
        billing_cycle: subscription?.billing_cycle || 'monthly',
        seat_limit: subscription?.seat_limit || '',
        license_key: subscription?.license_key || '',
        billing_reference: subscription?.billing_reference || '',
        starts_at: subscription?.starts_at ? subscription.starts_at.slice(0, 10) : '',
        ends_at: subscription?.ends_at ? subscription.ends_at.slice(0, 10) : '',
        notes: subscription?.notes || '',
    });

    function submit(e) {
        e.preventDefault();
        put(route('platform.tenants.subscription.update', tenant.id), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <DialogHeader><DialogTitle>Subscription / License -- {tenant.name}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label>Plan</Label>
                            <Select value={data.package_id} onValueChange={(v) => setData('package_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{packages.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>License Type</Label>
                            <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{types.map((t) => <SelectItem key={t} value={t} className="capitalize">{t}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Status</Label>
                            <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{statuses.map((s) => <SelectItem key={s} value={s} className="capitalize">{s.replace('_', ' ')}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        {data.type !== 'lifetime' && (
                            <div className="space-y-1.5">
                                <Label>Billing Cycle</Label>
                                <Select value={data.billing_cycle} onValueChange={(v) => setData('billing_cycle', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent><SelectItem value="monthly">Monthly</SelectItem><SelectItem value="yearly">Yearly</SelectItem></SelectContent>
                                </Select>
                            </div>
                        )}
                        <div className="space-y-1.5"><Label>Seat Limit (blank = plan default)</Label><Input type="number" min="1" value={data.seat_limit} onChange={(e) => setData('seat_limit', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>License Key</Label><Input value={data.license_key} onChange={(e) => setData('license_key', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Starts</Label><Input type="date" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} /></div>
                        {data.type !== 'lifetime' && (
                            <div className="space-y-1.5"><Label>Ends / Renewal</Label><Input type="date" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} /></div>
                        )}
                    </div>
                    <div className="space-y-1.5"><Label>Billing Reference</Label><Input value={data.billing_reference} onChange={(e) => setData('billing_reference', e.target.value)} /></div>
                    <div className="space-y-1.5"><Label>Notes</Label><Textarea rows={3} value={data.notes} onChange={(e) => setData('notes', e.target.value)} /></div>
                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">{Object.values(errors).map((m, i) => <p key={i}>{m}</p>)}</div>
                    )}
                    <DialogFooter><Button type="button" variant="outline" onClick={onClose}>Cancel</Button><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function InvoiceDialog({ tenant, onClose }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        period_start: '', period_end: '', amount: '', currency: 'IDR', due_date: '', notes: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('platform.tenants.invoices.store', tenant.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    }

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader><DialogTitle>Issue Invoice -- {tenant.name}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5"><Label>Period Start</Label><Input type="date" value={data.period_start} onChange={(e) => setData('period_start', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Period End</Label><Input type="date" value={data.period_end} onChange={(e) => setData('period_end', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Amount</Label><Input type="number" min="0" step="0.01" value={data.amount} onChange={(e) => setData('amount', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Currency</Label><Input maxLength={3} value={data.currency} onChange={(e) => setData('currency', e.target.value.toUpperCase())} /></div>
                        <div className="space-y-1.5 col-span-2"><Label>Due Date</Label><Input type="date" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} /></div>
                    </div>
                    <div className="space-y-1.5"><Label>Notes</Label><Textarea rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} /></div>
                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">{Object.values(errors).map((m, i) => <p key={i}>{m}</p>)}</div>
                    )}
                    <DialogFooter><Button type="button" variant="outline" onClick={onClose}>Cancel</Button><Button type="submit" disabled={processing}>Issue Invoice</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
