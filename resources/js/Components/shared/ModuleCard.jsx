import { Link } from '@inertiajs/react';
import { Badge } from '@/Components/ui/badge';
import { ArrowRight } from 'lucide-react';

/**
 * v1.11.0 (SaaS Finalization Pass, Part 3). The ONE shared "kotak-kotak
 * menu" tile every department Overview should use for its module
 * shortcuts -- icon / title / description / status / action, consistent
 * spacing/radius/hover/responsive behavior everywhere, instead of each
 * department dashboard inventing its own card markup. Reused, not
 * redesigned per page: this component is deliberately dumb (no
 * data-fetching, no department-specific logic) -- callers pass plain
 * props.
 *
 * `status`: 'active' (real, working -- default), 'planned' (genuinely not
 * built yet -- renders "Coming Soon", never a working link), or 'locked'
 * (built, but the tenant isn't entitled to it -- renders "Not included in
 * your plan", explicitly distinct from 'planned' per the SaaS product
 * rule that "not built" and "not licensed" must never look identical).
 */
export default function ModuleCard({ icon: Icon, title, description, href, status = 'active', queryParams }) {
    const isActive = status === 'active' && !!href;

    // v1.11.1: tightened to match StatCard's own visual scale exactly
    // (p-5→p-3.5, h-11/h-5 icon→h-9/h-4 icon, no "Open" affordance row --
    // the whole card is already the click target, same as StatCard) after
    // explicit feedback that the previous version read as oversized next
    // to the Dashboard's own cards. One shared scale for both surfaces.
    const body = (
        <>
            <div className="flex items-center justify-between gap-2">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950/40 dark:text-brand-400">
                    {Icon && <Icon className="h-4 w-4" />}
                </span>
                {status === 'planned' && <Badge variant="secondary" className="shrink-0">Coming Soon</Badge>}
                {status === 'locked' && <Badge variant="destructive" className="shrink-0">Not in plan</Badge>}
                {isActive && <ArrowRight className="h-3.5 w-3.5 shrink-0 text-graphite-300 dark:text-slate-600" />}
            </div>
            <p className="mt-2.5 truncate text-sm font-semibold text-graphite-800 dark:text-slate-100">{title}</p>
            {description && <p className="mt-0.5 line-clamp-1 text-xs text-graphite-400 dark:text-slate-500">{description}</p>}
        </>
    );

    const className = 'block h-full rounded-2xl border border-graphite-200 bg-white/85 p-3.5 backdrop-blur-sm transition-all duration-200 dark:border-slate-800 dark:bg-slate-900/85 ' +
        (isActive ? 'hover:-translate-y-0.5 hover:shadow-card-hover cursor-pointer' : 'opacity-70 cursor-not-allowed');

    if (isActive) {
        return (
            <Link href={route(href, queryParams)} className={className}>
                {body}
            </Link>
        );
    }

    return <div className={className}>{body}</div>;
}
