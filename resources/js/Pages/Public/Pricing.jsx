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
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-steel-300">Pricing</p>
                    <h1 className="mt-3 text-3xl font-semibold leading-tight tracking-tight sm:text-4xl">
                        Standardized plans for industrial operations.
                    </h1>
                    <p className="mx-auto mt-4 max-w-xl text-sm leading-relaxed text-navy-300 sm:text-base">
                        One product, improved for every customer. All three plans are the same IOMS platform — what
                        differs is breadth of access and capacity, not the build.
                    </p>

                    <div className="mt-8 inline-flex items-center gap-1 rounded-full border border-white/10 bg-white/[0.06] p-1" role="group" aria-label="Billing cycle">
                        {[
                            { key: false, label: 'Monthly' },
                            { key: true, label: 'Annual' },
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
                        <p className="text-center text-sm text-graphite-500">Plan information is not available right now.</p>
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
                                                per {yearly ? 'year' : 'month'}
                                                {saving > 0 && <span className="ml-1 font-semibold text-success">· {saving}% less than monthly</span>}
                                            </p>
                                        </div>

                                        {/* Built from the plan's OWN entitlement values --
                                            max_users / max_companies / granted workspaces --
                                            so the page can never advertise capacity the
                                            entitlement layer would not actually grant. */}
                                        <ul className="mt-5 space-y-2 border-t border-steel-100 pt-5">
                                            {/* v2.53.0: capacity is ONE number. PTW Access used to
                                                appear here as a second allowance, which a buyer then
                                                had to reconcile against the first ("10 users and 5
                                                PTW Access -- is that fifteen?"). It is not sold any
                                                more; it is an internal permission. */}
                                            <li className="flex items-start gap-2 text-xs text-graphite-600">
                                                <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" />
                                                <span>{plan.max_users ? `${plan.max_users} user accounts` : 'Highest standardized user capacity'}</span>
                                            </li>
                                            <li className="flex items-start gap-2 text-xs text-graphite-600">
                                                <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" />
                                                {/* v2.54.0: Operating Units, not companies. A plan
                                                    never sells more than ONE organization -- what it
                                                    sizes is how many operating units that organization
                                                    may run inside it. */}
                                                <span>{plan.max_companies ? `${plan.max_companies} Operating Unit${plan.max_companies > 1 ? 's' : ''}` : 'Multiple Operating Units'}</span>
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
                                                    Choose this plan <ArrowRight className="h-4 w-4" />
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
                            Every plan is the same standardized IOMS platform. We build once and improve it for
                            everyone — no per-customer custom development, and no lifetime plan.
                            <strong className="text-navy-800"> Users</strong> is the number of login accounts included.
                            Permissions such as PTW Access are granted to those accounts inside IOMS at no extra cost.
                            {/* v2.54.0: says plainly what an Operating Unit is, so nobody reads
                                "2 Operating Units" as "two companies you may bill separately". */}
                            <strong className="text-navy-800"> Operating Units</strong> are yards, sites or divisions
                            inside one organization — one subscription, not separate companies.
                        </p>
                    </div>

                    <p className="mt-6 text-center text-xs text-graphite-500">
                        Not sure yet?{' '}
                        <Link href={route('sandbox')} className="font-medium text-brand-700 hover:underline">Try the Sandbox</Link>
                        {' '}first — a real workspace with an operation already running in it.
                    </p>
                    <p className="mt-2 text-center text-xs text-graphite-500">
                        Already have an IOMS account?{' '}
                        <Link href={route('login')} className="font-medium text-brand-700 hover:underline">Sign in</Link>
                        {contactEmail && (
                            <>
                                {' · '}
                                <a href={`mailto:${contactEmail}`} className="font-medium text-brand-700 hover:underline">Talk to us</a>
                            </>
                        )}
                    </p>
                </div>
            </section>
        </PublicLayout>
    );
}
