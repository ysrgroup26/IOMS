import { Link } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

/**
 * Shared Statistic Card (v1.6.5 foundation, tightened across many passes,
 * exact spec re-applied in v1.11.14 -- the directive explicitly said the
 * number inside the box was STILL too large at the previous 20px/18px
 * scale and gave new, smaller exact values; implemented precisely rather
 * than re-guessed, one more notch down from v1.11.12/13:
 * - Default (primary) size: 12px padding (`p-3`), 32x32 icon (`h-8 w-8`),
 *   9px icon radius (`rounded-[9px]`), 16px icon glyph (`h-4 w-4`), 18px/
 *   600 number (`text-lg font-semibold`), 11px/500 label (unchanged).
 * - `size="sm"` (secondary/compact row): 10px padding (`p-2.5`), 28x28
 *   icon (`h-7 w-7`), 15px icon glyph (`h-[15px] w-[15px]`), 16px/600
 *   number (`text-base font-semibold`), 10px/500 label (`text-[10px]`).
 * - Card radius 10px (`rounded-[10px]`, matching `Card`'s own v1.11.14
 *   retune -- Tailwind's `rounded-xl` is 12px, one step too large now).
 * - Accent colors pull from the exact-hex `success`/`warning`/`danger`
 *   tokens in tailwind.config.js (Tailwind's own `emerald-600`/
 *   `amber-600`/`red-600` render #059669/#d97706/#dc2626, not this
 *   spec's #16A34A/#F59E0B/#EF4444) and Tailwind's own `violet-50`/
 *   `violet-600` for purple (already an exact hex match, no new token).
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
        <Card className={cn('h-full rounded-[10px] bg-white/85 backdrop-blur-sm transition-all duration-200 dark:bg-slate-900/85', href && 'hover:-translate-y-0.5 hover:shadow-card-hover')}>
            <CardContent className={cn('flex items-center', isSmall ? 'gap-2 p-2.5' : 'gap-2.5 p-3')}>
                <div className={cn('flex shrink-0 items-center justify-center', isSmall ? 'h-7 w-7 rounded-[9px]' : 'h-8 w-8 rounded-[9px]', iconClass)}>
                    <Icon className={isSmall ? 'h-[15px] w-[15px]' : 'h-4 w-4'} />
                </div>
                <div className="min-w-0">
                    <p className={cn('truncate font-semibold leading-tight text-graphite-900 dark:text-slate-50', isSmall ? 'text-base' : 'text-lg')}>{value}</p>
                    <p className={cn('truncate font-medium uppercase tracking-wide text-graphite-400 dark:text-slate-500', isSmall ? 'text-[10px]' : 'text-[11px]')}>{label}</p>
                </div>
            </CardContent>
        </Card>
    );

    return href ? <Link href={href}>{content}</Link> : content;
}
