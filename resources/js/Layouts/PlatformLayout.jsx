import { Link, usePage, router } from '@inertiajs/react';
import { LayoutDashboard, Building2, LogOut, Tag } from 'lucide-react';
import BrandWordmark from '@/Components/shared/BrandWordmark';

const NAV_CLASS = 'flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors';

/**
 * Milestone 2 (Platform Super Admin UI, Task #44). Deliberately NOT
 * AuthenticatedLayout -- that layout's sidebar/department switcher/Work
 * Center/notifications are all tenant-scoped concepts a Platform Super
 * Admin (no tenant, see User::isPlatformAdmin()) has no relationship to.
 * A small, separate top-nav shell instead, matching this app's existing
 * visual language (BrandWordmark, brand/graphite color palette) without
 * pulling in any of the tenant-side navigation machinery.
 */
export default function PlatformLayout({ children }) {
    const { auth, version } = usePage().props;
    const currentUrl = usePage().url;

    function logout(e) {
        e.preventDefault();
        router.post(route('logout'));
    }

    const navItems = [
        { name: 'Dashboard', href: route('platform.dashboard'), icon: LayoutDashboard, active: currentUrl === '/platform' },
        { name: 'Tenants', href: route('platform.tenants'), icon: Building2, active: currentUrl.startsWith('/platform/tenants') },
        { name: 'Plans', href: route('platform.plans'), icon: Tag, active: currentUrl.startsWith('/platform/plans') },
    ];

    return (
        <div className="min-h-screen bg-graphite-50">
            <header className="border-b border-graphite-200 bg-white">
                <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
                    <div className="flex items-center gap-6">
                        <BrandWordmark className="h-6 w-auto" />
                        {/* Milestone 3 (UAT #1/#3/#7 -- identity clarity):
                            "Master" -- matches User::roleLabel()'s
                            platform_admin mapping exactly, so this reads
                            the same word everywhere a Platform Super Admin
                            sees their own identity (this badge, the
                            dropdown role tag on the tenant-side layout if
                            they ever switch, roleLabel() itself). */}
                        <span className="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-brand-700">
                            Master
                        </span>
                        <nav className="flex items-center gap-1">
                            {navItems.map((item) => (
                                <Link
                                    key={item.name}
                                    href={item.href}
                                    className={`${NAV_CLASS} ${item.active ? 'bg-brand-50 text-brand-700' : 'text-graphite-600 hover:bg-graphite-100'}`}
                                >
                                    <item.icon className="h-4 w-4" /> {item.name}
                                </Link>
                            ))}
                        </nav>
                    </div>
                    <div className="flex items-center gap-3">
                        <span className="text-sm text-graphite-500">{auth?.user?.name} <span className="text-xs text-graphite-400">(Master)</span></span>
                        <button
                            onClick={logout}
                            className="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm text-graphite-500 hover:bg-graphite-100"
                        >
                            <LogOut className="h-4 w-4" /> Sign out
                        </button>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-6xl px-4 py-8">{children}</main>

            <footer className="mx-auto max-w-6xl px-4 pb-8 text-center text-xs text-graphite-400">
                {version?.edition} &middot; v{version?.number} -- Platform Operator Console
            </footer>
        </div>
    );
}
