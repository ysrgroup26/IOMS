import { Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { CalendarDays } from 'lucide-react';

/**
 * v1.11.2 (Final Completion Pass, Part 2/4). The "Department Calendar" half
 * of the ONE Calendar Engine (see `App\Services\CalendarService`) -- a
 * compact read-only list, not a second calendar UI. Every department
 * Dashboard controller feeds this the exact same
 * `CalendarService::departmentEvents($companyIds, $departmentKey)` shape,
 * so this one component covers HSE/HR/Project Management/Logistics/
 * Procurement (and any future department) without per-department variants.
 *
 * Deliberately not shown at all when the department has genuinely no
 * calendar-relevant data source wired up (see docs/MODULES.md) -- an empty
 * state here is still informative (distinguishes "nothing scheduled" from
 * "not applicable"), so callers pass `events` and let this render the
 * empty state itself rather than conditionally omitting the widget.
 */
export default function DepartmentCalendarWidget({ events = [], title = 'Department Calendar', description = 'Next 3 weeks' }) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <div>
                    <CardTitle className="flex items-center gap-2"><CalendarDays className="h-3.5 w-3.5 text-graphite-400" /> {title}</CardTitle>
                    <CardDescription>{description}</CardDescription>
                </div>
                <Link href={route('calendar.index')} className="text-xs font-medium text-brand-600 hover:underline">Full Calendar</Link>
            </CardHeader>
            <CardContent>
                {events.length === 0 ? (
                    <p className="py-6 text-center text-sm text-graphite-400">Nothing scheduled for this department.</p>
                ) : (
                    <div className="divide-y divide-graphite-100 dark:divide-slate-800">
                        {events.map((e, i) => {
                            const content = (
                                <div className="flex items-center justify-between py-2 text-sm">
                                    <span className="truncate font-medium text-graphite-700 dark:text-slate-200">{e.title}</span>
                                    <span className="shrink-0 text-xs text-graphite-400">{new Date(e.start).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}</span>
                                </div>
                            );
                            return e.url ? <Link key={i} href={e.url} className="block hover:text-brand-700">{content}</Link> : <div key={i}>{content}</div>;
                        })}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
