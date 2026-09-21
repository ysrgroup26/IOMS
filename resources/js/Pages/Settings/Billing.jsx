import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CreditCard, Users, Building2, ShieldCheck, CalendarClock, Receipt, AlertTriangle, FileDown, ArrowUpRight, Clock3, CheckCircle2, Lock } from 'lucide-react';
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
 * v2.70.0 -- and now the page where a customer can actually DO something.
 *
 * It used to be read-only, and said so: "contact us to change plan". That
 * was honest at the time, because no renewal, payment or plan-change path
 * existed behind it. It also carried one sentence that was NOT honest --
 * it promised that "an invoice is issued at the end of each billing
 * period and your subscription continues once it is paid" while nothing
 * in the product issued one. That sentence is now true, and the buttons
 * beside it are real.
 *
 * WHAT THIS PAGE SHOWS is the DERIVED lifecycle state, not the stored
 * status. `status` alone could never tell a customer whether their period
 * had ended -- it said "active" on a subscription that ran out four
 * months ago. Active / grace / lapsed comes from the dates, every time it
 * is read.
 *
 * Capacity bars show real counts against the real entitlement -- never a
 * decorative percentage.
 */

/**
 * One row per state, because each one has to answer a different question
 * and none of them may overstate what happened.
 *
 * `lapsed` is the one to read carefully: it says what was withdrawn
 * (recording new data) AND what was not (everything already recorded). A
 * customer who thinks they have been locked out of their own safety
 * records will escalate to a lawyer, not to billing.
 */
const LIFECYCLE = {
    // v2.77.0: inside the renewal window the page no longer says only
    // "Active" in green while the shell's banner is already warning.
    expiring: {
        tone: 'warn',
        icon: Clock3,
        label: 'Renewal due soon',
        body: (s) =>
            `Langganan Anda aktif hingga ${fmtDate(s.period_ends_at)} (${s.days_remaining} hari lagi). `
            + 'Perpanjang sekarang: masa aktif baru ditambahkan setelah periode yang sedang berjalan, bukan mulai hari ini.',
    },
    active: {
        tone: 'ok',
        icon: CheckCircle2,
        label: 'Active',
        body: (s) => `Langganan Anda aktif hingga ${fmtDate(s.period_ends_at)}.`,
    },
    grace: {
        tone: 'warn',
        icon: Clock3,
        label: 'Renewal due',
        body: (s) =>
            `Masa aktif langganan berakhir pada ${fmtDate(s.period_ends_at)}. Akses penuh masih berjalan hingga ${fmtDate(s.grace_ends_at)}. `
            + 'Setelah tanggal tersebut, seluruh data Anda tetap dapat dibuka namun pencatatan data baru dijeda sampai pembayaran diterima.',
    },
    lapsed: {
        tone: 'danger',
        icon: Lock,
        label: 'Read-only',
        body: () =>
            'Masa aktif langganan telah berakhir, sehingga pencatatan data baru sedang dijeda. '
            + 'Seluruh data Anda tetap utuh dan dapat dibuka, dicari, serta diunduh seperti biasa. '
            + 'Pencatatan akan aktif kembali segera setelah pembayaran diterima.',
    },
    suspended: {
        tone: 'danger',
        icon: Lock,
        label: 'Suspended',
        body: () => 'Langganan organisasi Anda sedang dihentikan sementara. Data Anda tidak dihapus dan tetap tersimpan.',
    },
    cancelled: {
        tone: 'danger',
        icon: Lock,
        label: 'Cancelled',
        body: () => 'Langganan organisasi Anda telah dibatalkan. Data Anda tidak dihapus dan tetap tersimpan.',
    },
};

const TONE = {
    ok: 'border-success/25 bg-success/[0.07] text-emerald-900',
    warn: 'border-warning/25 bg-warning/[0.07] text-amber-900',
    danger: 'border-danger/25 bg-danger/[0.07] text-red-900',
};

function fmtDate(v) {
    return v ? new Date(v).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' }) : '—';
}

export default function Billing({
    subscription,
    outstandingInvoice,
    entitlements,
    invoices = [],
    onlinePaymentEnabled,
    graceDays,
    billingEmail,
}) {
    const renew = useForm({});

    // The same window the shell banner and the renewal invoice use, read
    // from the server rather than invented here.
    const leadDays = usePage().props.subscriptionState?.lead_days ?? 14;

    const baseState = subscription?.is_lifetime ? 'active' : subscription?.lifecycle_state;
    const expiring = baseState === 'active'
        && subscription?.status !== 'trial'
        && typeof subscription?.days_remaining === 'number'
        && subscription.days_remaining >= 0
        && subscription.days_remaining <= leadDays;
    const state = expiring ? 'expiring' : baseState;
    const notice = state ? LIFECYCLE[state] : null;

    // Where renewal is the answer, the action sits WITH the message rather
    // than at the bottom of the next card. Suspended and cancelled are
    // excluded: a payment does not lift an operator's decision (ADR 033 §8).
    const renewalRelevant = !subscription?.is_lifetime && ['expiring', 'grace', 'lapsed'].includes(state);
    const NoticeIcon = notice?.icon ?? AlertTriangle;

    const cancelPendingChange = () => {
        router.delete(route('subscription.plan-change.cancel'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Billing" />

            <PageHeader
                icon={CreditCard}
                title="Billing"
                subtitle="Paket, kapasitas, dan riwayat pembayaran organisasi Anda."
            >
                <Button variant="outline" asChild>
                    <Link href={route('subscription.plans')}>Compare plans</Link>
                </Button>
            </PageHeader>

            {/* ---------------- Where this subscription stands ---------------- */}
            {notice && (
                <div className={cn('mb-4 flex items-start gap-2.5 rounded-lg border p-4 text-sm leading-relaxed', TONE[notice.tone])}>
                    <NoticeIcon className="mt-0.5 h-4 w-4 shrink-0" />
                    <div className="min-w-0 flex-1">
                        <p className="font-medium">{notice.label}</p>
                        <p className="mt-0.5">
                            {subscription.is_lifetime
                                ? 'Lisensi organisasi Anda berlaku selamanya dan tidak memerlukan perpanjangan.'
                                : notice.body(subscription)}
                        </p>
                        {renewalRelevant && (
                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                {outstandingInvoice ? (
                                    onlinePaymentEnabled ? (
                                        <Button size="sm" asChild>
                                            <Link href={route('subscription.pay', outstandingInvoice.id)}>
                                                Pay renewal invoice
                                            </Link>
                                        </Button>
                                    ) : (
                                        <span className="text-xs">
                                            Tagihan {outstandingInvoice.invoice_number} menunggu pembayaran melalui transfer bank — hubungi {billingEmail}.
                                        </span>
                                    )
                                ) : (
                                    <Button
                                        size="sm"
                                        onClick={() => renew.post(route('subscription.renew'), { preserveScroll: true })}
                                        disabled={renew.processing}
                                    >
                                        {renew.processing ? 'Preparing…' : 'Renew subscription'}
                                    </Button>
                                )}
                                {state === 'lapsed' && (
                                    <span className="text-xs opacity-90">
                                        Pencatatan aktif kembali segera setelah pembayaran terverifikasi.
                                    </span>
                                )}
                            </div>
                        )}
                        {(state === 'suspended' || state === 'cancelled') && billingEmail && (
                            <p className="mt-1.5">
                                Hubungi{' '}
                                <a href={`mailto:${billingEmail}`} className="font-medium underline">{billingEmail}</a>{' '}
                                untuk mengaktifkan kembali.
                            </p>
                        )}
                    </div>
                </div>
            )}

            {/* ---------------- The thing they have to pay ---------------- */}
            {outstandingInvoice && (
                <Card className="mb-4 border-brand-200 bg-brand-50/40">
                    <CardContent className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0">
                            <p className="text-[11px] uppercase tracking-wide text-graphite-400">
                                {outstandingInvoice.purpose === 'plan_change' ? 'Plan change invoice' : 'Renewal invoice'}
                            </p>
                            <p className="mt-0.5 text-lg font-semibold tracking-tight text-navy-900">
                                {outstandingInvoice.amount_formatted}
                                <span className="ml-2 text-sm font-normal text-graphite-500">{outstandingInvoice.invoice_number}</span>
                            </p>
                            <p className="mt-1 text-xs leading-relaxed text-graphite-600">
                                {outstandingInvoice.period_start
                                    ? `Untuk masa ${fmtDate(outstandingInvoice.period_start)} sampai ${fmtDate(outstandingInvoice.period_end)}. `
                                    : ''}
                                {outstandingInvoice.is_overdue
                                    ? `Jatuh tempo pada ${fmtDate(outstandingInvoice.due_date)}.`
                                    : `Mohon dibayar sebelum ${fmtDate(outstandingInvoice.due_date)}.`}
                            </p>
                        </div>
                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                            <a
                                href={route('subscription.invoices.pdf', outstandingInvoice.id)}
                                target="_blank"
                                rel="noopener"
                                className="inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline"
                            >
                                <FileDown className="h-3.5 w-3.5" /> PDF
                            </a>
                            {/* An invoice is paid on IOMS's own checkout page, which
                                then opens the provider's payment interface. Nothing
                                the browser does here extends anything -- the period
                                moves only when the provider's signed notification is
                                verified server-side. */}
                            {onlinePaymentEnabled ? (
                                <Button asChild>
                                    <Link href={route('subscription.pay', outstandingInvoice.id)}>Pay now</Link>
                                </Button>
                            ) : (
                                <span className="text-xs text-graphite-500">
                                    Pembayaran melalui transfer bank — hubungi {billingEmail}.
                                </span>
                            )}
                        </div>
                    </CardContent>
                </Card>
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
                                        {/* v2.60.0: this figure is the price the customer
                                            AGREED to, which after a catalogue change is no
                                            longer the published one. Saying so here is the
                                            difference between "my bill is right" and a
                                            support ticket asking why the pricing page shows
                                            something else. */}
                                        {subscription.is_legacy_pricing && (
                                            <p className="mt-1 max-w-[15rem] text-[11px] leading-relaxed text-graphite-500">
                                                Harga yang Anda sepakati saat berlangganan tetap dipertahankan.
                                                {subscription.catalogue_price
                                                    ? ` Paket ini kini dipublikasikan seharga ${subscription.catalogue_price}.`
                                                    : ''}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <dl className="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                                    <Row label="Status">
                                        <StatusBadge value={subscription.lifecycle_state} />
                                    </Row>
                                    <Row label="Billing cycle">
                                        {subscription.billing_cycle === 'monthly' ? 'Monthly' : 'Annual'}
                                    </Row>
                                    <Row label="Started">{fmtDate(subscription.starts_at)}</Row>
                                    {/* v2.77.0: once the period has ended this row
                                        used to read "Renews <a past date>". */}
                                    <Row label={
                                        subscription.status === 'trial'
                                            ? 'Trial ends'
                                            : ['grace', 'lapsed'].includes(baseState) ? 'Period ended' : 'Renews'
                                    }>
                                        {subscription.is_lifetime ? 'Lifetime' : fmtDate(subscription.period_ends_at)}
                                        {typeof subscription.days_remaining === 'number' && subscription.days_remaining >= 0 && (
                                            <span className="ml-1.5 text-[11px] text-graphite-400">
                                                ({subscription.days_remaining} hari lagi)
                                            </span>
                                        )}
                                    </Row>
                                    {!subscription.is_lifetime && subscription.grace_ends_at && ['grace', 'lapsed'].includes(baseState) && (
                                        <Row label={baseState === 'lapsed' ? 'Read-only since' : 'Read-only from'}>
                                            {fmtDate(subscription.grace_ends_at)}
                                        </Row>
                                    )}
                                </dl>

                                {/* A downgrade or cycle change the customer already
                                    asked for. Deferred to the period boundary on
                                    purpose: they paid for the period they are in, and
                                    applying a smaller plan today could drop the
                                    organization below the seats it is actively using. */}
                                {subscription.pending_change && (
                                    <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-steel-100 bg-steel-50/60 p-3.5">
                                        <p className="text-xs leading-relaxed text-graphite-600">
                                            Terjadwal: pindah ke <strong className="font-semibold text-navy-900">{subscription.pending_change.plan_name}</strong>{' '}
                                            ({subscription.pending_change.billing_cycle === 'monthly' ? 'bulanan' : 'tahunan'}) pada{' '}
                                            {fmtDate(subscription.pending_change.effective_at)}.
                                        </p>
                                        <Button variant="ghost" size="sm" onClick={cancelPendingChange}>Cancel change</Button>
                                    </div>
                                )}

                                {/* Says which renewal mode this deployment is actually
                                    in. Automatic recurring charging needs separate
                                    merchant activation, so it is never implied. */}
                                <div className="mt-5 flex items-start gap-2.5 rounded-lg border border-steel-100 bg-steel-50/60 p-3.5">
                                    <CalendarClock className="mt-0.5 h-4 w-4 shrink-0 text-graphite-400" />
                                    <p className="text-xs leading-relaxed text-graphite-600">
                                        {subscription.is_lifetime
                                            ? 'Lisensi Anda berlaku selamanya, sehingga tidak ada tagihan perpanjangan yang diterbitkan.'
                                            : `Tagihan perpanjangan diterbitkan sebelum periode berjalan berakhir, dan masa aktif berlanjut segera setelah pembayaran terverifikasi. IOMS tidak pernah menagih kartu tersimpan secara otomatis. Setelah periode berakhir, Anda memiliki ${graceDays} hari akses penuh sebelum pencatatan data baru dijeda — data lama tetap dapat dibuka kapan pun.`}
                                    </p>
                                </div>

                                {!subscription.is_lifetime && (
                                    <div className="mt-4 flex flex-wrap gap-2">
                                        {/* Renewing early is allowed and costs the customer
                                            nothing: the new period is added to the end of
                                            the one they already paid for, never from today. */}
                                        {/* Shown here only when the notice above is not
                                            already offering it -- one renewal button per
                                            screen, not two. */}
                                        {!outstandingInvoice && !renewalRelevant && (
                                            <Button
                                                onClick={() => renew.post(route('subscription.renew'), { preserveScroll: true })}
                                                disabled={renew.processing}
                                            >
                                                {renew.processing ? 'Preparing…' : 'Renew now'}
                                            </Button>
                                        )}
                                        <Button variant="outline" asChild>
                                            <Link href={route('subscription.plans')}>
                                                Change plan <ArrowUpRight className="ml-1 h-3.5 w-3.5" />
                                            </Link>
                                        </Button>
                                    </div>
                                )}
                            </>
                        ) : (
                            <EmptyState
                                icon={CreditCard}
                                title="No subscription on record"
                                description="Organisasi ini belum memiliki langganan. Hubungi kami untuk mengaktifkan paket."
                            />
                        )}
                    </CardContent>
                </Card>

                {/* ---------------- Capacity ---------------- */}
                <Card>
                    <CardHeader><CardTitle>Capacity in use</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <Meter icon={Users} label="User accounts" {...entitlements.users} />
                        {/* v2.54.0: capacity is measured in OPERATING UNITS -- one
                            organization, one subscription, one or more units. */}
                        <Meter icon={Building2} label="Operating Units" {...entitlements.operating_units} />
                        {/* v2.53.0: PTW Access is shown as a COUNT, not a meter.
                            It is a permission granted inside IOMS, not a
                            purchased allowance, so there is no limit to fill. */}
                        <div className="flex items-center justify-between gap-2 border-t border-steel-100 pt-3">
                            <span className="flex items-center gap-1.5 text-xs font-medium text-graphite-600">
                                <ShieldCheck className="h-3.5 w-3.5 text-graphite-400" />
                                PTW Access granted
                            </span>
                            <span className="text-xs font-semibold text-navy-900">{entitlements.ptw_users?.used ?? 0}</span>
                        </div>
                        <p className="text-[11px] leading-relaxed text-graphite-400">
                            Dihitung langsung dari workspace ini. Batas yang kosong berarti paket tidak membatasinya.
                            PTW Access adalah izin yang Anda berikan kepada akun yang sudah ada — bukan kuota yang dibeli.
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
                                description="Tagihan akan muncul di sini setiap kali diterbitkan untuk langganan Anda."
                            />
                        </div>
                    ) : (
                        // A table is the wrong shape for a phone, so it scrolls
                        // horizontally inside its own container rather than
                        // pushing the page sideways.
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Invoice</TableHead>
                                        <TableHead>Period</TableHead>
                                        <TableHead>Due</TableHead>
                                        <TableHead>Paid</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                        <TableHead />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {invoices.map((inv) => (
                                        <TableRow key={inv.id}>
                                            <TableCell className="font-medium text-navy-900">
                                                {inv.invoice_number}
                                                {inv.purpose === 'onboarding' && (
                                                    <span className="block text-[11px] font-normal text-graphite-400">Initial subscription</span>
                                                )}
                                                {inv.purpose === 'plan_change' && (
                                                    <span className="block text-[11px] font-normal text-graphite-400">Plan change</span>
                                                )}
                                            </TableCell>
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
                                            {/* v2.55.0: the invoice as a PDF, on the tenant's own
                                                letterhead-styled document. A plain anchor, not an
                                                Inertia Link -- this response is a file, and routing
                                                it through the SPA would try to render a PDF as a
                                                page. */}
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-3">
                                                    {inv.is_payable && onlinePaymentEnabled && (
                                                        <Link
                                                            href={route('subscription.pay', inv.id)}
                                                            className="text-xs font-medium text-brand-700 hover:underline"
                                                        >
                                                            Pay
                                                        </Link>
                                                    )}
                                                    <a
                                                        href={route('subscription.invoices.pdf', inv.id)}
                                                        target="_blank"
                                                        rel="noopener"
                                                        className="inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline"
                                                    >
                                                        <FileDown className="h-3.5 w-3.5" /> PDF
                                                    </a>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
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
