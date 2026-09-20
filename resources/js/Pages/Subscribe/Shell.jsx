import { Link, router } from '@inertiajs/react';
import { Check, LogOut } from 'lucide-react';
import BrandWordmark from '@/Components/shared/BrandWordmark';
import { cn } from '@/lib/utils';

/**
 * v2.74.0 -- THE SUBSCRIPTION CHECKOUT SHELL.
 *
 * Four steps, always visible:
 *
 *   Plan -> Organization -> Order -> Payment
 *
 * A COMMERCIAL FLOW, NOT THE OPERATIONAL APP. Deliberately not wrapped in
 * `AuthenticatedLayout`: the workspace switcher, department rail and Work
 * Center are all meaningless to somebody who does not have a tenant yet,
 * and a checkout that renders the product's own navigation invites people
 * to wander out of it halfway through.
 *
 * WHY THE STEPPER MATTERS MORE THAN IT LOOKS. The single most common
 * reason a B2B checkout is abandoned is not price -- it is not knowing
 * how much is left. Naming all four steps up front, with the current one
 * marked and the finished ones ticked, answers that before it is asked.
 *
 * The account's identity sits in the bar rather than in a form field,
 * because that is the point of the whole v2.74.0 change: the person is
 * already known, and the flow should never ask them who they are again.
 */
const STEPS = [
    { n: 1, label: 'Plan' },
    { n: 2, label: 'Organization' },
    { n: 3, label: 'Order' },
    { n: 4, label: 'Payment' },
];

export default function SubscribeShell({ step, account, heading, subheading, children, aside }) {
    return (
        <div className="min-h-screen bg-steel-50/60">
            <div className="border-b border-steel-200/70 bg-navy-900">
                <div className="mx-auto flex w-full max-w-5xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
                    <Link href={route('account.overview')}>
                        <BrandWordmark className="h-7 w-auto" tone="dark" />
                    </Link>

                    <div className="flex min-w-0 items-center gap-3">
                        {/* Stated, not collected. */}
                        <span className="hidden min-w-0 truncate text-xs text-navy-300 sm:block">{account?.email}</span>
                        <button
                            type="button"
                            onClick={() => router.post(route('logout'))}
                            className="shrink-0 rounded-md p-1.5 text-navy-300 transition-colors hover:bg-white/[0.08] hover:text-white"
                            aria-label="Sign out"
                            title="Sign out"
                        >
                            <LogOut className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>

            <div className="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6 lg:py-10">
                <Stepper current={step} />

                <header className="mb-6 mt-6">
                    <h1 className="text-[22px] font-semibold tracking-tight text-navy-900">{heading}</h1>
                    {subheading && <p className="mt-1 text-sm text-graphite-500">{subheading}</p>}
                </header>

                {aside ? (
                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                        <div className="min-w-0">{children}</div>
                        <div className="min-w-0">{aside}</div>
                    </div>
                ) : (
                    children
                )}
            </div>
        </div>
    );
}

function Stepper({ current }) {
    return (
        <ol className="flex flex-wrap items-center gap-x-2 gap-y-2" aria-label="Checkout progress">
            {STEPS.map((s, i) => {
                const done = s.n < current;
                const active = s.n === current;

                return (
                    <li key={s.n} className="flex items-center gap-2">
                        <span
                            className={cn(
                                'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold',
                                done && 'bg-success text-white',
                                active && 'bg-navy-900 text-white',
                                !done && !active && 'border border-graphite-200 bg-white text-graphite-400'
                            )}
                            // The number is decoration once a step is done;
                            // the tick and the label carry the meaning.
                            aria-hidden="true"
                        >
                            {done ? <Check className="h-3.5 w-3.5" /> : s.n}
                        </span>

                        <span
                            className={cn(
                                'text-[13px]',
                                active ? 'font-semibold text-navy-900' : 'text-graphite-500'
                            )}
                            aria-current={active ? 'step' : undefined}
                        >
                            {s.label}
                        </span>

                        {i < STEPS.length - 1 && (
                            <span className="mx-1 hidden h-px w-6 bg-graphite-200 sm:block" aria-hidden="true" />
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
