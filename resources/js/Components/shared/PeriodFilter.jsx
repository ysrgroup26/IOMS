import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import GroupedDepartmentSelect from '@/Components/shared/GroupedDepartmentSelect';
import { cn } from '@/lib/utils';

// v2.36.0 (Visual System 2.0): new optional `triggerClassName` -- the
// Dashboard hero is now a dark navy surface (Part 12) and needs its
// filter triggers to actually be readable against it; every other
// existing caller passes nothing and keeps the exact same default light
// trigger styling as before. Threaded onto this component's own two
// SelectTriggers only (GroupedDepartmentSelect keeps its own default
// styling -- not used on the one dark-surface caller, since Dashboard
// renders with `showDepartment={false}`).
export default function PeriodFilter({ year, month, departmentId, years, departments, companies, onChange, showDepartment = true, triggerClassName }) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            <Select value={String(year)} onValueChange={(v) => onChange({ year: Number(v), month, departmentId })}>
                <SelectTrigger className={cn('w-28', triggerClassName)}><SelectValue placeholder="Year" /></SelectTrigger>
                <SelectContent>
                    {years.map((y) => <SelectItem key={y} value={String(y)}>{y}</SelectItem>)}
                </SelectContent>
            </Select>

            <Select
                value={month ? String(month) : 'all'}
                onValueChange={(v) => onChange({ year, month: v === 'all' ? null : Number(v), departmentId })}
            >
                <SelectTrigger className={cn('w-36', triggerClassName)}><SelectValue placeholder="Month" /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All Months</SelectItem>
                    {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
                        <SelectItem key={m} value={String(m)}>
                            {new Date(2000, m - 1).toLocaleString('en-US', { month: 'long' })}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {showDepartment && (
                <GroupedDepartmentSelect
                    className="w-44"
                    departments={departments}
                    companies={companies || []}
                    value={departmentId}
                    onChange={(v) => onChange({ year, month, departmentId: v ? Number(v) : null })}
                />
            )}
        </div>
    );
}
