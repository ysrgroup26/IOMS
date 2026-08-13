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

    const body = (
        <>
            <div className="flex items-start justify-between gap-2">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">
                    {Icon && <Icon className="h-4.5 w-4.5" />}
                </span>
                {status === 'planned' && <Badge variant="secondary">Coming Soon</Badge>}
                {status === 'locked' && <Badge variant="destructive">Not in your plan</Badge>}
            </div>
            <p className="mt-3 text-sm font-semibold text-graphite-800 dark:text-slate-100">{title}</p>
            {description && <p className="mt-1 text-xs leading-relaxed text-graphite-500 dark:text-slate-400">{description}</p>}
            {isActive && (
                <span className="mt-3 inline-flex items-center gap-1 text-xs font-medium text-brand-700 dark:text-brand-300">
                    Open <ArrowRight className="h-3 w-3" />
                </span>
            )}
        </>
    );

    const className = 'block rounded-xl border border-graphite-200 bg-white p-4 transition-all dark:border-slate-800 dark:bg-slate-900 ' +
        (isActive ? 'hover:border-brand-300 hover:shadow-md cursor-pointer' : 'opacity-70 cursor-not-allowed');

    if (isActive) {
        return (
            <Link href={route(href, queryParams)} className={className}>
                {body}
            </Link>
        );
    }

    return <div className={className}>{body}</div>;
}
