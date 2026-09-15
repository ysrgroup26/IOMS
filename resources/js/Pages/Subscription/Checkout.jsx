import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { ShieldCheck, CreditCard, FileDown, Loader2, ExternalLink, Receipt } from 'lucide-react';

/**
 * v2.70.0 -- THE SIGNED-IN CHECKOUT PAGE.
 *
 * The authenticated twin of the onboarding checkout, and deliberately the
 * same shape: IOMS states what is being bought, for which period, for
 * exactly how many rupiah, on its own page — then opens the provider's
 * payment interface as an overlay using a token the SERVER created. Only
 * the client key reaches the browser, and it authorises nothing.
 *
 * THIS PAGE CANNOT EXTEND A SUBSCRIPTION, BY CONSTRUCTION. Every Snap
 * callback below does exactly one thing: navigate to the read-only
 * billing page. None of them posts a result, and there is no endpoint
 * that would accept one. A period moves only when the provider's signed
 * notification reaches the webhook and the server verifies it. Anyone who
 * forges a callback here achieves a page navigation and nothing else.
 */
export default function Checkout({ order, payment, billingUrl, invoiceUrl, billingEmail }) {
    const [snapReady, setSnapReady] = useState(false);
    const [scriptFailed, setScriptFailed] = useState(false);
    const [opening, setOpening] = useState(false);

    useEffect(() => {
        if (!payment?.snap_script || !payment?.client_key) {
            setScriptFailed(true);
            return;
        }

        if (window.snap) {
            setSnapReady(true);
            return;
        }

        const script = document.createElement('script');
        script.src = payment.snap_script;
        script.setAttribute('data-client-key', payment.client_key);
        script.async = true;
        script.onload = () => setSnapReady(true);
        // A blocked or unreachable script is a real possibility on a
        // corporate network. Rather than leaving a dead button, the page
        // falls back to the provider's own hosted page.
        script.onerror = () => setScriptFailed(true);
        document.body.appendChild(script);

        return () => {
            script.onload = null;
            script.onerror = null;
        };
    }, [payment?.snap_script, payment?.client_key]);

    function pay() {
        if (!window.snap || !payment?.snap_token) {
            setScriptFailed(true);
            return;
        }

        setOpening(true);

        // Every outcome lands on the billing page, which reads server
        // state. Nothing here reports a result back to IOMS.
        const goToBilling = () => { window.location.href = billingUrl; };

        window.snap.pay(payment.snap_token, {
            onSuccess: goToBilling,
            onPending: goToBilling,
            onError: goToBilling,
            onClose: () => setOpening(false),
        });
    }

    const isPlanChange = order.purpose === 'plan_change';
    const cycleLabel = order.billing_cycle === 'monthly' ? 'Monthly' : 'Annual';

    return (
        <AuthenticatedLayout>
            <Head title={`Payment · ${order.invoice_number}`} />

            <PageHeader
                icon={Receipt}
                title={isPlanChange ? 'Pay for your plan change' : 'Renew your subscription'}
                subtitle="Rincian tagihan Anda sebelum melanjutkan ke pembayaran."
            >
                <Button variant="outline" asChild>
                    <Link href={billingUrl}>Back to Billing</Link>
                </Button>
            </PageHeader>

            <div className="mx-auto max-w-3xl space-y-4">
                {/* Order summary. The amount in IDR is the point of this
                    page -- nobody should reach a payment window without
                    having seen what it is for. */}
                <div className="rounded-xl border border-steel-200/70 bg-white p-5 shadow-panel sm:p-6">
                    <h2 className="text-[15px] font-semibold tracking-tight text-navy-900">Order summary</h2>

                    <dl className="mt-4 divide-y divide-steel-100 border-y border-steel-100">
                        <SummaryRow label="Organization" value={order.organization_name} />
                        <SummaryRow label="Plan" value={order.plan_name ? `IOMS ${order.plan_name}` : 'IOMS subscription'} />
                        <SummaryRow label="Billing cycle" value={cycleLabel} />
                        {order.period_start && (
                            <SummaryRow label="Period" value={`${order.period_start} – ${order.period_end}`} />
                        )}
                        <SummaryRow label="Invoice number" value={order.invoice_number} />
                    </dl>

                    {order.notes && (
                        <p className="mt-3 text-xs leading-relaxed text-graphite-500">{order.notes}</p>
                    )}

                    <div className="mt-5 flex flex-wrap items-baseline justify-between gap-2 border-t-2 border-navy-900 pt-4">
                        <span className="text-sm font-semibold text-navy-900">Amount due</span>
                        <span className="text-2xl font-semibold tracking-tight text-navy-900">{order.amount}</span>
                    </div>

                    <p className="mt-2 text-xs leading-relaxed text-graphite-500">
                        {isPlanChange
                            ? 'Dihitung proporsional untuk sisa masa aktif periode berjalan.'
                            : 'Ditagihkan di muka untuk satu periode penuh. Masa aktif baru ditambahkan setelah periode yang sedang berjalan, sehingga membayar lebih awal tidak mengurangi hak Anda.'}
                    </p>
                </div>

                {/* Payment */}
                <div className="rounded-xl border border-steel-200/70 bg-white p-5 shadow-panel sm:p-6">
                    <h2 className="text-[15px] font-semibold tracking-tight text-navy-900">Payment</h2>
                    <p className="mt-2 text-sm leading-relaxed text-graphite-600">
                        Pembayaran diproses oleh penyedia pembayaran berlisensi kami. Data kartu Anda tidak pernah
                        melewati atau tersimpan di sistem IOMS.
                    </p>

                    {!scriptFailed ? (
                        <Button className="mt-4 w-full sm:w-auto" onClick={pay} disabled={!snapReady || opening}>
                            {!snapReady || opening
                                ? <><Loader2 className="h-4 w-4 animate-spin" /> Preparing payment…</>
                                : <><CreditCard className="h-4 w-4" /> Pay {order.amount}</>}
                        </Button>
                    ) : (
                        payment?.fallback_url && (
                            <div className="mt-4">
                                <Button asChild className="w-full sm:w-auto">
                                    <a href={payment.fallback_url}>
                                        <ExternalLink className="h-4 w-4" /> Continue to payment page
                                    </a>
                                </Button>
                                <p className="mt-2 text-xs leading-relaxed text-graphite-500">
                                    Antarmuka pembayaran tidak dapat dimuat di halaman ini, sehingga Anda akan
                                    diarahkan ke halaman pembayaran penyedia kami.
                                </p>
                            </div>
                        )
                    )}

                    {/* The honest sentence about what actually extends a
                        subscription. A customer who closes the browser
                        mid-payment should know the outcome does not depend on
                        them returning here. */}
                    <div className="mt-5 flex items-start gap-2.5 rounded-lg border border-steel-100 bg-graphite-50 p-3">
                        <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                        <p className="text-xs leading-relaxed text-graphite-600">
                            Masa aktif diperpanjang setelah penyedia pembayaran mengonfirmasi pembayaran ke server
                            kami — bukan karena Anda tiba di halaman konfirmasi. Anda dapat menutup halaman ini
                            dengan aman; statusnya selalu dapat dilihat kembali di halaman Billing.
                        </p>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    <a href={invoiceUrl} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 font-medium text-brand-700 hover:underline">
                        <FileDown className="h-4 w-4" /> Download invoice (PDF)
                    </a>
                    {billingEmail && (
                        <a href={`mailto:${billingEmail}`} className="text-graphite-600 hover:text-graphite-900">
                            {billingEmail}
                        </a>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function SummaryRow({ label, value }) {
    if (!value) return null;

    return (
        <div className="flex items-baseline justify-between gap-4 py-2.5">
            <dt className="text-sm text-graphite-500">{label}</dt>
            <dd className="text-right text-sm font-medium text-navy-900">{value}</dd>
        </div>
    );
}
