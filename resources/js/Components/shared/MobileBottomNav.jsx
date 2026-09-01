import { Link } from '@inertiajs/react';
import { LayoutDashboard, Menu } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * v2.22.0 (Complete Product UI/UX Transformation, Part 9 -- "MAJOR
 * CHANGE"). On mobile, IOMS's primary navigation is no longer just "the
 * same sidebar, hidden behind a hamburger" -- a fixed bottom tab bar now
 * sits alongside it, the pattern every serious mobile-first operational
 * app (and this directive's own explicit ask) uses.
 *
 * Deliberately does NOT introduce a second navigation/permission system:
 * every item shown here is drawn directly from `visibleNav`, the EXACT
 * same already-RBAC/department-filtered array `AuthenticatedLayout`'s
 * own sidebar renders (see that file's `visibleNav` derivation) -- this
 * component receives it as a prop, it never recomputes or duplicates
 * that logic. "More" does not open a second menu of its own; it calls
 * the same `onOpenMore` the sidebar's own mobile drawer already uses
 * (`setSidebarOpen(true)`), so the full authorized navigation (including
 * anything that didn't fit in the bar) is always one tap away, in the
 * exact same drawer already built and already correct.
 *
 * Only up to 3 items from `visibleNav` are shown (plus the pinned Home
 * and More) -- items with `children` (a grouped section, e.g. HSE's own
 * sub-groups) or `disabled` (a not-yet-built module) are skipped for the
 * bar itself, since neither has one single destination to tap straight
 * to; both are still reachable via More exactly as they are today from
 * the sidebar. This keeps the bar honest -- every icon it shows goes
 * exactly where it says, in one tap, for every role, every department.
 */
export default function MobileBottomNav({ visibleNav, currentUrl, onOpenMore }) {
    const primaryItems = (visibleNav || [])
        .filter((item) => item.href && !item.disabled && !item.children?.length)
        .slice(0, 3);

    return (
        <nav
            // z-30: intentionally BELOW the sidebar drawer's own mobile
            // overlay (z-40, AuthenticatedLayout.jsx) -- when the drawer
            // is open, the overlay should visually dim/cover this bar
            // too, not sit under it.
            className="fixed inset-x-0 bottom-0 z-30 flex items-stretch border-t border-graphite-200 bg-white/95 backdrop-blur-sm lg:hidden dark:border-slate-800 dark:bg-slate-950/95"
            style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
            aria-label="Primary"
        >
            <BottomNavLink href={route('dashboard')} label="Home" icon={LayoutDashboard} active={currentUrl === '/dashboard' || currentUrl === '/'} />
            {primaryItems.map((item) => (
                <BottomNavLink
                    key={item.name}
                    href={route(item.href, item.queryParams)}
                    label={item.name}
                    icon={item.icon}
                    active={isActive(item, currentUrl)}
                />
            ))}
            <button
                type="button"
                onClick={onOpenMore}
                className="flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 py-2 text-graphite-500 dark:text-slate-400"
            >
                <Menu className="h-5 w-5 shrink-0" />
                <span className="max-w-full truncate text-[10px] font-medium">More</span>
            </button>
        </nav>
    );
}

function isActive(item, currentUrl) {
    if (!item.href) return false;
    try {
        const path = new URL(route(item.href)).pathname;
        return currentUrl === path || currentUrl.startsWith(path + '/');
    } catch {
        return false;
    }
}

// v2.29.0 (Authenticated UI Visual Transformation, Part 17/19): the
// active tab previously only changed text color -- easy to miss at a
// glance on a small screen. Added a top indicator bar (mirrors the
// sidebar's own active left-bar convention) and a tinted pill behind the
// icon, both animated with a plain CSS transition (no new dependency).
function BottomNavLink({ href, label, icon: Icon, active }) {
    return (
        <Link
            href={href}
            className={cn(
                'relative flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 py-2 transition-colors',
                active ? 'text-brand-600 dark:text-brand-400' : 'text-graphite-500 dark:text-slate-400'
            )}
        >
            {active && <span className="absolute left-1/2 top-0 h-0.5 w-8 -translate-x-1/2 rounded-full bg-brand-600 transition-all duration-200" aria-hidden="true" />}
            <span className={cn('flex h-6 w-9 items-center justify-center rounded-full transition-colors duration-200', active && 'bg-brand-50 dark:bg-brand-950/40')}>
                {Icon && <Icon className="h-[18px] w-[18px] shrink-0" />}
            </span>
            <span className="max-w-full truncate text-[10px] font-medium">{label}</span>
        </Link>
    );
}
