import { useState, useRef, useEffect } from 'react';
import { Link } from '@inertiajs/react';
import {
    Search, Users, FolderKanban, AlertTriangle, PackageSearch, CalendarDays, Flag, PackageCheck, Building2,
    HardHat, ClipboardCheck, Flame, Box, Truck,
} from 'lucide-react';

// Milestone 3 (Task #52): generalized beyond Employees/Projects to every
// real module with a search-worthy detail page. Adding a category later
// = one more entry here + the matching key in GlobalSearchController's
// response, nothing else in this component changes.
// v2.2.0 (IOMS OS Ecosystem pass, Part 7): added PPE/CAPA/PTW/Asset/
// Vendor -- see GlobalSearchController's own doc comment for the
// tenant-scoping + RBAC-gating fix that came with these.
const CATEGORIES = [
    { key: 'employees', label: 'Employees', icon: Users },
    { key: 'projects', label: 'Projects', icon: FolderKanban },
    { key: 'incidents', label: 'Incidents', icon: AlertTriangle },
    { key: 'material_requests', label: 'Material Requests', icon: PackageSearch },
    { key: 'leave_requests', label: 'Leave Requests', icon: CalendarDays },
    { key: 'milestones', label: 'Milestones', icon: Flag },
    { key: 'goods_receipts', label: 'Goods Receipts', icon: PackageCheck },
    { key: 'companies', label: 'Companies', icon: Building2 },
    { key: 'ppe', label: 'PPE', icon: HardHat },
    { key: 'capas', label: 'CAPA', icon: ClipboardCheck },
    { key: 'ptws', label: 'PTW', icon: Flame },
    { key: 'assets', label: 'Assets', icon: Box },
    { key: 'vendors', label: 'Vendors', icon: Truck },
];

const EMPTY_RESULTS = Object.fromEntries(CATEGORIES.map((c) => [c.key, []]));

/**
 * Global search (v1.6.3; generalized in Milestone 3, Task #52). Searches
 * real, existing data via GlobalSearchController -- Employees, Projects,
 * Incidents, Material Requests, Leave Requests, Milestones, Goods
 * Receipts, Companies. Ctrl+K / Cmd+K focuses this input from anywhere
 * (the "command palette" trigger); it stays this same inline dropdown
 * rather than a separate full-screen modal, since that's already the
 * established pattern here and works well.
 */
export default function GlobalSearch() {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState(EMPTY_RESULTS);
    const [open, setOpen] = useState(false);
    const containerRef = useRef(null);
    const inputRef = useRef(null);

    useEffect(() => {
        function handleClickOutside(e) {
            if (containerRef.current && !containerRef.current.contains(e.target)) setOpen(false);
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    useEffect(() => {
        function handleKeydown(e) {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                inputRef.current?.focus();
                setOpen(true);
            }
        }
        document.addEventListener('keydown', handleKeydown);
        return () => document.removeEventListener('keydown', handleKeydown);
    }, []);

    useEffect(() => {
        if (query.trim().length < 2) {
            setResults(EMPTY_RESULTS);
            return;
        }
        const timeout = setTimeout(() => {
            fetch(route('search') + '?q=' + encodeURIComponent(query))
                .then((r) => {
                    if (!r.ok) throw new Error(`Search failed (${r.status})`);
                    return r.json();
                })
                .then(setResults)
                .catch((err) => console.error('Global search failed:', err));
        }, 250);
        return () => clearTimeout(timeout);
    }, [query]);

    const hasResults = CATEGORIES.some((c) => (results[c.key] ?? []).length > 0);

    return (
        <div ref={containerRef} className="relative hidden sm:block">
            <div className="relative">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                <input
                    ref={inputRef}
                    value={query}
                    onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
                    onFocus={() => setOpen(true)}
                    placeholder="Search employees, projects..."
                    className="h-[34px] w-[380px] rounded-md border border-graphite-200 bg-graphite-50/60 pl-8 pr-10 text-xs text-graphite-700 outline-none transition-colors focus:border-brand-300 focus:bg-white dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-200 dark:focus:bg-slate-800"
                />
                <kbd className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 rounded border border-graphite-200 bg-white px-1 text-[10px] text-graphite-400 dark:border-slate-600 dark:bg-slate-900">
                    Ctrl K
                </kbd>
            </div>

            {open && query.trim().length >= 2 && (
                <div className="absolute left-0 top-full z-[120] mt-1 w-72 max-h-[60vh] overflow-y-auto rounded-lg border border-graphite-200 bg-white shadow-card-hover">
                    {!hasResults ? (
                        <p className="px-3 py-4 text-center text-xs text-graphite-400">No matches found.</p>
                    ) : (
                        CATEGORIES.map(({ key, label, icon: Icon }) => {
                            const items = results[key] ?? [];
                            if (items.length === 0) return null;
                            return (
                                <div key={key} className="border-b border-graphite-100 py-1 last:border-0">
                                    <p className="px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-graphite-400">{label}</p>
                                    {items.map((r) => (
                                        <Link key={`${key}-${r.id}`} href={r.url} className="flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-graphite-50" onClick={() => setOpen(false)}>
                                            <Icon className="h-3.5 w-3.5 shrink-0 text-graphite-400" />
                                            <span className="min-w-0 flex-1 truncate text-graphite-700">{r.title}</span>
                                            {r.subtitle && <span className="shrink-0 text-xs text-graphite-400">{r.subtitle}</span>}
                                        </Link>
                                    ))}
                                </div>
                            );
                        })
                    )}
                </div>
            )}
        </div>
    );
}
