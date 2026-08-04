import { useState, useRef, useEffect } from 'react';
import { Link } from '@inertiajs/react';
import { Search, Users, FolderKanban } from 'lucide-react';

/**
 * Global search (v1.6.3) -- searches real, existing data (Employees,
 * Projects) via GlobalSearchController. Intentionally does not search
 * Incidents/Inspections/Permits/Assets since those modules don't exist
 * yet in this application.
 */
export default function GlobalSearch() {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState({ employees: [], projects: [] });
    const [open, setOpen] = useState(false);
    const containerRef = useRef(null);

    useEffect(() => {
        function handleClickOutside(e) {
            if (containerRef.current && !containerRef.current.contains(e.target)) setOpen(false);
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    useEffect(() => {
        if (query.trim().length < 2) {
            setResults({ employees: [], projects: [] });
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

    const hasResults = results.employees.length > 0 || results.projects.length > 0;

    return (
        <div ref={containerRef} className="relative hidden sm:block">
            <div className="relative">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                <input
                    value={query}
                    onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
                    onFocus={() => setOpen(true)}
                    placeholder="Search employees, projects..."
                    className="h-[34px] w-[380px] rounded-md border border-graphite-200 bg-graphite-50/60 pl-8 pr-2 text-xs text-graphite-700 outline-none transition-colors focus:border-brand-300 focus:bg-white dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-200 dark:focus:bg-slate-800"
                />
            </div>

            {open && query.trim().length >= 2 && (
                <div className="absolute left-0 top-full z-[120] mt-1 w-72 overflow-hidden rounded-lg border border-graphite-200 bg-white shadow-card-hover">
                    {!hasResults ? (
                        <p className="px-3 py-4 text-center text-xs text-graphite-400">No matches found.</p>
                    ) : (
                        <>
                            {results.employees.length > 0 && (
                                <div className="border-b border-graphite-100 py-1">
                                    <p className="px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-graphite-400">Employees</p>
                                    {results.employees.map((r) => (
                                        <Link key={`emp-${r.id}`} href={r.url} className="flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-graphite-50" onClick={() => setOpen(false)}>
                                            <Users className="h-3.5 w-3.5 shrink-0 text-graphite-400" />
                                            <span className="min-w-0 flex-1 truncate text-graphite-700">{r.title}</span>
                                            {r.subtitle && <span className="shrink-0 text-xs text-graphite-400">{r.subtitle}</span>}
                                        </Link>
                                    ))}
                                </div>
                            )}
                            {results.projects.length > 0 && (
                                <div className="py-1">
                                    <p className="px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-graphite-400">Projects</p>
                                    {results.projects.map((r) => (
                                        <Link key={`proj-${r.id}`} href={r.url} className="flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-graphite-50" onClick={() => setOpen(false)}>
                                            <FolderKanban className="h-3.5 w-3.5 shrink-0 text-graphite-400" />
                                            <span className="min-w-0 flex-1 truncate text-graphite-700">{r.title}</span>
                                            {r.subtitle && <span className="shrink-0 text-xs text-graphite-400">{r.subtitle}</span>}
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </>
                    )}
                </div>
            )}
        </div>
    );
}
