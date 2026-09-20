import { Head, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import BrandWordmark from '@/Components/shared/BrandWordmark';

/**
 * v2.74.0 -- THE AUTHENTICATION GATEWAY, extracted.
 *
 * The two-panel gateway shipped in v2.42.0 lived inside Login.jsx. When
 * v2.74.0 added a real Sign Up page, the choice was to duplicate roughly
 * eighty lines of brand panel into a second file or to lift it here. It
 * was lifted, because the alternative is two surfaces that drift: a copy
 * is exactly how the login screen and the registration screen end up
 * disagreeing about the product's own positioning line.
 *
 * The design is unchanged and its reasoning still holds:
 *
 *   The left panel is a deep navy brand surface that establishes what
 *   this product IS. The right is a clean white working surface where
 *   credentials are entered. That split is the whole design -- weight
 *   comes from two real surfaces meeting, not from decoration layered
 *   onto one. White is kept deliberately, on the half where it belongs:
 *   data entry wants maximum contrast and zero atmosphere.
 *
 *   EVERY WORD ON THE NAVY PANEL IS REAL. Product name, descriptor,
 *   positioning line and industries are the brand's own copy; edition and
 *   version come from live props. No invented customer counts, uptime
 *   figures, testimonials or logos -- a login screen is the easiest place
 *   to fabricate credibility and the worst place to be caught doing it.
 *
 *   On mobile the navy panel collapses to a compact identity band above
 *   the form rather than being hidden, so the brand still frames the page
 *   at 320px without pushing the form below the fold.
 */
export default function AuthLayout({ title, heading, subheading, children, footer }) {
    const { version, company } = usePage().props;

    const industries = ['Shipyards', 'Construction', 'Manufacturing', 'Heavy Industry'];

    return (
        <div className="min-h-screen bg-white lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <Head title={title} />

            {/* ---------------------------------------------------------------
                BRAND PANEL. Deep navy, full height on desktop, a compact band
                on mobile. The only texture is a fine technical grid at very
                low opacity -- an industrial reference, not a gradient light
                show, and it costs nothing at 320px.
            --------------------------------------------------------------- */}
            <aside className="relative isolate overflow-hidden bg-navy-900 px-6 py-8 text-white sm:px-10 lg:flex lg:flex-col lg:justify-between lg:py-14 xl:px-16">
                <div
                    className="pointer-events-none absolute inset-0 -z-10 opacity-[0.18]"
                    aria-hidden="true"
                    style={{
                        backgroundImage:
                            'linear-gradient(to right, rgba(255,255,255,0.10) 1px, transparent 1px), linear-gradient(to bottom, rgba(255,255,255,0.10) 1px, transparent 1px)',
                        backgroundSize: '56px 56px',
                    }}
                />
                {/* One soft steel wash so the navy reads as depth rather than
                    a flat fill. Deliberately a single layer. */}
                <div
                    className="pointer-events-none absolute -right-32 -top-32 -z-10 h-[34rem] w-[34rem] rounded-full bg-steel-500 opacity-[0.16] blur-3xl"
                    aria-hidden="true"
                />

                <div>
                    {/* The official lockup, dark-surface variant -- this panel
                        is navy, where the light wordmark would be invisible. */}
                    <BrandWordmark className="h-10 w-auto" tone="dark" alt={company?.name || 'IOMS'} />
                    <p className="mt-2.5 text-[13px] font-medium uppercase tracking-[0.18em] text-steel-300">
                        {company?.subtitle || 'Industrial Operations Platform'}
                    </p>
                </div>

                {/* Desktop-only positioning block. Hidden on mobile so the
                    form stays above the fold on a phone. */}
                <div className="hidden lg:block">
                    <h1 className="max-w-md text-[28px] font-semibold leading-tight tracking-tight xl:text-[32px]">
                        Built for industrial operations.
                    </h1>
                    <p className="mt-3 max-w-md text-sm leading-relaxed text-navy-300">
                        One platform for HSE, workforce, projects, maintenance and the documents
                        that have to stand up to an audit.
                    </p>

                    <ul className="mt-7 flex flex-wrap gap-x-2.5 gap-y-2" aria-label="Target industries">
                        {industries.map((industry) => (
                            <li
                                key={industry}
                                className="rounded-md border border-white/10 bg-white/[0.06] px-2.5 py-1 text-xs font-medium text-steel-100"
                            >
                                {industry}
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="hidden items-center gap-2 text-xs text-navy-300 lg:flex">
                    <ShieldCheck className="h-3.5 w-3.5 shrink-0 text-steel-400" />
                    <span>{version?.edition} &middot; v{version?.number}</span>
                </div>
            </aside>

            {/* ---------------------------------------------------------------
                WORKING SURFACE. White, high contrast, no ambient decoration --
                everything here is either a control or a label.
            --------------------------------------------------------------- */}
            <main className="flex items-center justify-center px-5 py-10 sm:px-8 lg:py-14">
                <div className="w-full max-w-[380px]">
                    <div className="mb-7">
                        <h2 className="text-[22px] font-semibold tracking-tight text-navy-900">{heading}</h2>
                        {subheading && <p className="mt-1 text-sm text-graphite-500">{subheading}</p>}
                    </div>

                    {children}

                    {footer}

                    <div className="mt-6 border-t border-graphite-100 pt-5 text-xs leading-relaxed text-graphite-400">
                        {/* Edition/version already sit on the navy panel at lg+;
                            repeated here only where that panel is collapsed. */}
                        <p className="lg:hidden">{version?.edition} &middot; v{version?.number}</p>
                        <p>
                            Designed &amp; Developed by{' '}
                            <span className="font-medium text-graphite-500">{version?.company}</span>
                        </p>
                        <p>&copy; {version?.copyright_year} All Rights Reserved.</p>
                    </div>
                </div>
            </main>
        </div>
    );
}
