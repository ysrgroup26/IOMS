import { Head, Link } from '@inertiajs/react';
import { CreditCard, Users, Building2, ShieldCheck, CalendarClock, Receipt, AlertTriangle } from 'lucide-react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * v2.51.0 -- the tenant's Billing page.
 *
 * Answers "what am I on, until when, how much of it am I using, and what
 * have I paid" without a support ticket.
 *
 * Deliberately read-only. A self-serve upgrade button would have to call
 * a payment gateway that may not be configured on this deployment, and a
 * button that sometimes fails is worse than an honest sentence about how
 * to change plan. Capacity bars show real counts against the real
 * entitlement -- never a decorative percentage.
 */
const STATUS_COPY = {
    trial: 'Your trial is running.',
    active: 'Your subscription is active.',
    grace_period: 'Payment is overdue. Access continues for a short grace period.',
    expired: 'This subscription has expired.',
    suspended: 'This subscription is suspended. Access is restricted.',
    cancelled: 'This subscription has been cancelled.',
};

export default function Billing({ subscription, entitlements, invoices = [], recurringEnabled, billingEmail }) {
    const fmtDate = (v) =>
        v ? new Date(v).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' }) : '—';

    const needsAttention =
        subscription && ['grace_period', 'expired', 'suspended', 'cancelled'].includes(subscription.status);

    return (
        <AuthenticatedLayout>
            <Head title="Billing" />

            <PageHeader
                icon={CreditCard}
                title="Billing"
                subtitle="Your plan, capacity and payment history."
            >
                <Button variant="outline" asChild>
                    <Link href={route('subscription.plans')}>Compare plans</Link>
                </Button>
            </PageHeader>

            {needsAttention && (
                <div className="mb-4 flex items-start gap-2.5 rounded-lg border border-warning/25 bg-warning/[0.07] p-4 text-sm leading-relaxed text-amber-900">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                    <span>
                        {STATUS_COPY[subscription.status]}{' '}
                        {billingEmail && (
                            <>Contact <a href={`mailto:${billingEmail}`} className="font-medium underline">{billingEmail}</a> to restore it.</>
                        )}
                    </span>
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                {/* ---------------- Current subscription ---------------- */}
                <Card className="lg:col-span-2">
                    <CardHeader><CardTitle>Current subscription</CardTitle></CardHeader>
                    <CardContent>
                        {subscription ? (
                            <>
                                <div className="flex flex-wrap items-baseline justify-between gap-3 border-b border-steel-100 pb-4">
                                    <div>
                                        <p className="text-[11px] uppercase tracking-wide text-graphite-400">Plan</p>
                                        <p className="mt-0.5 text-xl font-semibold tracking-tight text-navy-900">
                                            {subscription.plan_name || '—'}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-[11px] uppercase tracking-wide text-graphite-400">
                                            {subscription.billing_cycle === 'monthly' ? 'Per month' : 'Per year'}
                                        </p>
                                        <p className="mt-0.5 text-xl font-semibold tracking-tight text-navy-900">
                                            {subscription.price || '—'}
                                        </p>
                                    </div>
                                </div>

                                <dl className="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                                    <Row label="Status">
                                        <StatusBadge value={subscription.status} />
                                    </Row>
                                    <Row label="Billing cycle">
                                        {subscription.billing_cycle === 'monthly' ? 'Monthly' : 'Annual'}
                                    </Row>
                                    <Row label="Started">{fmtDate(subscription.starts_at)}</Row>
                                    <Row label={subscription.status === 'trial' ? 'Trial ends' : 'Renews / ends'}>
                                        {fmtDate(subscription.status === 'trial' ? subscription.trial_ends_at : subscription.ends_at)}
                                    </Row>
                                </dl>

                                {/* Says which renewal mode this deployment is
                                    actually in. Automatic recurring charging
                                    needs separate merchant activation, so it is
                                    never implied. */}
                                <div className="mt-5 flex items-start gap-2.5 rounded-lg border border-steel-100 bg-steel-50/60 p-3.5">
                                    <CalendarClock className="mt-0.5 h-4 w-4 shrink-0 text-graphite-400" />
                                    <p className="text-xs leading-relaxed text-graphite-600">
                                        {recurringEnabled
                                            ? 'Your subscription renews automatically at the end of each billing period using your saved payment method. A receipt is issued for every renewal.'
                                            : 'An invoice is issued at the end of each billing period and your subscription continues once it is paid. IOMS does not charge a saved card automatically on this deployment.'}
                                        {' '}To change or cancel your plan
                                        {billingEmail ? <>, contact <a href={`mailto:${billingEmail}`} className="font-medium text-brand-700 hover:underline">{billingEmail}</a>.</> : '.'}
                                    </p>
                                </div>
                            </>
                        ) : (
                            <EmptyState
                                icon={CreditCard}
                                title="No subscription on record"
                                description="This workspace has no subscription yet. Contact us to activate a plan."
                            />
                        )}
                    </CardContent>
                </Card>

                {/* ---------------- Capacity ---------------- */}
                <Card>
                    <CardHeader><CardTitle>Capacity in use</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <Meter icon={Users} label="User accounts" {...entitlements.users} />
                        <Meter icon={ShieldCheck} label="PTW access" {...entitlements.ptw_users} />
                        <Meter icon={Building2} label="Companies" {...entitlements.companies} />
                        <p className="text-[11px] leading-relaxed text-graphite-400">
                            Counted live from this workspace. A blank limit means the plan does not cap it.
                        </p>
                    </CardContent>
                </Card>
            </div>

            {/* ---------------- Invoices ---------------- */}
            <Card className="mt-4">
                <CardHeader><CardTitle>Invoices &amp; payments</CardTitle></CardHeader>
                <CardContent className="p-0">
                    {invoices.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                icon={Receipt}
                                title="No invoices yet"
                                description="Invoices appear here as they are issued for your subscription."
                            />
                        </div>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Invoice</TableHead>
                                    <TableHead>Period</TableHead>
                                    <TableHead>Due</TableHead>
                                    <TableHead>Paid</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Amount</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {invoices.map((inv) => (
                                    <TableRow key={inv.id}>
                                        <TableCell className="font-medium text-navy-900">{inv.invoice_number}</TableCell>
                                        <TableCell className="text-graphite-500">
                                            {inv.period_start ? `${fmtDate(inv.period_start)} – ${fmtDate(inv.period_end)}` : '—'}
                                        </TableCell>
                                        <TableCell className="text-graphite-500">{fmtDate(inv.due_date)}</TableCell>
                                        <TableCell className="text-graphite-500">
                                            {inv.payment_date ? fmtDate(inv.payment_date) : '—'}
                                            {inv.payment_method && <span className="block text-[11px] text-graphite-400">{inv.payment_method}</span>}
                                        </TableCell>
                                        <TableCell><StatusBadge value={inv.status} /></TableCell>
                                        <TableCell className="text-right font-medium text-navy-900">{inv.amount_formatted}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </AuthenticatedLayout>
    );
}

function Row({ label, children }) {
    return (
        <div>
            <dt className="text-[11px] uppercase tracking-wide text-graphite-400">{label}</dt>
            <dd className="mt-0.5 text-sm text-graphite-700">{children}</dd>
        </div>
    );
}

/** Real usage against a real limit. Renders a plain count when the plan sets no cap. */
function Meter({ icon: Icon, label, used, limit }) {
    const pct = limit ? Math.min(100, Math.round((used / limit) * 100)) : null;
    const tight = pct !== null && pct >= 90;

    return (
        <div>
            <div className="flex items-center justify-between gap-2">
                <span className="flex items-center gap-1.5 text-xs font-medium text-graphite-600">
                    <Icon className="h-3.5 w-3.5 text-graphite-400" />
                    {label}
                </span>
                <span className={cn('text-xs font-semibold', tight ? 'text-danger' : 'text-navy-900')}>
                    {used}{limit ? ` / ${limit}` : ''}
                </span>
            </div>
            {pct !== null && (
                <div className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-steel-100">
                    <div
                        className={cn('h-full rounded-full', tight ? 'bg-danger' : 'bg-gradient-to-r from-navy-800 to-brand-600')}
                        style={{ width: `${pct}%` }}
                    />
                </div>
            )}
        </div>
    );
}
