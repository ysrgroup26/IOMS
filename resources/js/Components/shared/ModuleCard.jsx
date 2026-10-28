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
// v2.136.0 (ADR 079): a module card is a frosted bubble with a gradient
// icon tile; the accent tints only the tile and a faint corner wash.
const ACCENTS = {
    brand: { chip: 'icon-tile icon-tile-blue', surface: 'from-sky-50/90' },
    green: { chip: 'icon-tile icon-tile-green', surface: 'from-emerald-50/90' },
    amber: { chip: 'icon-tile icon-tile-amber', surface: 'from-amber-50/90' },
    red: { chip: 'icon-tile icon-tile-rose', surface: 'from-rose-50/90' },
    purple: { chip: 'icon-tile icon-tile-violet', surface: 'from-violet-50/90' },
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
                isLarge ? 'h-12 w-12 rounded-2xl' : 'h-10 w-10',
                tone.chip
            )}
        >
            {Icon && <Icon className={isLarge ? 'h-6 w-6' : 'h-[18px] w-[18px]'} strokeWidth={1.75} />}
        </span>
    );

    const body = isLarge ? (
        <div className="flex items-center gap-3">
            {chip}
            <div className="min-w-0 flex-1">
                <p className="text-base font-bold text-navy-900">{title}</p>
                {description && <p className="truncate text-[13px] text-slate-500">{description}</p>}
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
            <p className="mt-3 text-[14px] font-bold leading-snug text-navy-900">{title}</p>
            {description && <p className="mt-0.5 line-clamp-2 text-xs leading-relaxed text-slate-500">{description}</p>}
        </>
    );

    // v2.43.0: module cards are the entry points to each domain, so they
    // get a real surface and hover lift -- they should feel like doors, not
    // list rows.
    const className = cn(
        'surface group block h-full bg-gradient-to-br via-white/85 to-white/80 transition-[box-shadow,transform] duration-base ease-out-soft',
        isLarge ? 'p-5' : 'p-4',
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
