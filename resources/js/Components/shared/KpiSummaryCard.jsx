import { Link } from '@inertiajs/react';
import Sparkline from '@/Components/shared/Sparkline';
import { Card, CardContent } from '@/Components/ui/card';
import { cn, formatNumber } from '@/lib/utils';
import { resolveIcon } from '@/lib/iconMap';

/**
 * Fully data-driven (v1.5.2): icon and color come from the KPI category's
 * `effective_icon`/`effective_color` (admin-configurable in Settings > KPI
 * Categories, with a sensible built-in fallback when unset) -- nothing
 * about which icon or color belongs to which category is hardcoded here
 * anymore. When `href` is provided, the whole card is a navigation
 * widget (Dashboard's "click FAC to see every FAC this period").
 */
/**
 * v2.44.0: optional `trend` -- a REAL series, never a generated one. The
 * Dashboard already computes 12 months of history per KPI category in
 * DashboardStatsService::monthlyTrend() and renders it as a chart; the
 * same numbers now also give each compact KPI card its own inline
 * history. No new query, no interpolation. A category with no series
 * passes nothing and Sparkline draws nothing.
 */
export default function KpiSummaryCard({ label, value, isNegative, icon, color, href, compact = false, trend }) {
    const Icon = resolveIcon(icon);
    // v2.48.0: was `${color}1a` (10% tint) behind a glyph of the SAME
    // colour -- pale, and invisible once the card behind it was also
    // tinted. Solid fill + white glyph, matching StatCard's restored chip.
    // `1f` / `12` are hex alpha suffixes for the surrounding surface wash,
    // so each KPI card is tinted by its own category colour.
    // v2.136.0: the category's OWN admin-configured colour still drives the
    // tile and the wash -- now as a soft gradient rather than a flat fill.
    // A frosted capsule tinted with the category's colour; the glyph carries it.
    const iconStyle = { backgroundImage: `linear-gradient(150deg, rgba(255,255,255,0.95), ${color}2e)`, color, border: `1px solid ${color}40`, boxShadow: `inset 0 1px 0 0 rgba(255,255,255,0.95), 0 4px 10px -6px ${color}88` };
    const surfaceStyle = undefined;
    const valueColor = isNegative && value > 0 ? { color } : undefined;

    if (compact) {
        const content = (
            <div style={surfaceStyle} className="surface flex items-center gap-2.5 rounded-xl px-3 py-2.5 transition-[box-shadow,transform] duration-base ease-out-soft hover:shadow-card-hover motion-safe:hover:-translate-y-0.5">
                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full" style={iconStyle}>
                    <Icon className="h-4 w-4" />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="line-clamp-2 text-[11px] font-semibold leading-snug text-slate-500">{label}</p>
                    <p className="type-metric text-lg leading-tight text-navy-900" style={valueColor}>
                        {formatNumber(value)}
                    </p>
                </div>
                {/* Real 12-month history for THIS category. Tinted with the
                    category's own admin-configured colour so the line belongs
                    to the metric rather than to a generic chart palette. */}
                {trend && (
                    <div className="hidden w-12 shrink-0 self-end pb-0.5 sm:block" style={{ color }} aria-hidden="true">
                        <Sparkline points={trend} className="h-6 w-full" />
                    </div>
                )}
            </div>
        );
        return href ? <Link href={href}>{content}</Link> : content;
    }

    const content = (
        <Card style={surfaceStyle} className="h-full transition-[box-shadow,transform] duration-base ease-out-soft hover:-translate-y-0.5 hover:shadow-card-hover">
            <CardContent className="flex items-center gap-3 p-4 lg:p-4">
                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" style={iconStyle}>
                    <Icon className="h-[18px] w-[18px]" />
                </div>
                <div className="min-w-0">
                    <p className="line-clamp-2 text-[13px] font-semibold text-slate-600">{label}</p>
                    <p className="type-metric text-[22px] leading-tight text-navy-900" style={valueColor}>
                        {formatNumber(value)}
                    </p>
                </div>
            </CardContent>
        </Card>
    );
    return href ? <Link href={href}>{content}</Link> : content;
}
