import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import PublicLayout from '@/Layouts/PublicLayout';
import PublicPageHero from '@/Components/shared/PublicPageHero';
import { Button } from '@/Components/ui/button';
import { ShieldCheck, CreditCard, FileDown, Loader2, ExternalLink } from 'lucide-react';

/**
 * v2.55.0 -- THE IOMS CHECKOUT PAGE.
 *
 * Before this, clicking Pay called `redirect()->away(...)` and the customer
 * left iomsuite.com the same instant. A buyer's last view before entering
 * payment details should say what they are buying, for how long, and for
 * exactly how many rupiah — on the seller's own page.
 *
 * The payment interface itself is unchanged and still the provider's:
 * Snap opens as an overlay on top of this page using a token the SERVER
 * created. Only the client key reaches the browser, and it authorises
 * nothing.
 *
 * THIS PAGE CANNOT ACTIVATE ANYTHING, BY CONSTRUCTION. Every Snap callback
 * below does exactly one thing: navigate to the read-only status page.
 * None of them posts a result, and there is no endpoint that would accept
 * one. A subscription becomes active only when the provider's signed
 * notification reaches the webhook and the server verifies it. Anyone who
 * forges a callback here achieves a page navigation and nothing else.
 */
export default function Checkout({ order, payment, statusUrl, invoiceUrl, billingEmail }) {
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

        // Every outcome lands on the status page, which reads server state.
        // Nothing here reports a result back to IOMS.
        const goToStatus = () => { window.location.href = statusUrl; };

        window.snap.pay(payment.snap_token, {
            onSuccess: goToStatus,
            onPending: goToStatus,
            onError: goToStatus,
            onClose: () => setOpening(false),
        });
    }

    const cycleLabel = order.billing_cycle === 'monthly' ? 'Monthly' : 'Annual';
    const saving = order.annual_saving ?? null;

    return (
        <PublicLayout>
            <Head title={`Payment · ${order.reference}`} />

            <PublicPageHero
                eyebrow={order.reference}
                title="Complete your subscription payment."
                subtitle={order.company_name}
                size="sm"
            />

            <section className="bg-graphite-100 py-12 sm:py-16">
                <div className="mx-auto max-w-3xl space-y-6 px-4 sm:px-6">

                    {/* Order summary. The amount in IDR is the point of this
                        page -- a customer must never reach a payment window
                        without having seen what it is for. */}
                    <div className="rounded-xl border border-steel-200/70 bg-white p-6 shadow-panel">
                        <h2 className="text-[15px] font-semibold tracking-tight text-navy-900">Order summary</h2>

                        <dl className="mt-4 divide-y divide-steel-100 border-y border-steel-100">
                            <SummaryRow label="Plan" value={order.plan_name ? `IOMS ${order.plan_name}` : 'IOMS subscription'} />
                            <SummaryRow label="Billing cycle" value={cycleLabel} />
                            {order.period_start && order.period_end && (
                                <SummaryRow label="Period" value={`${order.period_start} – ${order.period_end}`} />
                            )}
                            <SummaryRow label="Invoice number" value={order.invoice_number} />
                            <SummaryRow label="Organization" value={order.company_name} />
                            <SummaryRow label="Billing email" value={order.contact_email} />
                        </dl>

                        {/* v2.56.0 -- WHAT THE ANNUAL PRICE ACTUALLY SAVES.
                            The order used to show only the final annual figure, so
                            the discount already built into IOMS pricing was invisible
                            at the exact moment the customer decided to pay. The three
                            numbers are shown in the order a buyer checks them: what
                            twelve monthly payments would cost, what they are paying,
                            and the difference. All derived server-side from the
                            plan's own two prices -- no discount is invented here. */}
                        {saving && (
                            <div className="mt-4 rounded-lg border border-success/20 bg-success/[0.06] p-4">
                                <dl className="space-y-1.5">
                                    <div className="flex items-baseline justify-between text-xs text-graphite-600">
                                        <dt>Monthly plan, paid for 12 months</dt>
                                        <dd className="line-through">{saving.monthly_equivalent_formatted}</dd>
                                    </div>
                                    <div className="flex items-baseline justify-between text-xs text-graphite-600">
                                        <dt>Annual price</dt>
                                        <dd className="font-medium text-navy-900">{order.amount}</dd>
                                    </div>
                                    <div className="flex items-baseline justify-between border-t border-success/20 pt-1.5 text-sm font-semibold text-success">
                                        <dt>You save</dt>
                                        <dd>{saving.formatted} · {saving.percent}%</dd>
                                    </div>
                                </dl>
                            </div>
                        )}

                        <div className="mt-5 flex items-baseline justify-between border-t-2 border-navy-900 pt-4">
                            <span className="text-sm font-semibold text-navy-900">Amount due</span>
                            <span className="text-2xl font-semibold tracking-tight text-navy-900">{order.amount}</span>
                        </div>

                        <p className="mt-2 text-xs text-graphite-500">
                            Priced in Indonesian Rupiah. Billed in advance for one full period.
                        </p>
                    </div>

                    {/* Payment */}
                    <div className="rounded-xl border border-steel-200/70 bg-white p-6 shadow-panel">
                        <h2 className="text-[15px] font-semibold tracking-tight text-navy-900">Payment</h2>
                        <p className="mt-2 text-sm leading-relaxed text-graphite-600">
                            Payment is handled by our licensed payment provider. Your card details never pass
                            through or get stored on IOMS systems.
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
                                    <p className="mt-2 text-xs text-graphite-500">
                                        The payment interface could not load on this page, so you will be taken
                                        to our provider&apos;s own payment page instead.
                                    </p>
                                </div>
                            )
                        )}

                        {/* The honest sentence about what actually activates a
                            workspace. A customer who closes the browser mid-payment
                            should know the outcome does not depend on them
                            returning here. */}
                        <div className="mt-5 flex items-start gap-2.5 rounded-lg border border-steel-100 bg-graphite-50 p-3">
                            <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                            <p className="text-xs leading-relaxed text-graphite-600">
                                Your workspace activates once the payment provider confirms the payment to our
                                server — not simply because you reached a confirmation page. You can close this
                                page safely; the status is always available again from your registration page.
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                        <a href={invoiceUrl} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 font-medium text-brand-700 hover:underline">
                            <FileDown className="h-4 w-4" /> Download invoice (PDF)
                        </a>
                        <Link href={statusUrl} className="text-graphite-600 hover:text-graphite-900">
                            View registration status
                        </Link>
                        {billingEmail && (
                            <a href={`mailto:${billingEmail}`} className="text-graphite-600 hover:text-graphite-900">
                                {billingEmail}
                            </a>
                        )}
                    </div>
                </div>
            </section>
        </PublicLayout>
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
