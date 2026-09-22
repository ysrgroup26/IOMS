import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import {
    Dialog, DialogContent, DialogHeader, DialogFooter, DialogTitle, DialogDescription,
} from '@/Components/ui/dialog';
import { Check, Sparkles } from 'lucide-react';

/**
 * v2.14.0 (SaaS Productization / Pricing Foundation, Part 8/9). The
 * tenant-facing Plans/pricing comparison page -- entirely data-driven
 * from `PricingService::publicPlans()` (see SubscriptionController::plans()).
 * No amount is ever written into this component; every number/label
 * shown here comes from the `plans`/`currentPlan` props exactly as the
 * backend formatted them, so this page can never drift from what a
 * Platform Admin actually configured in Platform > Plans.
 *
 * v2.70.0 -- THE CTA IS NOW A REAL ACTION.
 *
 * It used to be a disabled button reading "Hubungi Administrator untuk
 * Upgrade", which was the honest thing to show while no self-service plan
 * change existed. One does now, so the button does what it says.
 *
 * IT STILL DOES NOT TAKE MONEY. Choosing a plan posts a request; the
 * server decides whether that is an upgrade (a prorated invoice, applied
 * when paid) or a downgrade/cycle change (scheduled for the period
 * boundary, so nobody loses capacity they already paid for and no tenant
 * is dropped below the seats it is using). The browser never sends an
 * amount and never confirms a payment.
 *
 * An account that cannot manage billing still sees the comparison and is
 * told plainly who can act on it, rather than a button that would 403.
 */
export default function SubscriptionPlans({ plans, currentPlan, currentPlanId, currentCycle, canManageBilling, salesEmail, changePreview = {} }) {
    const [interval, setInterval] = useState(currentCycle === 'yearly' ? 'yearly' : 'monthly');
    const [confirming, setConfirming] = useState(null);

    // What confirming will actually do, for the plan and cycle in question
    // -- decided by the server (SubscriptionController::changePreview()).
    const preview = confirming ? changePreview?.[confirming.id]?.[interval] : null;
    const fmtDate = (d) => (d ? new Date(d).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' }) : '—');

    const form = useForm({ package_id: null, billing_cycle: 'monthly' });

    const submitChange = () => {
        // `transform()` returns void in Inertia's React adapter, so it
        // cannot be chained -- doing so throws before the request is ever
        // made, silently, with the dialog left open as though nothing had
        // been clicked. Set it, then post.
        form.transform(() => ({ package_id: confirming.id, billing_cycle: interval }));
        form.post(route('subscription.plan-change'), {
            preserveScroll: true,
            onSuccess: () => setConfirming(null),
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Plans" />

            <PageHeader
                title="Paket Berlangganan"
                subtitle="Bandingkan paket IOMS dan lihat modul/departemen apa saja yang tercakup di setiap paket."
            >
                <div className="inline-flex items-center rounded-lg border border-graphite-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-900">
                    <button
                        type="button"
                        onClick={() => setInterval('monthly')}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${interval === 'monthly' ? 'bg-graphite-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-graphite-500 dark:text-slate-400'}`}
                    >
                        Bulanan
                    </button>
                    <button
                        type="button"
                        onClick={() => setInterval('yearly')}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${interval === 'yearly' ? 'bg-graphite-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-graphite-500 dark:text-slate-400'}`}
                    >
                        Tahunan
                    </button>
                </div>
            </PageHeader>

            {currentPlan && (
                <div className="mb-4 flex items-center gap-2 rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-300">
                    <Sparkles className="h-4 w-4 shrink-0" />
                    Paket perusahaan Anda saat ini: <span className="font-semibold">{currentPlan.name}</span>
                </div>
            )}

            {plans.length === 0 ? (
                <Card><CardContent className="p-8 text-center text-sm text-graphite-400">Belum ada paket yang tersedia untuk ditampilkan.</CardContent></Card>
            ) : (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {plans.map((plan) => {
                        const isCurrentPlan = plan.id === currentPlanId;
                        // Same plan on the SAME cycle is where they already
                        // are. Same plan on a different cycle is a real,
                        // requestable change, so it must not read as "your
                        // current plan" and be disabled.
                        const isCurrent = isCurrentPlan && interval === currentCycle;
                        const price = interval === 'monthly' ? plan.monthly : plan.yearly;

                        return (
                            <Card key={plan.id} className={isCurrent ? 'border-2 border-graphite-900 dark:border-slate-100' : ''}>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <CardTitle>{plan.name}</CardTitle>
                                        {isCurrent && <Badge variant="success">Paket Anda</Badge>}
                                    </div>
                                    <CardDescription>{plan.description}</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div>
                                        <div className="text-2xl font-semibold tracking-tight text-graphite-900 dark:text-slate-50">
                                            {price.formatted}
                                        </div>
                                        {!plan.is_custom && price.amount !== null && (
                                            <div className="text-xs text-graphite-400">
                                                / {interval === 'monthly' ? 'bulan' : 'tahun'}
                                            </div>
                                        )}
                                        {plan.trial_days ? (
                                            <div className="mt-1 text-xs text-graphite-500">Uji coba gratis {plan.trial_days} hari</div>
                                        ) : null}
                                    </div>

                                    <div className="space-y-1.5 border-t border-graphite-100 pt-3 text-sm dark:border-slate-800">
                                        <div className="flex justify-between text-graphite-500">
                                            <span>Maks. Pengguna</span>
                                            <span className="font-medium text-graphite-700 dark:text-slate-300">{plan.max_users ?? 'Tanpa batas'}</span>
                                        </div>
                                        <div className="flex justify-between text-graphite-500">
                                            <span>Operating Units</span>
                                            <span className="font-medium text-graphite-700 dark:text-slate-300">{plan.max_companies ?? 'Tanpa batas'}</span>
                                        </div>
                                    </div>

                                    {/* v2.60.0: this list is headed "Departemen", so it
                                        shows departments -- it used to include Reports and
                                        Administration, which are neither departments nor
                                        something a plan grants or withholds. */}
                                    {(plan.department_workspaces ?? []).length > 0 && (
                                        <div className="space-y-1.5 border-t border-graphite-100 pt-3 dark:border-slate-800">
                                            <p className="text-xs font-medium uppercase tracking-wide text-graphite-400">Departemen</p>
                                            <ul className="space-y-1 text-sm">
                                                {plan.department_workspaces.map((label) => (
                                                    <li key={label} className="flex items-center gap-1.5 text-graphite-700 dark:text-slate-300">
                                                        <Check className="h-3.5 w-3.5 shrink-0 text-emerald-600" /> {label}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}

                                    <div className="border-t border-graphite-100 pt-3 dark:border-slate-800">
                                        {isCurrent ? (
                                            <Button className="w-full" variant="outline" disabled>Current plan</Button>
                                        ) : plan.is_custom ? (
                                            // A custom-priced plan has no figure to
                                            // invoice, so there is nothing honest for a
                                            // self-service button to do.
                                            <Button className="w-full" variant="outline" asChild>
                                                <a href={`mailto:${salesEmail}`}>Contact sales</a>
                                            </Button>
                                        ) : canManageBilling ? (
                                            <Button
                                                className="w-full"
                                                variant={isCurrentPlan ? 'outline' : 'default'}
                                                onClick={() => setConfirming(plan)}
                                            >
                                                {isCurrentPlan
                                                    ? `Switch to ${interval === 'monthly' ? 'monthly' : 'annual'}`
                                                    : 'Choose this plan'}
                                            </Button>
                                        ) : (
                                            <Button className="w-full" variant="outline" disabled title="Hanya Administrator organisasi yang dapat mengubah paket.">
                                                Administrator only
                                            </Button>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            )}

            {/* The confirmation has to be honest about WHEN the change takes
                effect, because the two answers are genuinely different and
                the customer is about to commit money to one of them.

                v2.78.1: the server now says which it is (changePreview,
                computed by the same calls that act on the change), so the
                dialog states ONE outcome -- with the exact prorated amount
                for an upgrade, or the effective date for a scheduled change.
                The two-outcome explanation remains only as a fallback for a
                plan the server could not preview. */}
            <Dialog open={confirming !== null} onOpenChange={(open) => !open && setConfirming(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Change plan</DialogTitle>
                        <DialogDescription>
                            Pindah ke <span className="font-semibold">{confirming?.name}</span>{' '}
                            ({interval === 'monthly' ? 'bulanan' : 'tahunan'}).
                        </DialogDescription>
                    </DialogHeader>

                    {preview?.kind === 'upgrade' ? (
                        <div className="space-y-2 text-sm leading-relaxed text-graphite-600">
                            <div className="flex items-baseline justify-between gap-3 rounded-lg border border-brand-200 bg-brand-50/50 p-3">
                                <span className="font-medium text-navy-900">Upgrade — prorated invoice</span>
                                <span className="text-base font-semibold tabular-nums text-navy-900">{preview.amount_formatted}</span>
                            </div>
                            <p>
                                Tagihan ini untuk sisa masa aktif hingga {fmtDate(preview.period_ends_at)}. Paket{' '}
                                {confirming?.name} berlaku segera setelah pembayaran terverifikasi; sampai saat itu paket
                                Anda saat ini tetap berjalan seperti biasa.
                            </p>
                            {preview.cycle_change_at && (
                                <p>
                                    Penagihan {preview.cycle === 'yearly' ? 'tahunan' : 'bulanan'} dimulai pada{' '}
                                    {fmtDate(preview.cycle_change_at)}, saat periode berjalan berakhir.
                                </p>
                            )}
                            <p>Data operasional Anda tidak terpengaruh oleh perubahan paket.</p>
                        </div>
                    ) : preview?.kind === 'scheduled' ? (
                        <div className="space-y-2 text-sm leading-relaxed text-graphite-600">
                            <div className="flex items-baseline justify-between gap-3 rounded-lg border border-steel-200 bg-steel-50/60 p-3">
                                <span className="font-medium text-navy-900">Scheduled change</span>
                                <span className="font-semibold text-navy-900">{fmtDate(preview.effective_at)}</span>
                            </div>
                            <p>
                                Perubahan berlaku pada akhir periode yang sudah Anda bayar. Tidak ada tagihan hari ini, dan
                                tidak ada akses yang berkurang lebih cepat. Perubahan ini dapat dibatalkan dari halaman Billing.
                            </p>
                            <p>Data operasional Anda tidak terpengaruh oleh perubahan paket.</p>
                        </div>
                    ) : (
                        <ul className="space-y-2 text-sm leading-relaxed text-graphite-600">
                            <li>
                                <span className="font-medium text-navy-900">Jika ini peningkatan paket:</span>{' '}
                                tagihan proporsional untuk sisa masa aktif periode berjalan akan diterbitkan, dan
                                paket baru berlaku setelah pembayaran terverifikasi.
                            </li>
                            <li>
                                <span className="font-medium text-navy-900">Jika ini penurunan paket atau perubahan siklus:</span>{' '}
                                perubahan dijadwalkan pada akhir periode yang sudah Anda bayar. Tidak ada tagihan
                                hari ini, dan tidak ada akses yang berkurang lebih cepat.
                            </li>
                            <li>Data operasional Anda tidak terpengaruh oleh perubahan paket.</li>
                        </ul>
                    )}

                    {form.errors.package_id && (
                        <p className="rounded-md border border-danger/25 bg-danger/[0.07] p-3 text-sm leading-relaxed text-red-900">
                            {form.errors.package_id}
                        </p>
                    )}

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirming(null)} disabled={form.processing}>
                            Cancel
                        </Button>
                        <Button onClick={submitChange} disabled={form.processing}>
                            {form.processing
                                ? 'Working…'
                                : preview?.kind === 'upgrade'
                                    ? 'Continue to payment'
                                    : preview?.kind === 'scheduled' ? 'Schedule change' : 'Confirm change'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
