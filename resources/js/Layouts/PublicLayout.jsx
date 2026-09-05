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
 * `NAV_LINKS` are same-page anchor links (`#platform`, `#pricing`, etc.)
 * -- the public site is intentionally one long page (Part 19's
 * "Do NOT introduce unnecessary dependencies/second frontend framework"
 * favors this over a router-driven multi-page structure for a first
 * version), not a router. `Login`/`Get Started` both point at the real
 * `login` route -- there is no self-serve registration route anywhere in
 * this codebase (confirmed by audit), so "Get Started" honestly leads to
 * the same sign-in a Platform-Admin-provisioned account already uses,
 * never a fabricated signup flow.
 */
const NAV_LINKS = [
    { label: 'Platform', href: '#platform' },
    { label: 'Solutions', href: '#solutions' },
    { label: 'How It Works', href: '#how-it-works' },
    { label: 'Pricing', href: '#pricing' },
    { label: 'FAQ', href: '#faq' },
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
                            <a key={l.href} href={l.href} className="text-sm font-medium text-graphite-600 transition-colors hover:text-graphite-900">
                                {l.label}
                            </a>
                        ))}
                    </nav>

                    <div className="hidden items-center gap-2 lg:flex">
                        <Button variant="ghost" asChild><Link href={route('login')}>Login</Link></Button>
                        <Button asChild><Link href={route('login')}>Get Started</Link></Button>
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
                                <a
                                    key={l.href}
                                    href={l.href}
                                    onClick={() => setMenuOpen(false)}
                                    className="rounded-lg px-3 py-2.5 text-sm font-medium text-graphite-700 hover:bg-graphite-50"
                                >
                                    {l.label}
                                </a>
                            ))}
                        </nav>
                        <div className="mt-3 flex flex-col gap-2 border-t border-graphite-100 pt-3">
                            <Button variant="outline" className="w-full" asChild><Link href={route('login')}>Login</Link></Button>
                            <Button className="w-full" asChild><Link href={route('login')}>Get Started</Link></Button>
                        </div>
                    </div>
                )}
            </header>

            <main id="main-content">{children}</main>

            <footer className="border-t border-graphite-100 bg-graphite-50">
                <div className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                    <div className="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="sm:col-span-2 lg:col-span-1">
                            <BrandWordmark className="h-6 w-auto" />
                            {/* v2.22.0 (Complete Product UI/UX Transformation,
                                Part 1): IOMS is the product name -- the full
                                expansion no longer gets equal visual billing
                                next to it in the one spot (footer) that
                                appears on every public page. */}
                            <p className="mt-3 text-sm font-medium text-graphite-700">Industrial Operations Platform</p>
                            <p className="text-xs text-graphite-400">Industrial Operations Platform</p>
                        </div>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-graphite-400">Platform</p>
                            <ul className="mt-3 space-y-2 text-sm text-graphite-600">
                                <li><a href="#platform" className="hover:text-graphite-900">Platform</a></li>
                                <li><a href="#solutions" className="hover:text-graphite-900">Solutions</a></li>
                                <li><a href="#how-it-works" className="hover:text-graphite-900">How It Works</a></li>
                            </ul>
                        </div>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-graphite-400">Resources</p>
                            <ul className="mt-3 space-y-2 text-sm text-graphite-600">
                                <li><a href="#pricing" className="hover:text-graphite-900">Pricing</a></li>
                                <li><a href="#faq" className="hover:text-graphite-900">FAQ</a></li>
                                {version?.support_email && (
                                    <li><a href={`mailto:${version.support_email}`} className="hover:text-graphite-900">Contact</a></li>
                                )}
                            </ul>
                        </div>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-graphite-400">Account</p>
                            <ul className="mt-3 space-y-2 text-sm text-graphite-600">
                                <li><Link href={route('login')} className="hover:text-graphite-900">Login</Link></li>
                                <li><Link href={route('login')} className="hover:text-graphite-900">Get Started</Link></li>
                            </ul>
                        </div>
                    </div>

                    <div className="mt-10 flex flex-col gap-3 border-t border-graphite-200 pt-6 text-xs text-graphite-400 sm:flex-row sm:items-center sm:justify-between">
                        <p>&copy; {version?.copyright_year || new Date().getFullYear()} {version?.company}. All rights reserved.</p>
                        <div className="flex gap-4">
                            <Link href={route('legal.privacy')} className="hover:text-graphite-600">Privacy</Link>
                            <Link href={route('legal.terms')} className="hover:text-graphite-600">Terms</Link>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    );
}
