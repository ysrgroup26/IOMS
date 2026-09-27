import { Head, Link, router } from '@inertiajs/react';
import { CreditCard, ArrowRight } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';

/*
 * v2.80.0 -- THE PAYMENT LEDGER, READ FROM THE PAYMENT END.
 *
 * Bahasa Indonesia, like the rest of Master Admin (ADR 040).
 *
 * The chain runs payment -> tagihan -> langganan -> tenant, and this page
 * exists because it was previously only walkable in the other direction. A
 * customer quotes a provider reference; an operator needs the tenant.
 *
 * Every attempt is listed, including failed and expired ones, because those
 * are the rows an operator is asked about. Nothing here settles anything --
 * that stays with the signature-verified webhook.
 */

const STATUS = {
    paid: { label: 'Berhasil', variant: 'success' },
    pending: { label: 'Menunggu', variant: 'secondary' },
    failed: { label: 'Gagal', variant: 'destructive' },
    expired: { label: 'Kedaluwarsa', variant: 'secondary' },
    refunded: { label: 'Dikembalikan', variant: 'warning' },
};

const LIFECYCLE = {
    active: { label: 'Aktif', variant: 'success' },
    grace: { label: 'Masa tenggang', variant: 'warning' },
    lapsed: { label: 'Read-only', variant: 'destructive' },
    suspended: { label: 'Ditangguhkan', variant: 'destructive' },
    cancelled: { label: 'Dibatalkan', variant: 'secondary' },
};

const PURPOSE = {
    onboarding: 'Aktivasi awal',
    renewal: 'Perpanjangan',
    plan_change: 'Perubahan paket',
};

const money = (amount, currency) =>
    (currency === 'IDR' || !currency ? 'Rp' : currency + ' ') +
    Math.round(Number(amount || 0)).toLocaleString('id-ID');

const waktu = (d) =>
    d
        ? new Date(d).toLocaleString('id-ID', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '—';

export default function PlatformPayments({ transactions = [], filter = 'all', statuses = [], gateway }) {
    function setFilter(value) {
        router.get(route('platform.payments'), value === 'all' ? {} : { status: value }, {
            preserveState: true,
            replace: true,
        });
    }

    return (
        <PlatformLayout>
            <Head title="Pembayaran" />

            <div className="mb-6">
                <h1 className="text-[22px] font-semibold tracking-tight text-navy-900">Pembayaran</h1>
                <p className="mt-1 text-sm text-graphite-500">
                    Setiap percobaan pembayaran, termasuk yang gagal. Dari sini pembayaran dapat ditelusuri ke tagihan,
                    langganan, dan tenant pemiliknya.
                    {gateway ? ` Penyedia aktif: ${gateway}.` : ' Belum ada penyedia pembayaran yang aktif.'}
                </p>
            </div>

            <div className="mb-4 flex flex-wrap gap-2">
                {['all', ...statuses].map((value) => (
                    <button
                        key={value}
                        onClick={() => setFilter(value)}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                            filter === value
                                ? 'bg-brand-50 text-brand-700'
                                : 'text-graphite-600 hover:bg-graphite-100'
                        }`}
                    >
                        {value === 'all' ? 'Semua' : (STATUS[value]?.label ?? value)}
                    </button>
                ))}
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Riwayat Transaksi</CardTitle>
                </CardHeader>
                <CardContent>
                    {transactions.length ? (
                        <div className="divide-y divide-graphite-100">
                            {transactions.map((t) => (
                                <div key={t.id} className="py-3.5">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-semibold text-graphite-900">
                                                    {money(t.amount, t.currency)}
                                                </span>
                                                <Badge variant={STATUS[t.status]?.variant ?? 'secondary'}>
                                                    {STATUS[t.status]?.label ?? t.status}
                                                </Badge>
                                                <span className="rounded bg-graphite-100 px-1.5 py-0.5 text-[11px] font-medium uppercase tracking-wide text-graphite-600">
                                                    {t.gateway}
                                                </span>
                                            </div>

                                            {/* The chain, spelled out rather than implied. */}
                                            <p className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-graphite-500">
                                                {t.invoice ? (
                                                    <span className="font-medium text-graphite-700">{t.invoice.number}</span>
                                                ) : (
                                                    <span className="text-graphite-400">Tagihan tidak ditemukan</span>
                                                )}
                                                {t.invoice?.purpose && (
                                                    <>
                                                        <ArrowRight className="h-3 w-3 text-graphite-300" />
                                                        <span>{PURPOSE[t.invoice.purpose] ?? t.invoice.purpose}</span>
                                                    </>
                                                )}
                                                {t.tenant && (
                                                    <>
                                                        <ArrowRight className="h-3 w-3 text-graphite-300" />
                                                        <Link
                                                            href={route('platform.tenants.show', t.tenant.id)}
                                                            className="font-medium text-brand-600 hover:underline"
                                                        >
                                                            {t.tenant.name}
                                                        </Link>
                                                    </>
                                                )}
                                            </p>

                                            <p className="mt-1 text-[11px] text-graphite-400">
                                                {t.reference ? `Ref ${t.reference} · ` : ''}
                                                {waktu(t.updated_at)}
                                            </p>
                                        </div>

                                        {/* Where that tenant's subscription stands TODAY -- the same
                                            snapshot the customer's own Billing page renders. */}
                                        {t.subscription && (
                                            <div className="text-right">
                                                <Badge variant={LIFECYCLE[t.subscription.lifecycle_state]?.variant ?? 'secondary'}>
                                                    {LIFECYCLE[t.subscription.lifecycle_state]?.label ?? t.subscription.lifecycle_state}
                                                </Badge>
                                                <p className="mt-1 text-[11px] text-graphite-400">
                                                    {t.subscription.plan_name ?? 'Tanpa paket'}
                                                    {t.subscription.period_ends_at
                                                        ? ` · s.d. ${t.subscription.period_ends_at}`
                                                        : ''}
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="flex flex-col items-center gap-2 py-10 text-center">
                            <CreditCard className="h-6 w-6 text-graphite-300" />
                            <p className="text-sm text-graphite-500">
                                {filter === 'all'
                                    ? 'Belum ada percobaan pembayaran yang tercatat.'
                                    : 'Tidak ada transaksi dengan status tersebut.'}
                            </p>
                        </div>
                    )}
                </CardContent>
            </Card>
        </PlatformLayout>
    );
}
