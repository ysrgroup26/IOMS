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
    const iconStyle = { backgroundColor: `${color}1a`, color };
    const valueColor = isNegative && value > 0 ? { color } : undefined;

    if (compact) {
        const content = (
            <div className="flex items-center gap-2 rounded-xl border border-steel-100 bg-gradient-to-br from-white to-steel-50/70 px-2.5 py-2 transition-all duration-200 hover:border-steel-200 hover:shadow-lift motion-safe:hover:-translate-y-0.5 dark:border-slate-800 dark:from-slate-900 dark:to-slate-900">
                <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style={iconStyle}>
                    <Icon className="h-3 w-3" />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-[10px] font-medium uppercase tracking-wide text-graphite-400">{label}</p>
                    <p className="text-sm font-bold leading-tight text-navy-900" style={valueColor}>
                        {formatNumber(value)}
                    </p>
                </div>
                {/* Real 12-month history for THIS category. Tinted with the
                    category's own admin-configured colour so the line belongs
                    to the metric rather than to a generic chart palette. */}
                {trend && (
                    <div className="hidden w-10 shrink-0 self-end pb-0.5 sm:block" style={{ color }} aria-hidden="true">
                        <Sparkline points={trend} className="h-5 w-full" />
                    </div>
                )}
            </div>
        );
        return href ? <Link href={href}>{content}</Link> : content;
    }

    const content = (
        <Card tone="steel" className="h-full rounded-2xl transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lift">
            <CardContent className="flex items-center gap-3 p-4">
                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" style={iconStyle}>
                    <Icon className="h-4.5 w-4.5" />
                </div>
                <div className="min-w-0">
                    <p className="truncate text-[11px] font-medium uppercase tracking-wide text-graphite-400">{label}</p>
                    <p className="text-lg font-bold text-graphite-900" style={valueColor}>
                        {formatNumber(value)}
                    </p>
                </div>
            </CardContent>
        </Card>
    );
    return href ? <Link href={href}>{content}</Link> : content;
}
