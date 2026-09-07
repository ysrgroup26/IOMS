import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/Components/ui/button';
import BrandWordmark from '@/Components/shared/BrandWordmark';
import { Menu, X } from 'lucide-react';

/**
 * v2.18.0 (Public Website / Landing Page Foundation). The public-facing
 * shell -- deliberately a SEPARATE layout from `AuthenticatedLayout`, per
 * that phase's own explicit instruction ("do not force the authenticated
 * sidebar into the public website"). No sidebar, no Work Center, no
 * Department Selector -- none of those concepts apply to an anonymous
 * visitor. Reuses `BrandWordmark` (the same single source of the IOMS
 * wordmark `Auth/Login.jsx` already uses for an unauthenticated page) and
 * the same `company`/`version` shared Inertia props -- no second
 * branding source invented.
 *
 * v2.51.0: `NAV_LINKS` are now real routes. They used to be same-page
 * anchors (`#platform`, `#solutions`, ...), which worked only on the
 * landing page -- from /pricing or /get-started the entire primary
 * navigation silently did nothing, and a nav item that goes nowhere is a
 * dead link with extra steps. `Get Started` points at the real onboarding
 * flow rather than the login form: a returning customer and a prospect
 * without an account need different destinations.
 */
const NAV_LINKS = [
    { label: 'Platform', route: 'platform-overview' },
    { label: 'Solutions', route: 'solutions' },
    { label: 'How It Works', route: 'how-it-works' },
    { label: 'Pricing', route: 'pricing' },
    { label: 'FAQ', route: 'faq' },
    // v2.53.0: the Sandbox is an acquisition surface -- a prospect who can
    // see the product before buying converts better than one reading about
    // it, and it is NOT a free trial, so it belongs beside the other
    // pre-purchase pages rather than next to Login.
    { label: 'Sandbox', route: 'sandbox' },
];

export default function PublicLayout({ children }) {
    const { company, version } = usePage().props;
    const [menuOpen, setMenuOpen] = useState(false);

    return (
        <div className="min-h-screen bg-white text-graphite-900">
            <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[200] focus:rounded-md focus:bg-graphite-900 focus:px-4 focus:py-2 focus:text-sm focus:text-white">
                Skip to content
            </a>

            <header className="sticky top-0 z-50 border-b border-graphite-100 bg-white/90 backdrop-blur-sm">
                <div className="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                    <Link href="/" className="flex shrink-0 items-center gap-2">
                        <BrandWordmark className="h-6 w-auto" />
                    </Link>

                    <nav className="hidden items-center gap-7 lg:flex" aria-label="Primary">
                        {NAV_LINKS.map((l) => (
                            <Link key={l.label} href={route(l.route)} className="text-sm font-medium text-graphite-600 transition-colors hover:text-graphite-900">
                                {l.label}
                            </Link>
                        ))}
                    </nav>

                    <div className="hidden items-center gap-2 lg:flex">
                        <Button variant="ghost" asChild><Link href={route('login')}>Login</Link></Button>
                        <Button asChild><Link href={route('get-started')}>Get Started</Link></Button>
                    </div>

                    <button
                        type="button"
                        onClick={() => setMenuOpen((v) => !v)}
                        className="flex h-10 w-10 items-center justify-center rounded-lg border border-graphite-200 text-graphite-600 lg:hidden"
                        aria-label={menuOpen ? 'Close menu' : 'Open menu'}
                        aria-expanded={menuOpen}
                    >
                        {menuOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
                    </button>
                </div>

                {menuOpen && (
                    <div className="border-t border-graphite-100 bg-white px-4 py-4 lg:hidden">
                        <nav className="flex flex-col gap-1" aria-label="Primary mobile">
                            {NAV_LINKS.map((l) => (
                                <Link
                                    key={l.label}
                                    href={route(l.route)}
                                    onClick={() => setMenuOpen(false)}
                                    className="rounded-lg px-3 py-2.5 text-sm font-medium text-graphite-700 hover:bg-graphite-50"
                                >
                                    {l.label}
                                </Link>
                            ))}
                        </nav>
                        <div className="mt-3 flex flex-col gap-2 border-t border-graphite-100 pt-3">
                            <Button variant="outline" className="w-full" asChild><Link href={route('login')}>Login</Link></Button>
                            <Button className="w-full" asChild><Link href={route('get-started')}>Get Started</Link></Button>
                        </div>
                    </div>
                )}
            </header>

            <main id="main-content">{children}</main>

            <footer className="border-t border-graphite-100 bg-graphite-50">
                <div className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                    <div className="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-5">
                        <div className="sm:col-span-2 lg:col-span-1">
                            <BrandWordmark className="h-6 w-auto" />
                            {/* v2.22.0 (Complete Product UI/UX Transformation,
                                Part 1): IOMS is the product name -- the full
                                expansion no longer gets equal visual billing
                                next to it in the one spot (footer) that
                                appears on every public page. */}
                            <p className="mt-3 text-sm font-medium text-graphite-700">Industrial Operations Platform</p>
                            <p className="mt-2 max-w-xs text-xs leading-relaxed text-graphite-500">
                                One standardized platform for HSE, people, operations and reporting. Built once, improved for everyone.
                            </p>
                        </div>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-graphite-400">Platform</p>
                            <ul className="mt-3 space-y-2 text-sm text-graphite-600">
                                <li><Link href={route('platform-overview')} className="hover:text-graphite-900">Platform</Link></li>
                                <li><Link href={route('solutions')} className="hover:text-graphite-900">Solutions</Link></li>
                                <li><Link href={route('how-it-works')} className="hover:text-graphite-900">How It Works</Link></li>
                            </ul>
                        </div>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-graphite-400">Resources</p>
                            <ul className="mt-3 space-y-2 text-sm text-graphite-600">
                                <li><Link href={route('pricing')} className="hover:text-graphite-900">Pricing</Link></li>
                                <li><Link href={route('faq')} className="hover:text-graphite-900">FAQ</Link></li>
                                <li><Link href={route('sandbox')} className="hover:text-graphite-900">Sandbox</Link></li>
                                {/* v2.55.0: a real page. This was a mailto:, which opens
                                    the OS "choose an app" dialog and does nothing at all
                                    on a machine with no mail client. */}
                                <li><Link href={route('contact')} className="hover:text-graphite-900">Contact</Link></li>
                            </ul>
                        </div>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-graphite-400">Account</p>
                            <ul className="mt-3 space-y-2 text-sm text-graphite-600">
                                <li><Link href={route('login')} className="hover:text-graphite-900">Login</Link></li>
                                <li><Link href={route('get-started')} className="hover:text-graphite-900">Get Started</Link></li>
                            </ul>
                        </div>
                        {/* v2.55.0: policies get their own column rather than two
                            small links under the copyright line. A subscription
                            product's terms, privacy and refund position are things
                            a buyer looks for BEFORE paying, not fine print. */}
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-graphite-400">Legal</p>
                            <ul className="mt-3 space-y-2 text-sm text-graphite-600">
                                <li><Link href={route('legal.terms')} className="hover:text-graphite-900">Terms &amp; Conditions</Link></li>
                                <li><Link href={route('legal.privacy')} className="hover:text-graphite-900">Privacy Policy</Link></li>
                                <li><Link href={route('legal.refunds')} className="hover:text-graphite-900">Refund &amp; Cancellation</Link></li>
                            </ul>
                        </div>
                    </div>

                    <div className="mt-10 flex flex-col gap-3 border-t border-graphite-200 pt-6 text-xs text-graphite-400 sm:flex-row sm:items-center sm:justify-between">
                        <p>&copy; {version?.copyright_year || new Date().getFullYear()} {version?.company}. All rights reserved.</p>
                        {/* The policy links moved into their own column above, so
                            this row carries the brand address instead of repeating
                            two of the three documents. */}
                        {version?.website && <p>{version.website}</p>}
                    </div>
                </div>
            </footer>
        </div>
    );
}
