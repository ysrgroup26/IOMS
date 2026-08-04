import { Link } from '@inertiajs/react';
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
export default function KpiSummaryCard({ label, value, isNegative, icon, color, href, compact = false }) {
    const Icon = resolveIcon(icon);
    const iconStyle = { backgroundColor: `${color}1a`, color };
    const valueColor = isNegative && value > 0 ? { color } : undefined;

    if (compact) {
        const content = (
            <div className="flex items-center gap-2 rounded-xl border border-graphite-100 bg-white/90 px-2.5 py-2 backdrop-blur-sm transition-all duration-200 hover:border-graphite-200 hover:shadow-card">
                <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md" style={iconStyle}>
                    <Icon className="h-3 w-3" />
                </div>
                <div className="min-w-0">
                    <p className="truncate text-[10px] font-medium uppercase tracking-wide text-graphite-400">{label}</p>
                    <p className="text-sm font-bold leading-tight text-graphite-900" style={valueColor}>
                        {formatNumber(value)}
                    </p>
                </div>
            </div>
        );
        return href ? <Link href={href}>{content}</Link> : content;
    }

    const content = (
        <Card className="h-full rounded-2xl bg-white/85 backdrop-blur-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-card-hover">
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
