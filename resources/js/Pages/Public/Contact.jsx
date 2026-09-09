import { Head, Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import { Button } from '@/Components/ui/button';
import { LifeBuoy, Receipt, Briefcase, ArrowRight, MapPin } from 'lucide-react';

/**
 * v2.55.0 -- a real Contact page.
 *
 * The footer's "Contact" was a `mailto:` link, which on a machine with no
 * mail client opens the operating system's "choose an app" dialog and
 * otherwise does nothing — the same acquisition dead end v2.51.0 removed
 * from the pricing CTAs, still sitting in the footer of every page.
 *
 * WHAT THIS PAGE DELIBERATELY DOES NOT PUBLISH. IOMS's payment provider
 * verifies a personal, private identity: a legal name, a registered address
 * that is frequently a home address, a tax number, identity documents. None
 * of that is website content, and none of it appears here. A postal address
 * renders only if the operator has explicitly configured one INTENDED for
 * publication; there is no config key for a tax or registration number at
 * all.
 *
 * Three mailboxes, each with a stated job, so a customer chasing an invoice
 * and a prospect asking about plans do not land in the same inbox.
 */
export default function Contact({ emails, operator, address, sandboxEnabled }) {
    const channels = [
        {
            icon: LifeBuoy,
            label: 'Support',
            email: emails?.support,
            blurb: 'Product questions, technical help and account matters for customers already running on IOMS.',
        },
        {
            icon: Receipt,
            label: 'Billing',
            email: emails?.billing,
            blurb: 'Invoices, payments, renewals, cancellations and everything else to do with a subscription.',
        },
        {
            icon: Briefcase,
            label: 'Sales',
            email: emails?.hello,
            blurb: 'Pre-sales questions, choosing a plan, and information requests from prospective customers.',
        },
    ].filter((c) => c.email);

    return (
        <PublicLayout>
            <Head title="Contact" />

            <div className="border-b border-graphite-100 bg-graphite-50/60">
                <div className="mx-auto max-w-4xl px-4 py-14 sm:px-6 lg:px-8">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-graphite-400">Contact</p>
                    <h1 className="mt-3 text-3xl font-semibold tracking-tight text-graphite-900 sm:text-4xl">
                        Contact IOMS
                    </h1>
                    <p className="mt-3 max-w-2xl text-base leading-relaxed text-graphite-600">
                        IOMS — Industrial Operations Platform. Three addresses for three different jobs, so your
                        question reaches the right person first time. We reply on business days.
                    </p>
                </div>
            </div>

            <div className="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
                <div className="grid gap-4 sm:grid-cols-3">
                    {channels.map((c) => (
                        <div key={c.label} className="rounded-xl border border-graphite-200 bg-white p-5">
                            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-navy-900 text-white">
                                <c.icon className="h-4 w-4" />
                            </span>
                            <p className="mt-3 text-sm font-semibold text-graphite-900">{c.label}</p>
                            <a
                                href={`mailto:${c.email}`}
                                className="mt-1 block break-all text-sm font-medium text-brand-700 hover:underline"
                            >
                                {c.email}
                            </a>
                            <p className="mt-2 text-xs leading-relaxed text-graphite-500">{c.blurb}</p>
                        </div>
                    ))}
                </div>

                {/* Rendered only when an address intended for publication exists. */}
                {(operator || address) && (
                    <div className="mt-6 rounded-xl border border-graphite-200 bg-graphite-50 p-5">
                        <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-graphite-400">
                            <MapPin className="h-3.5 w-3.5" /> Operator
                        </p>
                        {operator && <p className="mt-2 text-sm font-medium text-graphite-800">{operator}</p>}
                        {address && <p className="mt-1 text-sm leading-relaxed text-graphite-600">{address}</p>}
                    </div>
                )}

                <div className="mt-10 grid gap-4 sm:grid-cols-2">
                    <div className="rounded-xl border border-graphite-200 p-5">
                        <p className="text-sm font-semibold text-graphite-900">Not a customer yet?</p>
                        <p className="mt-1.5 text-sm leading-relaxed text-graphite-600">
                            See what each plan covers and what it costs, then start registration right here on the site.
                        </p>
                        <div className="mt-4 flex flex-wrap gap-2">
                            <Button asChild size="sm"><Link href={route('get-started')}>Get Started <ArrowRight className="h-4 w-4" /></Link></Button>
                            <Button asChild size="sm" variant="outline"><Link href={route('pricing')}>View Pricing</Link></Button>
                        </div>
                    </div>

                    <div className="rounded-xl border border-graphite-200 p-5">
                        <p className="text-sm font-semibold text-graphite-900">Want to see the product first?</p>
                        <p className="mt-1.5 text-sm leading-relaxed text-graphite-600">
                            {sandboxEnabled
                                ? 'The IOMS Sandbox is a demonstration workspace filled with an operation already running. Nothing to register for, and nothing to cancel afterwards.'
                                : 'Contact our team to arrange a product review shaped around how your operation works.'}
                        </p>
                        <div className="mt-4 flex flex-wrap gap-2">
                            {sandboxEnabled
                                ? <Button asChild size="sm" variant="outline"><Link href={route('sandbox')}>Open the Sandbox</Link></Button>
                                : emails?.hello && <Button asChild size="sm" variant="outline"><a href={`mailto:${emails.hello}`}>Contact Sales</a></Button>}
                            <Button asChild size="sm" variant="ghost"><Link href={route('faq')}>Read the FAQ</Link></Button>
                        </div>
                    </div>
                </div>

                <p className="mt-10 text-xs leading-relaxed text-graphite-500">
                    Already a customer? Most questions about your account, plan capacity, invoices and cancellation
                    can be handled yourself from the Billing page inside your workspace once you
                    <Link href={route('login')} className="font-medium text-brand-700 hover:underline"> sign in</Link>.
                </p>
            </div>
        </PublicLayout>
    );
}
