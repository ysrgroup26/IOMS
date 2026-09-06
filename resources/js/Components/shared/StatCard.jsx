import { Link, usePage } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui/card';
import Sparkline from '@/Components/shared/Sparkline';
import { cn } from '@/lib/utils';

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
 * 2. THE ICON CHIP IS LIT. A flat pastel square became a real gradient chip
 *    with its own soft glow, which is what makes a stat row read as a
 *    designed instrument panel rather than a table of numbers.
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

    const accentClasses = {
        red: {
            chip: 'bg-gradient-to-br from-danger to-red-600 text-white shadow-[0_4px_10px_-4px_rgba(220,38,38,0.55)] dark:from-red-900 dark:to-red-800',
            bar: 'bg-danger',
            wash: 'from-danger/[0.07] via-white to-white dark:from-red-950/30 dark:via-slate-900 dark:to-slate-900',
            edge: 'border-danger/20 dark:border-red-900/50',
            spark: 'danger',
        },
        amber: {
            chip: 'bg-gradient-to-br from-warning to-amber-600 text-white shadow-[0_4px_10px_-4px_rgba(217,119,6,0.55)] dark:from-amber-900 dark:to-amber-800',
            bar: 'bg-warning',
            wash: 'from-warning/[0.08] via-white to-white dark:from-amber-950/30 dark:via-slate-900 dark:to-slate-900',
            edge: 'border-warning/20 dark:border-amber-900/50',
            spark: 'warning',
        },
        green: {
            chip: 'bg-gradient-to-br from-success to-emerald-700 text-white shadow-[0_4px_10px_-4px_rgba(22,163,74,0.5)] dark:from-emerald-900 dark:to-emerald-800',
            bar: 'bg-success',
            wash: 'from-success/[0.06] via-white to-white dark:from-emerald-950/30 dark:via-slate-900 dark:to-slate-900',
            edge: 'border-success/20 dark:border-emerald-900/50',
            spark: 'success',
        },
        purple: {
            chip: 'bg-gradient-to-br from-violet-500 to-violet-700 text-white shadow-[0_4px_10px_-4px_rgba(139,92,246,0.5)] dark:from-violet-900 dark:to-violet-800',
            bar: 'bg-violet-500',
            wash: 'from-violet-500/[0.06] via-white to-white dark:from-violet-950/30 dark:via-slate-900 dark:to-slate-900',
            edge: 'border-violet-300/40 dark:border-violet-900/50',
            spark: 'brand',
        },
        neutral: {
            chip: 'bg-gradient-to-br from-graphite-400 to-graphite-500 text-white shadow-[0_4px_10px_-4px_rgba(100,116,139,0.45)] dark:from-slate-700 dark:to-slate-800',
            bar: 'bg-graphite-300',
            wash: 'from-graphite-200/40 via-white to-white dark:from-slate-800/40 dark:via-slate-900 dark:to-slate-900',
            edge: 'border-graphite-200 dark:border-slate-700',
            spark: 'neutral',
        },
    };

    // No accent = the IOMS default surface: the same navy->brand chip the
    // shell and module cards use, so an unaccented stat still belongs to the
    // product rather than looking unstyled.
    const resolved = accentClasses[effectiveAccent] || {
        chip: 'bg-gradient-to-br from-navy-800 to-brand-600 text-white shadow-[0_4px_10px_-4px_rgba(33,102,196,0.5)] dark:from-brand-950 dark:to-brand-900',
        bar: 'bg-brand-500',
        wash: 'from-steel-50 via-white to-white dark:from-slate-900 dark:via-slate-900 dark:to-slate-900',
        edge: 'border-steel-100 dark:border-slate-800',
        spark: 'brand',
    };

    const isSmall = size === 'sm';

    const content = (
        <Card
            className={cn(
                'group relative h-full overflow-hidden rounded-[10px] bg-gradient-to-br transition-all duration-200 hover:shadow-lift motion-safe:hover:-translate-y-0.5',
                resolved.wash,
                resolved.edge,
                href && 'cursor-pointer'
            )}
        >
            {effectiveAccent && <span className={cn('absolute inset-y-0 left-0 w-[3px]', resolved.bar)} aria-hidden="true" />}
            <CardContent className={cn('flex items-center', isSmall ? 'gap-2 p-2.5' : 'gap-2.5 p-3', effectiveAccent && 'pl-3.5')}>
                <div
                    className={cn(
                        'flex shrink-0 items-center justify-center transition-transform duration-200 group-hover:scale-105',
                        isSmall ? 'h-7 w-7 rounded-[9px]' : 'h-8 w-8 rounded-[9px]',
                        resolved.chip
                    )}
                >
                    <Icon className={isSmall ? 'h-[15px] w-[15px]' : 'h-4 w-4'} />
                </div>
                <div className="min-w-0 flex-1">
                    <p className={cn('truncate font-semibold leading-tight text-navy-900 dark:text-slate-50', isSmall ? 'text-base' : 'text-lg')}>{value}</p>
                    <p className={cn('truncate font-medium uppercase tracking-wide text-graphite-400 dark:text-slate-500', isSmall ? 'text-[10px]' : 'text-[11px]')}>{label}</p>
                    {hint && <p className="mt-0.5 truncate text-[10px] font-medium text-graphite-500 dark:text-slate-400">{hint}</p>}
                </div>
                {/* Renders only when the caller genuinely has a series --
                    Sparkline returns null below two real points. */}
                {trend && !isSmall && (
                    <div className="hidden w-16 shrink-0 self-end pb-0.5 sm:block" aria-hidden="true">
                        <Sparkline points={trend} tone={resolved.spark} className="h-6 w-full" />
                    </div>
                )}
            </CardContent>
        </Card>
    );

    return href ? <Link href={href}>{content}</Link> : content;
}
