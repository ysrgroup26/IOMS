import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { FieldGrid, Field, DetailSection } from '@/Components/shared/DetailFields';
import SubscribeShell from '@/Pages/Subscribe/Shell';
import { ArrowRight, ArrowLeft, Loader2, Pencil, Building2, User, ReceiptText } from 'lucide-react';

/**
 * v2.74.0 -- STEP 3 OF 4: THE ORDER SUMMARY.
 *
 * The last IOMS-owned page before the payment provider, and the one a
 * buyer is entitled to: what is being bought, for which organization, for
 * how long, and for exactly how much -- on the seller's own site, before
 * any card details are entered anywhere.
 *
 * That principle is not new here. v2.55.0 introduced it for the public
 * onboarding flow, after checkout used to redirect straight off
 * iomsuite.com the instant Pay was clicked. This is the same rule applied
 * to the account-initiated flow.
 *
 * NOTHING ON THIS PAGE ACTIVATES ANYTHING. Its single action posts to the
 * EXISTING `register.checkout` endpoint, which raises the invoice and
 * opens the gateway session. Provisioning still happens only in
 * PaymentWebhookController, from a payload the provider signed and this
 * server verified. Unchanged, and deliberately so: there must be exactly
 * one way a tenant comes into existence.
 */
export default function SubscribeSummary({ account, order, plan, amountFormatted, editUrl, checkoutUrl }) {
    // A bare POST to the existing checkout endpoint. `useForm` with an
    // empty payload is the simplest thing that carries CSRF correctly.
    const { post, processing } = useForm({});

    function pay(e) {
        e.preventDefault();
        post(checkoutUrl);
    }

    return (
        <SubscribeShell
            step={3}
            account={account}
            heading="Review your order"
            subheading="Periksa kembali sebelum melanjutkan ke pembayaran."
            aside={
                <Card className="lg:sticky lg:top-6">
                    <CardContent className="space-y-4 py-4">
                        <div>
                            <p className="text-[10px] font-semibold uppercase tracking-wide text-graphite-400">Order</p>
                            <p className="mt-0.5 font-mono text-[13px] font-semibold tabular-nums text-navy-900">
                                {order.reference}
                            </p>
                        </div>

                        <div className="space-y-2 border-t border-graphite-100 pt-3">
                            <div className="flex items-baseline justify-between gap-3">
                                <span className="min-w-0 text-xs text-graphite-600">
                                    {plan?.name}
                                    <span className="text-graphite-400">
                                        {' '}&middot; {order.billing_cycle === 'monthly' ? 'Bulanan' : 'Tahunan'}
                                    </span>
                                </span>
                                <span className="shrink-0 text-[13px] font-medium text-navy-900">{amountFormatted}</span>
                            </div>
                        </div>

                        <div className="flex items-baseline justify-between border-t border-graphite-100 pt-3">
                            <span className="text-[13px] font-semibold text-navy-900">Total</span>
                            <span className="text-[20px] font-semibold tracking-tight text-navy-900">{amountFormatted}</span>
                        </div>

                        <form onSubmit={pay}>
                            <Button type="submit" className="w-full" disabled={processing}>
                                {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                                Continue to payment <ArrowRight className="h-4 w-4" />
                            </Button>
                        </form>

                        {/* Says exactly what the next click does and what it
                            does not. A buyer about to be handed to a payment
                            provider should not be guessing when their
                            workspace appears. */}
                        <p className="text-[11px] leading-relaxed text-graphite-500">
                            Faktur diterbitkan dan Anda diarahkan ke penyedia pembayaran. Ruang kerja IOMS
                            dibuat setelah pembayaran dikonfirmasi oleh penyedia pembayaran, bukan saat
                            Anda kembali ke halaman ini.
                        </p>
                    </CardContent>
                </Card>
            }
        >
            <Head title="Review your order" />

            <Card>
                <CardContent className="space-y-5 pt-5">
                    <DetailSection
                        index="01"
                        title="Account"
                        icon={User}
                        description="Dari akun Anda. Tidak diminta ulang."
                    >
                        <FieldGrid columns={2}>
                            <Field label="Name" value={account.name} />
                            <Field label="Email" value={account.email} />
                        </FieldGrid>
                    </DetailSection>

                    <DetailSection
                        index="02"
                        title="Organization"
                        icon={Building2}
                        action={editUrl ? (
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={editUrl}><Pencil className="h-3.5 w-3.5" /> Edit</Link>
                            </Button>
                        ) : null}
                    >
                        <FieldGrid columns={2}>
                            <Field label="Organization" value={order.organization} emphasis />
                            <Field label="Legal name" value={order.legal_name} />
                            <Field label="Industry" value={order.industry} />
                            <Field label="Billing email" value={order.billing_email} />
                            <Field label="Address" value={order.address} span={2} />
                        </FieldGrid>
                    </DetailSection>

                    <DetailSection index="03" title="Plan" icon={ReceiptText}>
                        <FieldGrid columns={2}>
                            <Field label="Plan" value={plan?.name} emphasis />
                            <Field label="Billing" value={order.billing_cycle === 'monthly' ? 'Bulanan' : 'Tahunan'} />
                            <Field label="Amount" value={amountFormatted} emphasis />
                            <Field label="Reference" value={order.reference} mono />
                        </FieldGrid>
                    </DetailSection>
                </CardContent>
            </Card>

            <div className="mt-4">
                <Button variant="ghost" size="sm" asChild>
                    <Link href={route('account.overview')}><ArrowLeft className="h-4 w-4" /> Back to account</Link>
                </Button>
            </div>
        </SubscribeShell>
    );
}
