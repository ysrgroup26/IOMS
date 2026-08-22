import { Link } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

/**
 * Shared Statistic Card (v1.6.5 foundation, tightened through v1.11.12,
 * exact spec applied in v1.11.12/13 -- Final Visual Design System pass,
 * against the actual reference screenshots in v1.11.13). Pixel/hex
 * targets implemented precisely rather than re-guessed:
 * - Icon container 36x36 (`h-9 w-9`), radius 10px (`rounded-[10px]`,
 *   Tailwind has no built-in step between `rounded-lg` 8px and
 *   `rounded-xl` 12px), icon glyph 18px (`h-[18px] w-[18px]`).
 * - Card padding 14px (`p-3.5`).
 * - KPI number 20px/600 (`text-xl font-semibold`), label 11px/500.
 * - Accent colors pull from the exact-hex `success`/`warning`/`danger`
 *   tokens in tailwind.config.js (Tailwind's own `emerald-600`/
 *   `amber-600`/`red-600` render #059669/#d97706/#dc2626, not this
 *   spec's #16A34A/#F59E0B/#EF4444) and Tailwind's own `violet-50`/
 *   `violet-600` for purple (already an exact match, no new token).
 *
 * v1.11.13: `size="sm"` added. The reference screenshots show TWO
 * distinct KPI card scales on the same HSE Overview page -- a primary
 * row (this component's default size) and a visually smaller
 * "Ringkasan KPI" secondary row (Fatalitas/LTI/FAC/etc.) -- not one
 * scale used everywhere. `size="sm"` matches that second scale exactly
 * (spec's own "Small KPI Cards" section): 18px number, 32x32 icon,
 * 12px padding, same 10px icon radius.
 *
 * Usage:
 *   <StatCard icon={Users} value="128" label="Employees" href={route('employees.index')} />
 *   <StatCard icon={AlertTriangle} value="3" label="PPE Alerts" accent="amber" />
 *   <StatCard icon={Skull} value="0" label="Fatalitas" accent="red" size="sm" />
 */
export default function StatCard({ icon: Icon, value, label, href, accent, size = 'default' }) {
    const accentClasses = {
        red: 'bg-danger-light text-danger dark:bg-red-950/40 dark:text-red-400',
        amber: 'bg-warning-light text-warning dark:bg-amber-950/40 dark:text-amber-400',
        green: 'bg-success-light text-success dark:bg-emerald-950/40 dark:text-emerald-400',
        purple: 'bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-400',
        neutral: 'bg-graphite-100 text-graphite-500 dark:bg-slate-800 dark:text-slate-400',
    };
    const iconClass = accentClasses[accent] || 'bg-brand-50 text-brand-600 dark:bg-brand-950/40 dark:text-brand-400';
    const isSmall = size === 'sm';

    const content = (
        <Card className={cn('h-full rounded-xl bg-white/85 backdrop-blur-sm transition-all duration-200 dark:bg-slate-900/85', href && 'hover:-translate-y-0.5 hover:shadow-card-hover')}>
            <CardContent className={cn('flex items-center', isSmall ? 'gap-2.5 p-3' : 'gap-3 p-3.5')}>
                <div className={cn('flex shrink-0 items-center justify-center rounded-[10px]', isSmall ? 'h-8 w-8' : 'h-9 w-9', iconClass)}>
                    <Icon className={isSmall ? 'h-4 w-4' : 'h-[18px] w-[18px]'} />
                </div>
                <div className="min-w-0">
                    <p className={cn('truncate font-semibold leading-tight text-graphite-900 dark:text-slate-50', isSmall ? 'text-lg' : 'text-xl')}>{value}</p>
                    <p className="truncate text-[11px] font-medium uppercase tracking-wide text-graphite-400 dark:text-slate-500">{label}</p>
                </div>
            </CardContent>
        </Card>
    );

    return href ? <Link href={href}>{content}</Link> : content;
}
