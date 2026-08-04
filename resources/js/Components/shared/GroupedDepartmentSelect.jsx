import {
    Select, SelectGroup, SelectLabel, SelectTrigger, SelectValue, SelectContent, SelectItem,
} from '@/Components/ui/select';

/**
 * Department dropdown grouped by Company (v1.3.1) -- avoids one long,
 * duplicate-name-prone list when a Company filter isn't already narrowing
 * things down (e.g. GAJ and Maintenance both have a "HSE", "Engineering",
 * etc.). Groups are rendered in whatever order `companies` arrives in
 * (already alphabetical from the backend); departments within each group
 * follow their configured display order (also from the backend).
 */
export default function GroupedDepartmentSelect({
    departments,
    companies,
    value,
    onChange,
    placeholder = 'All Departments',
    className,
    disabled = false,
}) {
    const byCompany = companies.map((company) => ({
        company,
        departments: departments.filter((d) => d.company_id === company.id),
    })).filter((group) => group.departments.length > 0);

    return (
        <Select value={value ? String(value) : 'all'} onValueChange={(v) => onChange(v === 'all' ? null : v)} disabled={disabled}>
            <SelectTrigger className={className}><SelectValue placeholder={placeholder} /></SelectTrigger>
            <SelectContent>
                <SelectItem value="all">{placeholder}</SelectItem>
                {byCompany.map(({ company, departments: deptList }) => (
                    <SelectGroup key={company.id}>
                        <SelectLabel>{company.name}</SelectLabel>
                        {deptList.map((d) => (
                            <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>
                        ))}
                    </SelectGroup>
                ))}
            </SelectContent>
        </Select>
    );
}
