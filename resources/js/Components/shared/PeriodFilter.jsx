import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import GroupedDepartmentSelect from '@/Components/shared/GroupedDepartmentSelect';

export default function PeriodFilter({ year, month, departmentId, years, departments, companies, onChange, showDepartment = true }) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            <Select value={String(year)} onValueChange={(v) => onChange({ year: Number(v), month, departmentId })}>
                <SelectTrigger className="w-28"><SelectValue placeholder="Year" /></SelectTrigger>
                <SelectContent>
                    {years.map((y) => <SelectItem key={y} value={String(y)}>{y}</SelectItem>)}
                </SelectContent>
            </Select>

            <Select
                value={month ? String(month) : 'all'}
                onValueChange={(v) => onChange({ year, month: v === 'all' ? null : Number(v), departmentId })}
            >
                <SelectTrigger className="w-36"><SelectValue placeholder="Month" /></SelectTrigger>
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
