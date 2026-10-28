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
                        <Link key={`${key}-${r.id}`} href={r.url} className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-brand-50/70" onClick={dismiss}>
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
                <Search className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
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
                    className="h-11 w-full rounded-full border border-white/80 bg-white/70 pl-11 pr-4 text-[13px] text-navy-900 shadow-float outline-none backdrop-blur-xl transition-[background-color,box-shadow] duration-fast placeholder:text-slate-400 focus:bg-white focus:ring-4 focus:ring-brand-500/15 xl:pr-16"
                />
                <kbd className="pointer-events-none absolute right-3 top-1/2 hidden -translate-y-1/2 rounded-md border border-steel-100 bg-white/80 px-1.5 py-0.5 font-sans text-[10px] font-semibold text-slate-500 xl:block">
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
                    className="chrome-icon"
                >
                    <Search className="h-[18px] w-[18px]" />
                </button>

                {expanded && (
                    <div className="absolute right-0 top-full z-[120] mt-1 w-[min(320px,calc(100vw-1.5rem))] rounded-2xl border border-white/80 bg-white/95 p-2 shadow-float backdrop-blur-xl">
                        {field(compactRef)}
                        {showResults && (
                            <div className="mt-2 max-h-[50vh] overflow-y-auto rounded-xl border border-steel-100 bg-white">
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
            <div className="relative hidden w-[220px] min-w-0 lg:block 2xl:w-[340px]">
                {field(inlineRef)}

                {showResults && (
                    <div className="absolute left-0 top-full z-[120] mt-2 max-h-[60vh] w-80 overflow-y-auto rounded-2xl border border-white/80 bg-white/95 p-1 shadow-float backdrop-blur-xl">
                        {resultsList}
                    </div>
                )}
            </div>
        </div>
    );
}
