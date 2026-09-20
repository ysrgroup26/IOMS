import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/Components/ui/button';
import { ArrowRight, Check, ArrowLeft } from 'lucide-react';
import SubscribeShell from '@/Pages/Subscribe/Shell';

/**
 * v2.74.0 -- STEP 1 OF 4: CHOOSE A PLAN.
 *
 * Reached only by an existing, email-confirmed account. That is what
 * makes it different from the public Pricing page, which has to sell to a
 * stranger: this one assumes the decision to use IOMS is already made and
 * is only asking WHICH.
 *
 * So it is a chooser, not a sales page. No hero, no testimonials, no
 * feature matrix -- the differences that actually decide a tier
 * (departments, users, operating units) and a button.
 */
export default function SubscribePlans({ plans, account }) {
    const [cycle, setCycle] = useState('monthly');

    return (
        <SubscribeShell
            title="Choose a plan"
            step={1}
            account={account}
            heading="Choose a plan"
            subheading="Pilih paket untuk organisasi Anda. Anda dapat mengubahnya nanti."
        >
            <Head title="Choose a plan" />

            {/* Monthly/annual is a real commercial choice and belongs with
                the plans rather than buried in checkout, because the saving
                changes which tier people pick. */}
            <div className="mb-5 inline-flex rounded-lg border border-steel-200 bg-white p-0.5">
                {['monthly', 'yearly'].map((c) => (
                    <button
                        key={c}
                        type="button"
                        onClick={() => setCycle(c)}
                        aria-pressed={cycle === c}
                        className={
                            cycle === c
                                ? 'rounded-[6px] bg-navy-900 px-3.5 py-1.5 text-[13px] font-medium text-white'
                                : 'rounded-[6px] px-3.5 py-1.5 text-[13px] font-medium text-graphite-600 hover:text-navy-900'
                        }
                    >
                        {c === 'monthly' ? 'Bulanan' : 'Tahunan'}
                    </button>
                ))}
            </div>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                {plans.map((plan) => {
                    const price = cycle === 'monthly' ? plan.monthly : plan.yearly;
                    // A custom-priced plan has no self-service path: it
                    // ends in a conversation, not a checkout.
                    const isCustom = plan.is_custom || price?.amount === null;

                    return (
                        <div
                            key={plan.slug}
                            className={
                                'relative flex flex-col rounded-xl border bg-white p-5 '
                                + (plan.is_popular ? 'border-brand-300 shadow-panel' : 'border-steel-200')
                            }
                        >
                            {plan.is_popular && (
                                <span className="absolute -top-2.5 left-5 rounded-full bg-brand-600 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                                    Paling dipilih
                                </span>
                            )}

                            <h3 className="text-[15px] font-semibold text-navy-900">{plan.name}</h3>
                            {plan.positioning && (
                                <p className="mt-1 text-xs leading-relaxed text-graphite-500">{plan.positioning}</p>
                            )}

                            <p className="mt-4 text-[22px] font-semibold tracking-tight text-navy-900">
                                {price?.formatted}
                                {!isCustom && (
                                    <span className="text-[13px] font-normal text-graphite-400">
                                        {cycle === 'monthly' ? '/bulan' : '/tahun'}
                                    </span>
                                )}
                            </p>

                            {cycle === 'yearly' && plan.annual_saving && !isCustom && (
                                <p className="mt-1 text-xs font-medium text-success">{plan.annual_saving}</p>
                            )}

                            {/* The departments a tier actually unlocks. This is
                                the difference that decides a purchase, so it is
                                the only list shown. */}
                            {plan.department_workspaces?.length > 0 && (
                                <ul className="mt-4 space-y-1.5 border-t border-graphite-100 pt-4">
                                    {plan.department_workspaces.map((w) => (
                                        <li key={w} className="flex items-start gap-2 text-xs leading-relaxed text-graphite-700">
                                            <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" aria-hidden="true" />
                                            {w}
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <div className="mt-5 flex-1" />

                            {isCustom ? (
                                <Button variant="outline" asChild className="w-full">
                                    <a href={route('contact')}>Hubungi Kami</a>
                                </Button>
                            ) : (
                                <Button asChild className="w-full">
                                    <Link href={`${route('subscribe.organization', plan.slug)}?cycle=${cycle}`}>
                                        Pilih {plan.name} <ArrowRight className="h-4 w-4" />
                                    </Link>
                                </Button>
                            )}
                        </div>
                    );
                })}
            </div>

            <div className="mt-6">
                <Button variant="ghost" size="sm" asChild>
                    <Link href={route('account.overview')}><ArrowLeft className="h-4 w-4" /> Back to account</Link>
                </Button>
            </div>
        </SubscribeShell>
    );
}
