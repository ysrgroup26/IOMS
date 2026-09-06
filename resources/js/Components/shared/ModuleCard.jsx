import { Link } from '@inertiajs/react';
import { Badge } from '@/Components/ui/badge';
import { ArrowRight } from 'lucide-react';
import { cn } from '@/lib/utils';

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
 *
 * ---------------------------------------------------------------------
 * v2.49.0 -- TWO ADDITIONS, both so a page stops needing to fork this.
 *
 * `size="lg"` renders a ROW tile with a 48px chip instead of the dense
 * 32px stacked one. This exists because My Work (Field/Home) had forked
 * its own card markup for a real reason -- a field user's primary action
 * tile needs a genuinely easy touch target on a phone, not enterprise
 * information density -- and that fork is exactly why My Work never
 * inherited the v2.43-v2.48 chip/tint work and drifted back to flat white
 * cards with pale chips. Folding the large variant in here means the
 * touch-target requirement is preserved AND future global visual changes
 * reach it automatically.
 *
 * `accent` tints the surface with the icon's own colour, matching
 * StatCard's relationship exactly: a strong filled chip supplying the
 * dark anchor, a soft visible wash of the same hue around it. Omitting it
 * gives the steel default every existing caller already renders.
 *
 * `href` accepts either a route NAME (resolved through Ziggy, the
 * original contract) or an already-resolved URL/path -- some callers,
 * including My Work, receive fully-built URLs from the server.
 */
const ACCENTS = {
    brand: {
        chip: 'bg-gradient-to-br from-navy-800 to-brand-600 text-white shadow-[0_3px_8px_-3px_rgba(33,102,196,0.38)] dark:from-brand-950 dark:to-brand-900 dark:text-brand-300',
        surface: 'border-steel-100 bg-gradient-to-b from-steel-100/70 via-steel-50/40 to-white dark:border-slate-800 dark:from-slate-900 dark:to-slate-900',
    },
    green: {
        chip: 'bg-gradient-to-br from-success to-emerald-700 text-white shadow-[0_3px_8px_-3px_rgba(22,163,74,0.38)] dark:from-emerald-900 dark:to-emerald-800',
        surface: 'border-success/15 bg-gradient-to-b from-success/[0.12] via-success/[0.04] to-white dark:border-emerald-900/40 dark:from-emerald-950/20 dark:to-slate-900',
    },
    amber: {
        chip: 'bg-gradient-to-br from-warning to-amber-600 text-white shadow-[0_3px_8px_-3px_rgba(217,119,6,0.38)] dark:from-amber-900 dark:to-amber-800',
        surface: 'border-warning/15 bg-gradient-to-b from-warning/[0.14] via-warning/[0.05] to-white dark:border-amber-900/40 dark:from-amber-950/20 dark:to-slate-900',
    },
    red: {
        chip: 'bg-gradient-to-br from-danger to-red-600 text-white shadow-[0_3px_8px_-3px_rgba(220,38,38,0.38)] dark:from-red-900 dark:to-red-800',
        surface: 'border-danger/15 bg-gradient-to-b from-danger/[0.12] via-danger/[0.04] to-white dark:border-red-900/40 dark:from-red-950/20 dark:to-slate-900',
    },
    purple: {
        chip: 'bg-gradient-to-br from-violet-500 to-violet-700 text-white shadow-[0_3px_8px_-3px_rgba(139,92,246,0.38)] dark:from-violet-900 dark:to-violet-800',
        surface: 'border-violet-300/30 bg-gradient-to-b from-violet-500/[0.11] via-violet-500/[0.035] to-white dark:border-violet-900/40 dark:from-violet-950/20 dark:to-slate-900',
    },
};

export default function ModuleCard({ icon: Icon, title, description, href, status = 'active', queryParams, size = 'default', accent }) {
    const isActive = status === 'active' && !!href;
    const isLarge = size === 'lg';
    const tone = ACCENTS[accent] || ACCENTS.brand;

    // v1.11.14: the default scale is kept in lockstep with StatCard's own
    // compacted scale (32x32 icon / 9px radius / 16px glyph / 12px padding
    // / 10px card radius) so neither tile ever reads larger than the other
    // on the same page.
    const chip = (
        <span
            className={cn(
                'flex shrink-0 items-center justify-center',
                isLarge ? 'h-12 w-12 rounded-xl' : 'h-8 w-8 rounded-[9px]',
                tone.chip
            )}
        >
            {Icon && <Icon className={isLarge ? 'h-6 w-6' : 'h-4 w-4'} />}
        </span>
    );

    const body = isLarge ? (
        <div className="flex items-center gap-3">
            {chip}
            <div className="min-w-0 flex-1">
                <p className="truncate text-base font-semibold text-navy-900 dark:text-slate-50">{title}</p>
                {description && <p className="truncate text-xs text-graphite-500 dark:text-slate-400">{description}</p>}
            </div>
            {isActive && <ArrowRight className="h-4 w-4 shrink-0 text-graphite-300 dark:text-slate-600" />}
        </div>
    ) : (
        <>
            <div className="flex items-center justify-between gap-2">
                {chip}
                {status === 'planned' && <Badge variant="secondary" className="shrink-0">Coming Soon</Badge>}
                {status === 'locked' && <Badge variant="destructive" className="shrink-0">Not in plan</Badge>}
                {isActive && <ArrowRight className="h-3.5 w-3.5 shrink-0 text-graphite-300 dark:text-slate-600" />}
            </div>
            <p className="mt-2 truncate text-[13px] font-semibold text-navy-800 dark:text-slate-100">{title}</p>
            {description && <p className="mt-0.5 line-clamp-1 text-[11px] text-graphite-400 dark:text-slate-500">{description}</p>}
        </>
    );

    // v2.43.0: module cards are the entry points to each domain, so they
    // get a real surface and hover lift -- they should feel like doors, not
    // list rows.
    const className = cn(
        'group block h-full rounded-[10px] border shadow-card transition-all duration-200',
        isLarge ? 'p-4' : 'p-3',
        tone.surface,
        isActive ? 'cursor-pointer hover:-translate-y-0.5 hover:shadow-card-hover' : 'cursor-not-allowed opacity-70'
    );

    if (! isActive) {
        return <div className={className}>{body}</div>;
    }

    // Accept a resolved URL/path as well as a Ziggy route name.
    const target = /^(https?:)?\/\//.test(href) || href.startsWith('/') ? href : route(href, queryParams);

    return (
        <Link href={target} className={className}>
            {body}
        </Link>
    );
}
