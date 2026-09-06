import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Check, ArrowRight, Building2, CreditCard, KeyRound, ShieldCheck } from 'lucide-react';
import PublicLayout from '@/Layouts/PublicLayout';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * v2.50.0 -- the acquisition entry point.
 *
 * WHY IT EXISTS: "Get Started" used to point at the login page, which is
 * the one door a prospect without an account cannot walk through. A
 * returning customer and a new company need different destinations, and
 * conflating them is why the public journey read as a dead end.
 *
 * WHAT IT HONESTLY DOES: it lets a prospect choose a plan and billing
 * cycle, shows them the real onboarding sequence, and hands off to the
 * team. It does NOT pretend to create an account or take a payment.
 *
 * That restraint is deliberate. IOMS has no self-serve tenant provisioning
 * yet, and standing one up means creating a tenant, company, admin user,
 * subscription and entitlement from an unauthenticated request -- a
 * security surface that should be built properly or not at all. And no
 * payment gateway is configured, so any "checkout" here would be theatre:
 * the app must never treat a browser reaching a success screen as proof of
 * payment. Steps 3 and 4 below are therefore labelled as what they are.
 */
export default function GetStarted({ plans = [], selectedPlan, billingCycle, supportEmail }) {
    const [slug, setSlug] = useState(selectedPlan || plans[1]?.slug || plans[0]?.slug || null);
    const [yearly, setYearly] = useState(billingCycle !== 'monthly');

    const plan = plans.find((p) => p.slug === slug) || null;
    const price = plan ? (yearly ? plan.yearly : plan.monthly) : null;

    // Real onboarding sequence. Each step says plainly whether it is
    // something the product does today or something the team does with you.
    const steps = [
        { icon: Building2, title: 'Company & administrator', body: 'We create your tenant, your first company and the administrator account that will own it.' },
        { icon: ShieldCheck, title: 'Plan & capacity', body: 'Your plan sets user capacity, PTW access seats and which operational domains are enabled.' },
        { icon: CreditCard, title: 'Invoice & payment', body: 'An invoice is issued for the selected billing cycle. Your subscription activates once payment is confirmed by the payment provider — never before.' },
        { icon: KeyRound, title: 'Sign in to IOMS', body: 'You receive your administrator credentials and sign in to a fully provisioned workspace.' },
    ];

    const subject = plan
        ? `IOMS ${plan.name} (${yearly ? 'annual' : 'monthly'}) — new account request`
        : 'IOMS — new account request';

    return (
        <PublicLayout>
            <Head title="Get Started" />

            <section className="relative isolate overflow-hidden border-b border-navy-800 bg-navy-900 py-14 text-white sm:py-16">
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
                <div className="pointer-events-none absolute -right-40 -top-40 -z-10 h-[30rem] w-[30rem] rounded-full bg-steel-500 opacity-[0.14] blur-3xl" aria-hidden="true" />

                <div className="mx-auto max-w-3xl px-4 text-center sm:px-6">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-steel-300">Get Started</p>
                    <h1 className="mt-3 text-3xl font-semibold leading-tight tracking-tight sm:text-4xl">
                        Set up IOMS for your operation.
                    </h1>
                    <p className="mx-auto mt-4 max-w-xl text-sm leading-relaxed text-navy-300 sm:text-base">
                        Choose the plan that matches your capacity and the domains you run. We provision your
                        company, issue the invoice, and hand over administrator access.
                    </p>
                </div>
            </section>

            <section className="bg-graphite-100 py-12 sm:py-16">
                <div className="mx-auto grid max-w-6xl gap-6 px-4 sm:px-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
                    {/* ---- Plan selection ---- */}
                    <div className="rounded-xl border border-steel-200/70 bg-white p-6 shadow-panel">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-[15px] font-semibold tracking-tight text-navy-900">Choose your plan</h2>
                            <div className="inline-flex items-center gap-1 rounded-full border border-steel-200 bg-steel-50 p-0.5" role="group" aria-label="Billing cycle">
                                {[{ k: false, l: 'Monthly' }, { k: true, l: 'Annual' }].map((o) => (
                                    <button
                                        key={o.l}
                                        type="button"
                                        onClick={() => setYearly(o.k)}
                                        aria-pressed={yearly === o.k}
                                        className={cn(
                                            'rounded-full px-3 py-1 text-[11px] font-semibold transition-colors',
                                            yearly === o.k ? 'bg-navy-900 text-white' : 'text-graphite-600 hover:text-navy-800'
                                        )}
                                    >
                                        {o.l}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="mt-4 space-y-2.5">
                            {plans.map((p) => {
                                const active = p.slug === slug;
                                const amount = yearly ? p.yearly : p.monthly;
                                return (
                                    <button
                                        key={p.slug}
                                        type="button"
                                        onClick={() => setSlug(p.slug)}
                                        aria-pressed={active}
                                        className={cn(
                                            'flex w-full items-center gap-3 rounded-xl border p-3.5 text-left transition-all',
                                            active
                                                ? 'border-brand-300 bg-gradient-to-b from-steel-100/80 via-white to-white shadow-card'
                                                : 'border-steel-100 bg-white hover:border-steel-200 hover:bg-steel-50/60'
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors',
                                                active ? 'border-brand-600 bg-brand-600 text-white' : 'border-steel-300'
                                            )}
                                        >
                                            {active && <Check className="h-3 w-3" />}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-sm font-semibold text-navy-900">{p.name}</span>
                                            <span className="block truncate text-[11px] text-graphite-500">
                                                {p.max_users ? `${p.max_users} users` : 'Maximum capacity'}
                                                {p.max_companies ? ` · ${p.max_companies} ${p.max_companies === 1 ? 'company' : 'companies'}` : ' · Multi-company'}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-right">
                                            <span className="block text-sm font-semibold text-navy-900">{amount?.formatted ?? '—'}</span>
                                            <span className="block text-[10px] uppercase tracking-wide text-graphite-400">/{yearly ? 'year' : 'month'}</span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>

                        <div className="mt-5 border-t border-steel-100 pt-5">
                            <div className="flex items-baseline justify-between">
                                <span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Selected</span>
                                <span className="text-lg font-semibold tracking-tight text-navy-900">
                                    {plan ? `${plan.name} · ${price?.formatted ?? '—'}` : '—'}
                                </span>
                            </div>

                            <Button className="mt-4 w-full" asChild disabled={!plan}>
                                <a href={supportEmail ? `mailto:${supportEmail}?subject=${encodeURIComponent(subject)}` : '#'}>
                                    Request this plan <ArrowRight className="h-4 w-4" />
                                </a>
                            </Button>

                            {/* Honest about where the journey currently ends. */}
                            <p className="mt-2.5 text-center text-[11px] leading-relaxed text-graphite-500">
                                Accounts are provisioned by our team. Self-serve signup and online payment are
                                being enabled — your subscription only becomes active once payment is confirmed.
                            </p>
                        </div>
                    </div>

                    {/* ---- What happens next ---- */}
                    <div className="rounded-xl border border-steel-200/70 bg-gradient-to-b from-steel-100/70 via-white to-white p-6 shadow-panel">
                        <h2 className="text-[15px] font-semibold tracking-tight text-navy-900">What happens next</h2>
                        <ol className="mt-4 space-y-4">
                            {steps.map((s, i) => (
                                <li key={s.title} className="flex gap-3">
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] bg-gradient-to-br from-navy-800 to-brand-600 text-white shadow-[0_3px_8px_-3px_rgba(33,102,196,0.38)]">
                                        <s.icon className="h-4 w-4" />
                                    </span>
                                    <div className="min-w-0">
                                        <p className="text-[13px] font-semibold text-navy-900">
                                            <span className="mr-1.5 text-[11px] font-mono text-graphite-400">0{i + 1}</span>
                                            {s.title}
                                        </p>
                                        <p className="mt-0.5 text-xs leading-relaxed text-graphite-500">{s.body}</p>
                                    </div>
                                </li>
                            ))}
                        </ol>

                        <div className="mt-6 rounded-lg border border-steel-100 bg-white p-3.5">
                            <p className="text-xs leading-relaxed text-graphite-600">
                                <span className="font-semibold text-navy-800">Already an IOMS customer?</span>{' '}
                                <Link href={route('login')} className="font-medium text-brand-700 hover:underline">Sign in</Link>
                                {' '}— or{' '}
                                <Link href={route('pricing')} className="font-medium text-brand-700 hover:underline">compare plans</Link>
                                {' '}first.
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
