/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';

/* v2.70.0: keyed on the DERIVED lifecycle state, not the stored status.
   `grace_period`/`expired` are gone from the vocabulary entirely -- they
   were stored values nothing ever wrote. */
const SUB_STATUS_VARIANT = {
    active: 'success', trial: 'default', grace: 'warning',
    lapsed: 'destructive', suspended: 'destructive', cancelled: 'secondary',
};


const INVOICE_STATUS_VARIANT = { draft: 'secondary', issued: 'default', paid: 'success', overdue: 'destructive', void: 'secondary' };


/**
 * v1.11.0 (SaaS Finalization Pass, Part 19). Tenant Admin's read-only
 * view of their own commercial record -- changing plan/type/status stays
 * Platform Admin-only (PlatformController::updateSubscription()), this
 * tab only displays what SettingsController::index() already resolved.
 * If lifetime, explicitly shows "Lifetime License" and never a
 * recurring-renewal date, per the explicit product requirement.
 */
export default function SubscriptionTab({ subscription, invoices }) {
    if (!subscription) {
        return (
            <Card>
                <CardHeader><CardTitle>Subscription</CardTitle></CardHeader>
                {/* v2.25.0 (Global UX & Copywriting Polish pass): naturalized to Indonesian. */}
                <CardContent><p className="text-sm text-graphite-400">Belum ada data langganan untuk perusahaan Anda. Hubungi penyedia layanan.</p></CardContent>
            </Card>
        );
    }

    const formatDate = (v) => (v ? new Date(v).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '-');

    return (
        <div className="space-y-4">
            {/* v2.70.0 -- this panel used to say "contact your platform
                provider", which was accurate while no self-service renewal
                existed and is not any more. It states the DERIVED lifecycle
                and sends the customer to the page that can fix it, so this
                tab and Billing cannot tell them two different things. */}
            {subscription.lifecycle_state === 'grace' && (
                <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm leading-relaxed text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">
                    Masa aktif langganan telah berakhir. Akses penuh masih berjalan untuk sementara; setelah itu pencatatan data baru dijeda sampai pembayaran diterima.{' '}
                    <Link href={route('subscription.billing')} className="font-medium underline">Buka Billing</Link>.
                </div>
            )}
            {subscription.lifecycle_state === 'lapsed' && (
                <div className="rounded-md border border-red-200 bg-red-50 p-3 text-sm leading-relaxed text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">
                    Masa aktif langganan telah berakhir, sehingga pencatatan data baru dijeda. Seluruh data Anda tetap utuh dan dapat dibuka seperti biasa.{' '}
                    <Link href={route('subscription.billing')} className="font-medium underline">Buka Billing</Link> untuk melanjutkan langganan.
                </div>
            )}
            {!subscription.is_usable && (
                <div className="rounded-md border border-red-200 bg-red-50 p-3 text-sm leading-relaxed text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">
                    Langganan organisasi Anda berstatus {subscription.status}. Data Anda tidak dihapus dan tetap tersimpan. Hubungi penyedia platform Anda untuk mengaktifkan kembali.
                </div>
            )}
            <Card>
                <CardHeader className="flex flex-row items-start justify-between gap-3">
                    <div>
                        <CardTitle>Subscription / License</CardTitle>
                        {/* v2.25.0 (Global UX & Copywriting Polish pass): naturalized to Indonesian, per this pass's own example phrasing. */}
                        <CardDescription>Kelola paket dan status langganan IOMS perusahaan Anda.</CardDescription>
                    </div>
                    {/* v2.14.0 (SaaS Productization, Part 8): links to the new
                        data-driven Plans page rather than duplicating a plan
                        comparison inline on this tab. */}
                    <Button variant="outline" size="sm" asChild><Link href={route('subscription.plans')}>Lihat Paket Lain</Link></Button>
                </CardHeader>
                <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="space-y-3 text-sm">
                        <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">Plan</span><span className="font-medium">{subscription.package_name ?? '-'}</span></div>
                        <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">License Type</span><span className="font-medium capitalize">{subscription.type ?? 'subscription'}</span></div>
                        <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">Status</span><Badge variant={SUB_STATUS_VARIANT[subscription.lifecycle_state] ?? 'secondary'} className="capitalize">{subscription.lifecycle_state}</Badge></div>
                        <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">Full User Seats</span><span className="font-medium">{subscription.seat_limit ?? 'Unlimited'}</span></div>
                        <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">My Work User Seats</span><span className="font-medium">{subscription.my_work_seat_limit ?? 'Unlimited'}</span></div>
                    </div>
                    <div className="space-y-3 text-sm">
                        <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">Start Date</span><span className="font-medium">{formatDate(subscription.starts_at)}</span></div>
                        {subscription.type === 'lifetime' ? (
                            <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">Expiry</span><Badge variant="success">Lifetime License -- no expiry</Badge></div>
                        ) : (
                            <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">{subscription.status === 'trial' ? 'Trial Ends' : 'Renewal'}</span><span className="font-medium">{formatDate(subscription.status === 'trial' ? subscription.trial_ends_at : subscription.ends_at)}{typeof subscription.days_remaining === 'number' && subscription.days_remaining >= 0 ? <span className="ml-1.5 font-normal text-graphite-400">({subscription.days_remaining} hari)</span> : null}</span></div>
                        )}
                        <div className="flex items-center justify-between border-b border-graphite-100 pb-2 dark:border-slate-800"><span className="text-graphite-500">Billing Cycle</span><span className="font-medium capitalize">{subscription.type === 'lifetime' ? 'N/A' : subscription.billing_cycle}</span></div>
                        <div className="flex items-center justify-between pb-2"><span className="text-graphite-500">Currently Usable</span>{subscription.is_usable ? <Badge variant="success">Yes</Badge> : <Badge variant="destructive">No -- contact your provider</Badge>}</div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>Invoices</CardTitle><CardDescription>Billing history for your organization.</CardDescription></CardHeader>
                <CardContent className="p-0">
                    {invoices.length === 0 ? (
                        <p className="p-4 text-center text-sm text-graphite-400">No invoices yet.</p>
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Invoice #</TableHead><TableHead>Amount</TableHead><TableHead>Due</TableHead><TableHead>Status</TableHead><TableHead>Payment Date</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {invoices.map((inv) => (
                                    <TableRow key={inv.id}>
                                        <TableCell className="font-medium">{inv.invoice_number}</TableCell>
                                        <TableCell>{inv.currency} {Number(inv.amount).toLocaleString()}</TableCell>
                                        <TableCell>{formatDate(inv.due_date)}</TableCell>
                                        <TableCell><Badge variant={INVOICE_STATUS_VARIANT[inv.status] ?? 'secondary'}>{inv.status}</Badge></TableCell>
                                        <TableCell>{formatDate(inv.payment_date)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
