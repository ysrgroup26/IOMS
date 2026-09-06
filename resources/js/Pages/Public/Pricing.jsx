import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Check, ArrowRight, ShieldCheck } from 'lucide-react';
import PublicLayout from '@/Layouts/PublicLayout';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * v2.50.0 -- Pricing as a real page.
 *
 * It previously existed only as a section inside the landing page, so
 * "View Pricing" could only be an anchor scroll and there was no URL to
 * send a prospect to. Same PricingService payload the landing section and
 * the authenticated Plans page already use -- one price source, three
 * surfaces, so a price can never disagree with itself.
 *
 * B2B, not consumer: no countdown timers, no "most popular" confetti, no
 * fake scarcity. Annual is presented as a commitment that costs less per
 * month, with the real saving computed from the plan's own two prices --
 * never a made-up "save 40%" badge.
 *
 * Visual language is the app's own: navy header band, soft steel surfaces,
 * filled accent chips, restrained depth.
 */
export default function Pricing({ plans = [], contactEmail }) {
    const [yearly, setYearly] = useState(true);

    return (
        <PublicLayout>
            <Head title="Pricing" />

            <section className="relative isolate overflow-hidden border-b border-navy-800 bg-navy-900 py-16 text-white sm:py-20">
                <div
                    className="pointer-events-none absolute inset-0 -z-10 opacity-[0.16]"
                    aria-hidden="true"
                    style={{
                        backgroundImage:
                            'linear-gradient(to right, rgba(255,255,255,0.10) 1px, transparent 1px), linear-gradient(to bottom, rgba(255,255,255,0.10) 1px, transparent 1px)',
                        backgroundSize: '56px 56px',
                        maskImage: 'linear-gradient(to bottom, black, transparent 92%)',
                    }}
                />
                <div className="pointer-events-none absolute -right-40 -top-40 -z-10 h-[32rem] w-[32rem] rounded-full bg-steel-500 opacity-[0.14] blur-3xl" aria-hidden="true" />

                <div className="mx-auto max-w-3xl px-4 text-center sm:px-6">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-steel-300">Paket</p>
                    <h1 className="mt-3 text-3xl font-semibold leading-tight tracking-tight sm:text-4xl">
                        Paket standar untuk operasi industri.
                    </h1>
                    <p className="mx-auto mt-4 max-w-xl text-sm leading-relaxed text-navy-300 sm:text-base">
                        Satu produk yang terus disempurnakan untuk semua pelanggan. Ketiga paket adalah platform
                        IOMS yang sama — yang berbeda hanya luas akses dan kapasitasnya, bukan versinya.
                    </p>

                    <div className="mt-8 inline-flex items-center gap-1 rounded-full border border-white/10 bg-white/[0.06] p-1" role="group" aria-label="Billing cycle">
                        {[
                            { key: false, label: 'Bulanan' },
                            { key: true, label: 'Tahunan' },
                        ].map((opt) => (
                            <button
                                key={opt.label}
                                type="button"
                                onClick={() => setYearly(opt.key)}
                                aria-pressed={yearly === opt.key}
                                className={cn(
                                    'rounded-full px-4 py-1.5 text-xs font-semibold transition-colors',
                                    yearly === opt.key ? 'bg-white text-navy-900' : 'text-steel-200 hover:text-white'
                                )}
                            >
                                {opt.label}
                            </button>
                        ))}
                    </div>
                </div>
            </section>

            <section className="bg-graphite-100 py-14 sm:py-20">
                <div className="mx-auto max-w-6xl px-4 sm:px-6">
                    {plans.length === 0 ? (
                        <p className="text-center text-sm text-graphite-500">Informasi paket belum tersedia saat ini.</p>
                    ) : (
                        <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
                            {plans.map((plan) => {
                                const price = yearly ? plan.yearly : plan.monthly;
                                // Real saving from the plan's OWN two prices; never a claim.
                                const saving =
                                    yearly && plan.monthly?.amount > 0 && plan.yearly?.amount > 0
                                        ? Math.round(100 - (plan.yearly.amount / (plan.monthly.amount * 12)) * 100)
                                        : null;

                                return (
                                    <div
                                        key={plan.slug}
                                        className="flex flex-col rounded-xl border border-steel-200/70 bg-gradient-to-b from-steel-100/70 via-white to-white p-6 shadow-panel"
                                    >
                                        <h2 className="text-lg font-semibold tracking-tight text-navy-900">{plan.name}</h2>
                                        {plan.description && (
                                            <p className="mt-1.5 text-xs leading-relaxed text-graphite-500">{plan.description}</p>
                                        )}

                                        <div className="mt-5">
                                            <p className="text-[26px] font-semibold leading-none tracking-tight text-navy-900">
                                                {price?.formatted ?? '—'}
                                            </p>
                                            <p className="mt-1.5 text-[11px] uppercase tracking-wide text-graphite-400">
                                                per {yearly ? 'tahun' : 'bulan'}
                                                {saving > 0 && <span className="ml-1 font-semibold text-success">· hemat {saving}% dibanding bulanan</span>}
                                            </p>
                                        </div>

                                        {/* Built from the plan's OWN entitlement values --
                                            max_users / max_companies / granted workspaces --
                                            so the page can never advertise capacity the
                                            entitlement layer would not actually grant. */}
                                        <ul className="mt-5 space-y-2 border-t border-steel-100 pt-5">
                                            <li className="flex items-start gap-2 text-xs text-graphite-600">
                                                <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" />
                                                <span>{plan.max_users ? `${plan.max_users} akun pengguna (Users)` : 'Kapasitas pengguna standar tertinggi'}</span>
                                            </li>
                                            {/* PTW Access is a PERMISSION on an account that already
                                                exists, never an extra pool of accounts. Spelled out
                                                here because "10 Users / 5 PTW Access" is otherwise
                                                read by some buyers as fifteen accounts. */}
                                            <li className="flex items-start gap-2 text-xs text-graphite-600">
                                                <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" />
                                                <span>
                                                    {plan.max_ptw_users
                                                        ? `${plan.max_ptw_users} di antaranya dapat diberi PTW Access`
                                                        : 'PTW Access untuk seluruh akun'}
                                                </span>
                                            </li>
                                            <li className="flex items-start gap-2 text-xs text-graphite-600">
                                                <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" />
                                                <span>{plan.max_companies ? `${plan.max_companies} perusahaan` : 'Multi-perusahaan'}</span>
                                            </li>
                                            {(plan.workspaces ?? []).slice(0, 6).map((w) => (
                                                <li key={w} className="flex items-start gap-2 text-xs text-graphite-600">
                                                    <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" />
                                                    <span>{w}</span>
                                                </li>
                                            ))}
                                        </ul>

                                        <div className="mt-6 pt-1">
                                            <Button className="w-full" asChild>
                                                <Link href={`${route('get-started')}?plan=${plan.slug}&cycle=${yearly ? 'yearly' : 'monthly'}`}>
                                                    Pilih paket ini <ArrowRight className="h-4 w-4" />
                                                </Link>
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    <div className="mx-auto mt-10 flex max-w-2xl flex-col items-center gap-3 rounded-xl border border-steel-200/70 bg-white p-5 text-center shadow-card sm:flex-row sm:text-left">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-[9px] bg-gradient-to-br from-navy-800 to-brand-600 text-white shadow-[0_3px_8px_-3px_rgba(33,102,196,0.38)]">
                            <ShieldCheck className="h-4 w-4" />
                        </span>
                        <p className="text-xs leading-relaxed text-graphite-600">
                            Setiap paket adalah platform IOMS standar yang sama. Kami membangun satu kali dan
                            menyempurnakannya untuk semua pelanggan — tidak ada pengembangan khusus per
                            perusahaan, dan tidak ada paket seumur hidup. <strong className="text-navy-800">Users</strong>{' '}
                            adalah jumlah akun login, dan <strong className="text-navy-800">PTW Access</strong> adalah
                            berapa akun di antaranya yang boleh membuat Permit To Work — bukan tambahan akun.
                        </p>
                    </div>

                    <p className="mt-6 text-center text-xs text-graphite-500">
                        Sudah punya akun IOMS?{' '}
                        <Link href={route('login')} className="font-medium text-brand-700 hover:underline">Masuk</Link>
                        {contactEmail && (
                            <>
                                {' · '}
                                <a href={`mailto:${contactEmail}`} className="font-medium text-brand-700 hover:underline">Hubungi kami</a>
                            </>
                        )}
                    </p>
                </div>
            </section>
        </PublicLayout>
    );
}
