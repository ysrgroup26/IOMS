import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Check, Sparkles } from 'lucide-react';

/**
 * v2.14.0 (SaaS Productization / Pricing Foundation, Part 8/9). The
 * tenant-facing Plans/pricing comparison page -- entirely data-driven
 * from `PricingService::publicPlans()` (see SettingsController::plans()).
 * No amount is ever written into this component; every number/label
 * shown here comes from the `plans`/`currentPlan` props exactly as the
 * backend formatted them, so this page can never drift from what a
 * Platform Admin actually configured in Platform > Plans.
 *
 * Deliberately NO checkout/payment action anywhere on this page (see this
 * phase's own "DO NOT create fake payment buttons" rule) -- the CTA is a
 * plain, honest "Hubungi administrator untuk upgrade" message. Upgrading
 * a tenant's plan remains a Platform Admin action
 * (PlatformController::updateSubscription()) until a later, explicitly
 * separate Checkout/Billing phase.
 */
export default function SubscriptionPlans({ plans, currentPlan, currentPlanId }) {
    const [interval, setInterval] = useState('monthly');

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
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {plans.map((plan) => {
                        const isCurrent = plan.id === currentPlanId;
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
                                            <span>Maks. Perusahaan</span>
                                            <span className="font-medium text-graphite-700 dark:text-slate-300">{plan.max_companies ?? 'Tanpa batas'}</span>
                                        </div>
                                    </div>

                                    {plan.workspaces.length > 0 && (
                                        <div className="space-y-1.5 border-t border-graphite-100 pt-3 dark:border-slate-800">
                                            <p className="text-xs font-medium uppercase tracking-wide text-graphite-400">Departemen</p>
                                            <ul className="space-y-1 text-sm">
                                                {plan.workspaces.map((label) => (
                                                    <li key={label} className="flex items-center gap-1.5 text-graphite-700 dark:text-slate-300">
                                                        <Check className="h-3.5 w-3.5 shrink-0 text-emerald-600" /> {label}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}

                                    <div className="border-t border-graphite-100 pt-3 dark:border-slate-800">
                                        {isCurrent ? (
                                            <Button className="w-full" variant="outline" disabled>Paket Aktif Anda</Button>
                                        ) : (
                                            <Button className="w-full" variant="outline" disabled title="Hubungi administrator perusahaan Anda untuk mengubah paket.">
                                                Hubungi Administrator untuk Upgrade
                                            </Button>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
