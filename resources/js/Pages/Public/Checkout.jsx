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

    const cycleLabel = order.billing_cycle === 'monthly' ? 'Bulanan' : 'Tahunan';

    return (
        <PublicLayout>
            <Head title={`Pembayaran ${order.reference}`} />

            <PublicPageHero
                eyebrow={order.reference}
                title="Selesaikan pembayaran langganan Anda."
                subtitle={order.company_name}
                size="sm"
            />

            <section className="bg-graphite-100 py-12 sm:py-16">
                <div className="mx-auto max-w-3xl space-y-6 px-4 sm:px-6">

                    {/* Order summary. The amount in IDR is the point of this
                        page -- a customer must never reach a payment window
                        without having seen what it is for. */}
                    <div className="rounded-xl border border-steel-200/70 bg-white p-6 shadow-panel">
                        <h2 className="text-[15px] font-semibold tracking-tight text-navy-900">Ringkasan pesanan</h2>

                        <dl className="mt-4 divide-y divide-steel-100 border-y border-steel-100">
                            <SummaryRow label="Paket" value={order.plan_name ? `IOMS ${order.plan_name}` : 'Langganan IOMS'} />
                            <SummaryRow label="Siklus penagihan" value={cycleLabel} />
                            {order.period_start && order.period_end && (
                                <SummaryRow label="Periode" value={`${order.period_start} – ${order.period_end}`} />
                            )}
                            <SummaryRow label="Nomor invoice" value={order.invoice_number} />
                            <SummaryRow label="Organisasi" value={order.company_name} />
                            <SummaryRow label="Email penagihan" value={order.contact_email} />
                        </dl>

                        <div className="mt-5 flex items-baseline justify-between border-t-2 border-navy-900 pt-4">
                            <span className="text-sm font-semibold text-navy-900">Total tagihan</span>
                            <span className="text-2xl font-semibold tracking-tight text-navy-900">{order.amount}</span>
                        </div>

                        <p className="mt-2 text-xs text-graphite-500">
                            Harga dalam Rupiah. Ditagihkan di muka untuk satu periode penuh.
                        </p>
                    </div>

                    {/* Payment */}
                    <div className="rounded-xl border border-steel-200/70 bg-white p-6 shadow-panel">
                        <h2 className="text-[15px] font-semibold tracking-tight text-navy-900">Pembayaran</h2>
                        <p className="mt-2 text-sm leading-relaxed text-graphite-600">
                            Pembayaran diproses oleh penyedia pembayaran resmi kami. Data kartu Anda tidak pernah
                            melewati maupun tersimpan di sistem IOMS.
                        </p>

                        {!scriptFailed ? (
                            <Button className="mt-4 w-full sm:w-auto" onClick={pay} disabled={!snapReady || opening}>
                                {!snapReady || opening
                                    ? <><Loader2 className="h-4 w-4 animate-spin" /> Menyiapkan pembayaran…</>
                                    : <><CreditCard className="h-4 w-4" /> Bayar {order.amount}</>}
                            </Button>
                        ) : (
                            payment?.fallback_url && (
                                <div className="mt-4">
                                    <Button asChild className="w-full sm:w-auto">
                                        <a href={payment.fallback_url}>
                                            <ExternalLink className="h-4 w-4" /> Lanjutkan ke halaman pembayaran
                                        </a>
                                    </Button>
                                    <p className="mt-2 text-xs text-graphite-500">
                                        Antarmuka pembayaran tidak dapat dimuat di halaman ini, jadi Anda akan
                                        diarahkan ke halaman pembayaran penyedia kami.
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
                                Workspace Anda aktif setelah pembayaran dikonfirmasi oleh penyedia pembayaran
                                kepada server kami — bukan sekadar karena Anda sampai di halaman konfirmasi.
                                Anda dapat menutup halaman ini dengan aman; statusnya selalu dapat dilihat
                                kembali di halaman pendaftaran.
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                        <a href={invoiceUrl} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 font-medium text-brand-700 hover:underline">
                            <FileDown className="h-4 w-4" /> Unduh invoice (PDF)
                        </a>
                        <Link href={statusUrl} className="text-graphite-600 hover:text-graphite-900">
                            Lihat status pendaftaran
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
