import { Link } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

/**
 * Shared Statistic Card (v1.6.5 foundation). Dashboard's `PrimaryCard`,
 * Home's `StatCard`, and PPE Dashboard's `StatCard` are three separate
 * implementations of essentially the same icon+value+label card, each
 * with slightly different sizing/color details. This is the canonical
 * version for any *new* stat card going forward -- the three existing
 * ones were deliberately left as-is (each already works, and each page
 * has small intentional differences in accent handling); consolidating
 * them onto this component is a separate follow-up, not bundled here.
 *
 * Usage:
 *   <StatCard icon={Users} value="128" label="Employees" href={route('employees.index')} />
 *   <StatCard icon={AlertTriangle} value="3" label="PPE Alerts" accent="amber" />
 */
export default function StatCard({ icon: Icon, value, label, href, accent }) {
    const accentClasses = {
        red: 'bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-400',
        amber: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400',
    };
    const iconClass = accentClasses[accent] || 'bg-brand-50 text-brand-600 dark:bg-brand-950/40 dark:text-brand-400';

    const content = (
        <Card className={cn('h-full rounded-2xl bg-white/85 backdrop-blur-sm transition-all duration-200 dark:bg-slate-900/85', href && 'hover:-translate-y-0.5 hover:shadow-card-hover')}>
            <CardContent className="flex items-center gap-4 p-5">
                <div className={cn('flex h-11 w-11 shrink-0 items-center justify-center rounded-xl', iconClass)}>
                    <Icon className="h-5 w-5" />
                </div>
                <div className="min-w-0">
                    <p className="truncate text-lg font-bold text-graphite-900 dark:text-slate-50">{value}</p>
                    <p className="text-xs font-medium uppercase tracking-wide text-graphite-400 dark:text-slate-500">{label}</p>
                </div>
            </CardContent>
        </Card>
    );

    return href ? <Link href={href}>{content}</Link> : content;
}
