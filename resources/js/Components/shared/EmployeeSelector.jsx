import { useState, useEffect, useRef, useCallback } from 'react';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { Search, X, Loader2, Check } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * v2.38.0 -- shared Employee selector (platform primitive).
 *
 * WHY THIS EXISTS: seven controllers preload the tenant's entire active
 * employee directory into the Inertia page payload purely so a form can
 * render a `<select>`. In the shipyards and construction firms IOMS
 * targets, 1,500-3,000 workers is normal -- so those pages ship a large
 * payload and then render a dropdown nobody can realistically use. This
 * component queries `/employee-lookup` on demand instead (tenant-scoped,
 * capped at 50 rows per request, department-grouped server-side).
 *
 * WHY NOT EXTEND `Combobox`: that component is deliberately "suggest
 * from a list but allow free text", operating on a preloaded array of
 * STRINGS and returning a string. This one must return real Employee
 * IDs, must never accept a value outside the directory (the FK and its
 * `InCurrentTenant` validation depend on that), and is async + grouped +
 * optionally multi-select. Bending Combobox to cover both would break
 * its own contract; these are two different jobs.
 *
 * DESIGN NOTE -- why entity IDs and not free text: keeping this a real
 * FK is what lets IOMS answer "which permits was this person responsible
 * for?", exactly the question an HSE audit asks. The pain that made free
 * text tempting was the unusable dropdown -- which is what this
 * component removes.
 *
 * Props:
 *   mode        'single' | 'multiple'  (default 'single')
 *   value       id | null (single) OR array of ids (multiple)
 *   onChange(next)  emits the same shape it was given
 */
export default function EmployeeSelector({
    mode = 'single',
    value,
    onChange,
    placeholder = 'Cari nama atau NIK karyawan...',
    disabled = false,
    className,
}) {
    const isMultiple = mode === 'multiple';
    const selectedIds = normaliseIds(value, isMultiple);
    const selectedKey = selectedIds.join(',');

    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    // Resolved {id, full_name, ...} for whatever is currently selected.
    // Needed because a selected employee may not be in the current search
    // window (or may since have been deactivated), and rendering a blank
    // chip for a real saved value would misrepresent the record.
    const [resolved, setResolved] = useState({});

    const containerRef = useRef(null);
    const requestSeq = useRef(0);

    useEffect(() => {
        function onClickOutside(e) {
            if (containerRef.current && !containerRef.current.contains(e.target)) setOpen(false);
        }
        document.addEventListener('mousedown', onClickOutside);
        return () => document.removeEventListener('mousedown', onClickOutside);
    }, []);

    // Hydrate names for any selected id we don't have yet (edit form load).
    useEffect(() => {
        const missing = selectedIds.filter((id) => !resolved[id]);
        if (missing.length === 0) return;

        const params = new URLSearchParams();
        missing.forEach((id) => params.append('ids[]', id));

        fetch('/employee-lookup?' + params.toString(), { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : { data: [] }))
            .then((body) => {
                setResolved((prev) => {
                    const next = { ...prev };
                    (body.data || []).forEach((e) => { next[e.id] = e; });
                    return next;
                });
            })
            .catch(() => { /* leaving an id unresolved is safer than inventing a name */ });
    }, [selectedKey, resolved]);

    const search = useCallback((term) => {
        const seq = ++requestSeq.current;
        setLoading(true);

        const params = new URLSearchParams();
        if (term) params.set('q', term);

        fetch('/employee-lookup?' + params.toString(), { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : { data: [] }))
            .then((body) => {
                // Ignore out-of-order responses so a slow earlier request
                // cannot overwrite a newer one's results.
                if (seq !== requestSeq.current) return;
                setResults(body.data || []);
            })
            .catch(() => { if (seq === requestSeq.current) setResults([]); })
            .finally(() => { if (seq === requestSeq.current) setLoading(false); });
    }, []);

    useEffect(() => {
        if (!open) return undefined;
        const t = setTimeout(() => search(query.trim()), query.trim() ? 250 : 0);
        return () => clearTimeout(t);
    }, [query, open, search]);

    function toggle(employee) {
        setResolved((prev) => ({ ...prev, [employee.id]: employee }));

        if (!isMultiple) {
            onChange(String(employee.id));
            setOpen(false);
            setQuery('');
            return;
        }

        const has = selectedIds.includes(String(employee.id));
        onChange(has
            ? selectedIds.filter((id) => id !== String(employee.id))
            : [...selectedIds, String(employee.id)]);
    }

    function clearSelection(id) {
        onChange(isMultiple ? selectedIds.filter((s) => s !== String(id)) : '');
    }

    const grouped = groupByDepartment(results);

    return (
        <div ref={containerRef} className={cn('relative', className)}>
            <div className="relative">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                <Input
                    className="pl-8"
                    value={query}
                    disabled={disabled}
                    placeholder={placeholder}
                    onFocus={() => setOpen(true)}
                    onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
                />
                {loading && <Loader2 className="absolute right-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 animate-spin text-graphite-400" />}
            </div>

            {open && (
                <div className="absolute z-40 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-graphite-200 bg-white shadow-card-hover dark:border-slate-700 dark:bg-slate-900">
                    {!loading && results.length === 0 && (
                        <p className="px-3 py-4 text-center text-xs text-graphite-400">
                            {query.trim() ? 'Karyawan tidak ditemukan.' : 'Ketik untuk mencari karyawan.'}
                        </p>
                    )}

                    {grouped.map(([department, rows]) => (
                        <div key={department}>
                            <p className="sticky top-0 bg-graphite-50 px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-graphite-500 dark:bg-slate-800 dark:text-slate-400">
                                {department}
                            </p>
                            {rows.map((e) => {
                                const active = selectedIds.includes(String(e.id));
                                return (
                                    <button
                                        type="button"
                                        key={e.id}
                                        onClick={() => toggle(e)}
                                        className={cn(
                                            'flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition-colors hover:bg-brand-50 dark:hover:bg-slate-800',
                                            active && 'bg-brand-50/60 dark:bg-brand-950/30'
                                        )}
                                    >
                                        <span className="min-w-0">
                                            <span className="block truncate text-graphite-900 dark:text-slate-100">{e.full_name}</span>
                                            <span className="block truncate text-xs text-graphite-400">{e.employee_id}</span>
                                        </span>
                                        {active && <Check className="h-3.5 w-3.5 shrink-0 text-brand-600" />}
                                    </button>
                                );
                            })}
                        </div>
                    ))}
                </div>
            )}

            {selectedIds.length > 0 && (
                <div className="flex flex-wrap items-center gap-1.5 pt-2">
                    {selectedIds.map((id) => (
                        <Badge key={id} variant="secondary" className="gap-1 pr-1">
                            {/* v2.38.0: employee_id is shown alongside the name
                                because duplicate names are COMMON in a large
                                industrial workforce -- browser testing surfaced two
                                different "Siti Wijaya" records selected at once,
                                rendering as two identical chips with no way to tell
                                which was which. */}
                            <span className="truncate">{resolved[id]?.full_name ?? ('#' + id)}</span>
                            {resolved[id]?.employee_id && (
                                <span className="text-[10px] font-normal text-graphite-400">{resolved[id].employee_id}</span>
                            )}
                            {!disabled && (
                                <button
                                    type="button"
                                    aria-label="Hapus"
                                    onClick={() => clearSelection(id)}
                                    className="rounded-full p-0.5 hover:bg-graphite-200 dark:hover:bg-slate-700"
                                >
                                    <X className="h-2.5 w-2.5" />
                                </button>
                            )}
                        </Badge>
                    ))}
                    {isMultiple && (
                        <span className="text-xs text-graphite-400">Total: {selectedIds.length} orang</span>
                    )}
                </div>
            )}
        </div>
    );
}

function normaliseIds(value, isMultiple) {
    if (isMultiple) return (value || []).map(String);
    return value ? [String(value)] : [];
}

function groupByDepartment(rows) {
    const map = new Map();
    rows.forEach((r) => {
        const key = r.group || 'Tanpa Departemen';
        if (!map.has(key)) map.set(key, []);
        map.get(key).push(r);
    });
    return Array.from(map.entries());
}
