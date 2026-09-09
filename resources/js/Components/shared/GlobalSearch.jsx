import { useState, useRef, useEffect } from 'react';
import { Link } from '@inertiajs/react';
import {
    Search, Users, FolderKanban, AlertTriangle, PackageSearch, CalendarDays, Flag, PackageCheck, Building2,
    HardHat, ClipboardCheck, Flame, Box, Truck, ShieldAlert, FileWarning,
} from 'lucide-react';

// Milestone 3 (Task #52): generalized beyond Employees/Projects to every
// real module with a search-worthy detail page. Adding a category later
// = one more entry here + the matching key in GlobalSearchController's
// response, nothing else in this component changes.
// v2.2.0 (IOMS OS Ecosystem pass, Part 7): added PPE/CAPA/PTW/Asset/
// Vendor -- see GlobalSearchController's own doc comment for the
// tenant-scoping + RBAC-gating fix that came with these.
// v2.5.0 (Field HSE Experience pass, Part 23): added HIRADC/JSA --
// confirmed missing via audit, not a previously-deferred category.
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
    { key: 'hiradcs', label: 'HIRADC', icon: ShieldAlert },
    { key: 'jsas', label: 'JSA', icon: FileWarning },
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
 *
 * v2.67.0 -- THE 380 PIXELS THAT BROKE EVERY AUTHENTICATED PAGE.
 *
 * This field was `w-[380px]`, a hard width, rendered from `sm:` (640px)
 * upward. A flex item cannot shrink below an explicit width without
 * `min-w-0`, so from 640px to roughly 1140px the header was simply wider
 * than the page and every authenticated screen scrolled sideways --
 * measured at 265px of overflow at 640px, 137px at 768px (iPad portrait)
 * and 89px at 1024px (iPad landscape, where the 240px rail eats the gain).
 * It had been recorded as a known TopBar issue three times without the
 * band ever being measured; the low end was 128px lower than believed.
 *
 * The fix is not a smaller number. A single fixed width cannot serve a
 * header whose other contents change at four breakpoints, so the field
 * now has TWO presentations over ONE piece of state:
 *
 *   < sm   nothing. Unchanged -- MobileBottomNav owns navigation there.
 *   sm-lg  an icon that opens the field in a popover, full size.
 *   lg+    the inline field, `w-[380px]` as a basis it may shrink FROM.
 *
 * Shrinking is what the original could not do, and it is why every other
 * control in the header is now `shrink-0`: with exactly one item able to
 * give way, the outcome is predictable instead of every label squashing
 * at once.
 */
export default function GlobalSearch() {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState(EMPTY_RESULTS);
    const [open, setOpen] = useState(false);
    // Only meaningful below lg, where the field lives behind an icon.
    const [expanded, setExpanded] = useState(false);
    const containerRef = useRef(null);
    const inlineRef = useRef(null);
    const compactRef = useRef(null);

    function dismiss() {
        setOpen(false);
        setExpanded(false);
    }

    useEffect(() => {
        function handleClickOutside(e) {
            if (containerRef.current && !containerRef.current.contains(e.target)) {
                setOpen(false);
                setExpanded(false);
            }
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    useEffect(() => {
        function handleKeydown(e) {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();

                // Ctrl+K has to reach whichever field is actually on
                // screen. `offsetParent` is null for a `display:none`
                // element, which is precisely what the breakpoint classes
                // do to the one that isn't in use.
                const inline = inlineRef.current;
                if (inline && inline.offsetParent !== null) {
                    inline.focus();
                    setOpen(true);
                    return;
                }

                setExpanded(true);
                requestAnimationFrame(() => compactRef.current?.focus());
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
    const showResults = open && query.trim().length >= 2;

    const resultsList = !hasResults ? (
        <p className="px-3 py-4 text-center text-xs text-graphite-400">No matches found.</p>
    ) : (
        CATEGORIES.map(({ key, label, icon: Icon }) => {
            const items = results[key] ?? [];
            if (items.length === 0) return null;
            return (
                <div key={key} className="border-b border-graphite-100 py-1 last:border-0">
                    <p className="px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-graphite-400">{label}</p>
                    {items.map((r) => (
                        <Link key={`${key}-${r.id}`} href={r.url} className="flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-graphite-50" onClick={dismiss}>
                            <Icon className="h-3.5 w-3.5 shrink-0 text-graphite-400" />
                            <span className="min-w-0 flex-1 truncate text-graphite-700">{r.title}</span>
                            {r.subtitle && <span className="shrink-0 text-xs text-graphite-400">{r.subtitle}</span>}
                        </Link>
                    ))}
                </div>
            );
        })
    );

    // One field, rendered in two places. A plain function returning JSX
    // rather than a nested component -- a component declared inside a
    // render is a new type every pass, which remounts the input and drops
    // what somebody was typing.
    function field(ref) {
        return (
            <div className="relative">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-steel-300" aria-hidden="true" />
                <input
                    ref={ref}
                    aria-label="Search IOMS"
                    value={query}
                    onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={(e) => { if (e.key === 'Escape') dismiss(); }}
                    placeholder="Search employees, projects..."
                    // v2.67.0: was a hard `w-[380px]`. See this file's header.
                    // The Ctrl K hint and the padding that reserves room for
                    // it now appear only at xl, where the field is wide
                    // enough to spare 40px -- and where a keyboard is a safe
                    // assumption in the first place.
                    className="h-[34px] w-full rounded-md border border-white/15 bg-white/[0.07] pl-8 pr-3 text-xs text-white outline-none transition-colors placeholder:text-navy-300 focus:border-brand-400/60 focus:bg-white/[0.12] xl:pr-10 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-200 dark:focus:bg-slate-800"
                />
                <kbd className="pointer-events-none absolute right-2 top-1/2 hidden -translate-y-1/2 rounded border border-white/15 bg-white/[0.08] px-1 text-[10px] text-navy-300 xl:block dark:border-slate-600 dark:bg-slate-900">
                    Ctrl K
                </kbd>
            </div>
        );
    }

    return (
        // `contents` so both presentations below are direct flex children
        // of the header, and each can size itself. The wrapper still exists
        // in the DOM, which is all the click-outside check needs.
        <div ref={containerRef} className="contents">
            {/* COMPACT (sm -> lg): an icon that opens the field. Below lg the
                header is carrying the hamburger, Calendar, the department
                selector and four trailing controls, and there is no width
                left for a search box that is still usable once it has
                shrunk. An icon costs 34px and opens something full-size. */}
            <div className="relative hidden shrink-0 sm:block lg:hidden">
                <button
                    type="button"
                    onClick={() => { const next = !expanded; setExpanded(next); if (next) requestAnimationFrame(() => compactRef.current?.focus()); }}
                    aria-label="Search IOMS"
                    aria-expanded={expanded}
                    className="rounded-md p-2 text-navy-300 outline-none transition-colors hover:bg-white/[0.08] hover:text-white"
                >
                    <Search className="h-[18px] w-[18px]" />
                </button>

                {expanded && (
                    <div className="absolute right-0 top-full z-[120] mt-1 w-[min(320px,calc(100vw-1.5rem))] rounded-lg border border-navy-700 bg-navy-900 p-2 shadow-card-hover dark:border-slate-700 dark:bg-slate-900">
                        {field(compactRef)}
                        {showResults && (
                            <div className="mt-2 max-h-[50vh] overflow-y-auto rounded-md border border-graphite-200 bg-white">
                                {resultsList}
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* INLINE (lg+): the familiar field. `w-[380px]` is now a BASIS
                it may shrink from, not a floor it cannot -- every other
                header control is shrink-0, so this is the one item that
                gives way when the viewport is narrow, which is what keeps
                the header inside the page between lg and xl. */}
            <div className="relative hidden w-[380px] min-w-0 lg:block">
                {field(inlineRef)}

                {showResults && (
                    <div className="absolute left-0 top-full z-[120] mt-1 max-h-[60vh] w-72 overflow-y-auto rounded-lg border border-graphite-200 bg-white shadow-card-hover">
                        {resultsList}
                    </div>
                )}
            </div>
        </div>
    );
}
