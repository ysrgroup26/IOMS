import { Link, usePage } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui/card';
import Sparkline from '@/Components/shared/Sparkline';
import { cn } from '@/lib/utils';
import { ChevronRight } from 'lucide-react';

/**
 * Shared Statistic Card. Exact scale pinned in v1.11.14 (12px padding,
 * 32x32 icon, 18px/600 number, 11px/500 label; `size="sm"` one notch down)
 * and unchanged since -- this is the densest repeated surface in IOMS and
 * its measurements are load-bearing for every grid that uses it.
 *
 * v2.29.0 added the left accent bar, the optional `hint` line and the hover
 * lift. v2.39.0 added the "green is a claim" downgrade.
 *
 * ---------------------------------------------------------------------
 * v2.44.0 -- FROM A BOX WITH A NUMBER TO AN INFORMATION SURFACE.
 *
 * This component appears on 21 pages and was, visually, a white rectangle
 * with a thin coloured edge. Multiply that by a six-card row and you get
 * the "flat and white" reading -- not because any one card was wrong, but
 * because a row of them had no material.
 *
 * Three changes, all structural rather than decorative:
 *
 * 1. THE SURFACE CARRIES ITS OWN SEMANTIC. Each accent now tints the whole
 *    card with a soft diagonal wash of its own hue instead of only marking
 *    the 3px edge, so a red card is legible as a problem from across the
 *    room. The wash is deliberately weak (a ~4-6% tint) -- enough to give
 *    the surface material, nowhere near enough to fight the number.
 *
 * 2. THE CHIP IS THE CONTRAST ANCHOR. A solid accent-filled container with
 *    a white glyph and soft depth. v2.45.0 had softened this to a pale tint
 *    with a coloured glyph at the same time as softening the surface, which
 *    left tint-on-tint -- a light chip on a light tinted card, with nothing
 *    carrying contrast. The chip supplies the dark anchor; the surface wash
 *    (point 1) supplies the soft colour around it. Chip shadows are ~0.38
 *    alpha: present as depth, short of a glow.
 *
 * 3. IT CAN CARRY REAL HISTORY. An optional `trend` array renders an inline
 *    Sparkline. Nothing is generated: the sparkline refuses to draw with
 *    fewer than two real points (see Sparkline), so a caller with no series
 *    passes nothing and the card renders exactly as it always has. This is
 *    how "alive" gets added without inventing data.
 *
 * The v2.39.0 rule is untouched and still runs first: a positive accent is
 * a CLAIM, so green downgrades to neutral when the tenant has no data that
 * could have produced it. Amber/red pass through -- a warning that turns
 * out to be unmeasurable should still be visible.
 *
 * Usage:
 *   <StatCard icon={Users} value="128" label="Employees" href={...} />
 *   <StatCard icon={AlertTriangle} value="3" label="PPE Alerts" accent="amber" />
 *   <StatCard icon={Activity} value="12" label="Permits" accent="green" trend={realSeries} />
 */
export default function StatCard({ icon: Icon, value, label, hint, href, accent, size = 'default', trend }) {
    // v2.39.0 -- GREEN IS A CLAIM. Callers write `accent={x > 0 ? 'red' : 'green'}`,
    // which is correct once data exists and actively misleading before it does:
    // browser-verified on an empty tenant, HSE Overview showed six KPI cards
    // reading 0 in the healthy colour on a database with no records at all.
    // Encoded here because it is a property of the design system, not of any
    // one page.
    const { readiness } = usePage().props;
    const claimsUnsupported = readiness ? readiness.is_operational === false : false;
    const effectiveAccent = claimsUnsupported && accent === 'green' ? 'neutral' : accent;

    // v2.45.0 -- SOFTENED. v2.44.0 made every chip a saturated gradient with
    // its own coloured drop-glow; multiplied across a six-card row that reads
    // as a strip of glowing lozenges, which is loud rather than premium. A
    // chip is now a soft tint carrying a coloured GLYPH, the surface wash is
    // weaker still, and the coloured glows are gone. Depth comes from the
    // shared shadow token and the tonal step against the ground -- not from
    // saturation. Colour is left doing one job: marking semantics.
    // v2.136.0 (ADR 079) -- the bubble KPI card. An icon on a gradient tile
    // whose colour says what the figure MEANS, the label beside it in
    // sentence case, and the number set large in Manrope's tabular figures.
    // The surface carries only a faint wash of the same hue; the old 3px
    // coloured edge is gone because the tile already carries the semantic.
    const accentClasses = {
        red: { tile: 'icon-tile-rose', wash: 'from-rose-50/90', spark: 'danger' },
        amber: { tile: 'icon-tile-amber', wash: 'from-amber-50/90', spark: 'warning' },
        green: { tile: 'icon-tile-green', wash: 'from-emerald-50/90', spark: 'success' },
        purple: { tile: 'icon-tile-violet', wash: 'from-violet-50/90', spark: 'brand' },
        neutral: { tile: 'icon-tile-slate', wash: 'from-slate-50/90', spark: 'neutral' },
    };

    const resolved = accentClasses[effectiveAccent] || { tile: 'icon-tile-blue', wash: 'from-sky-50/90', spark: 'brand' };
    const isSmall = size === 'sm';

    const content = (
        <Card
            className={cn(
                'group relative h-full overflow-hidden bg-gradient-to-br via-white/85 to-white/80 transition-[box-shadow,transform] duration-base ease-out-soft',
                resolved.wash,
                href && 'cursor-pointer hover:shadow-card-hover motion-safe:hover:-translate-y-0.5'
            )}
        >
            <CardContent className={cn('flex flex-col', isSmall ? 'gap-2 p-3.5 lg:p-3.5' : 'gap-3 p-4 lg:p-5')}>
                <div className="flex items-start justify-between gap-2">
                    <div className={cn('icon-tile', resolved.tile, isSmall ? 'h-8 w-8 rounded-[10px]' : 'h-10 w-10')}>
                        <Icon className={isSmall ? 'h-4 w-4' : 'h-[18px] w-[18px]'} strokeWidth={2} />
                    </div>
                    {trend ? (
                        <div className={cn('hidden shrink-0 sm:block', isSmall ? 'w-14' : 'w-20')} aria-hidden="true">
                            {/* Renders only when the caller genuinely has a series --
                                Sparkline returns null below two real points. */}
                            <Sparkline points={trend} tone={resolved.spark} className={isSmall ? 'h-6 w-full' : 'h-8 w-full'} />
                        </div>
                    ) : href && !isSmall ? (
                        <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-white/80 text-slate-400 ring-1 ring-steel-100 transition-colors duration-fast group-hover:text-brand-600" aria-hidden="true">
                            <ChevronRight className="h-3.5 w-3.5" />
                        </span>
                    ) : null}
                </div>
                <div className="min-w-0">
                    <p className={cn('type-metric truncate leading-none text-navy-900', isSmall ? 'text-[22px]' : 'text-[30px]')}>{value}</p>
                    {/* v2.68.0: the label is never truncated -- it is the only
                        thing that says WHAT the number counts. */}
                    <p className={cn('mt-1.5 line-clamp-2 font-semibold leading-snug text-slate-600', isSmall ? 'text-xs' : 'text-[13px]')}>{label}</p>
                </div>
                {hint && <p className="-mt-1.5 truncate text-[11px] font-medium text-slate-500">{hint}</p>}
            </CardContent>
        </Card>
    );

    return href ? <Link href={href}>{content}</Link> : content;
}
