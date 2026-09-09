import { useEffect, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown, Search, X } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';

/**
 * v2.65.0 -- THE ID-BASED SEARCHABLE SELECT THAT DID NOT EXIST.
 *
 * The discovery document said "adopt Combobox where an option list can
 * exceed ~15". Reading Combobox properly while implementing showed that
 * was the wrong recommendation, so it is corrected here rather than
 * followed: `Combobox` operates on an array of STRINGS, allows free text,
 * and returns whatever was typed. It is right for "suggest a department
 * NAME but let me type a new one" (Daily Report). It is wrong for any
 * field backed by a foreign key, because it cannot guarantee the value is
 * a real id -- and `InCurrentTenant` validation, tenant isolation and
 * every report that joins on that FK depend on it being one.
 *
 * `EmployeeSelector` is the id-based answer, but it is employee-specific
 * and asynchronous by design (the directory is too large to preload).
 *
 * The gap between them is the common case: a list already in the page
 * payload -- operating units, departments, positions, vendors, projects,
 * items, asset categories -- that is too long for a usable `<select>` and
 * far too short to justify an endpoint. Twenty-five of twenty-seven forms
 * used a raw `<select>` for exactly this.
 *
 * SO THE THREE SELECTORS NOW DIVIDE CLEANLY, and none of them overlaps:
 *
 *   Select (ui/select)   small fixed enumerations -- status, priority,
 *                        employment type. Under ~15 stable options.
 *   SearchableSelect     id-backed, options already loaded. This one.
 *   EmployeeSelector     id-backed, async, grouped, optionally multiple.
 *   Combobox             free text with suggestions. Not an FK.
 *
 * SEARCH APPEARS ONLY WHEN IT HELPS. Below `searchThreshold` options the
 * filter input is hidden -- a search box over six items is furniture.
 *
 * KEYBOARD: ArrowUp/Down move, Enter selects, Escape closes, Home/End
 * jump. The listbox is `role="listbox"` with `aria-activedescendant`, so
 * a screen reader announces the highlighted option rather than silence.
 */
export default function SearchableSelect({
    value,
    onChange,
    options = [],
    placeholder = 'Select…',
    emptyLabel = 'No matches',
    disabled = false,
    clearable = false,
    searchThreshold = 8,
    id,
    className,
    ...aria
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [highlight, setHighlight] = useState(0);

    const containerRef = useRef(null);
    const searchRef = useRef(null);
    const listRef = useRef(null);

    const selected = options.find((o) => String(o.value) === String(value)) ?? null;
    const showSearch = options.length >= searchThreshold;

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (! q) return options;

        return options.filter((o) =>
            String(o.label).toLowerCase().includes(q)
            || String(o.hint ?? '').toLowerCase().includes(q)
        );
    }, [options, query]);

    useEffect(() => {
        function onPointerDown(event) {
            if (containerRef.current && ! containerRef.current.contains(event.target)) {
                setOpen(false);
            }
        }

        document.addEventListener('mousedown', onPointerDown);

        return () => document.removeEventListener('mousedown', onPointerDown);
    }, []);

    useEffect(() => {
        if (! open) {
            setQuery('');

            return;
        }

        setHighlight(Math.max(0, filtered.findIndex((o) => String(o.value) === String(value))));
        if (showSearch) window.setTimeout(() => searchRef.current?.focus(), 0);
        // `open` is the trigger; re-running on every keystroke would fight
        // the user's own highlight movement.
    }, [open]);

    function commit(option) {
        onChange(option === null ? '' : String(option.value));
        setOpen(false);
    }

    function onKeyDown(event) {
        if (! open) {
            if (['Enter', ' ', 'ArrowDown'].includes(event.key)) {
                event.preventDefault();
                setOpen(true);
            }

            return;
        }

        const last = filtered.length - 1;

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                setHighlight((i) => (i >= last ? 0 : i + 1));
                break;
            case 'ArrowUp':
                event.preventDefault();
                setHighlight((i) => (i <= 0 ? last : i - 1));
                break;
            case 'Home':
                event.preventDefault();
                setHighlight(0);
                break;
            case 'End':
                event.preventDefault();
                setHighlight(last);
                break;
            case 'Enter':
                event.preventDefault();
                if (filtered[highlight]) commit(filtered[highlight]);
                break;
            case 'Escape':
                event.preventDefault();
                setOpen(false);
                break;
            default:
                break;
        }
    }

    // Keep the highlighted row in view during keyboard traversal.
    useEffect(() => {
        if (! open) return;
        listRef.current
            ?.querySelector(`[data-index="${highlight}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    }, [highlight, open]);

    const listboxId = id ? `${id}-listbox` : undefined;

    return (
        <div ref={containerRef} className={cn('relative', className)}>
            <button
                type="button"
                id={id}
                disabled={disabled}
                onClick={() => ! disabled && setOpen((o) => ! o)}
                onKeyDown={onKeyDown}
                role="combobox"
                aria-expanded={open}
                aria-haspopup="listbox"
                aria-controls={open ? listboxId : undefined}
                className={cn(
                    'flex h-9 w-full items-center gap-2 rounded-lg border border-input bg-white px-3 text-left text-[13px] shadow-sm transition-colors lg:text-xs',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1',
                    'disabled:cursor-not-allowed disabled:opacity-50',
                    'dark:border-slate-700 dark:bg-slate-900',
                    selected ? 'text-graphite-900 dark:text-slate-100' : 'text-graphite-400 dark:text-slate-500'
                )}
                {...aria}
            >
                <span className="min-w-0 flex-1 truncate">{selected?.label ?? placeholder}</span>

                {clearable && selected && ! disabled && (
                    <span
                        role="button"
                        tabIndex={-1}
                        aria-label="Clear selection"
                        onClick={(e) => { e.stopPropagation(); commit(null); }}
                        className="shrink-0 rounded p-0.5 text-graphite-400 hover:text-graphite-700"
                    >
                        <X className="h-3.5 w-3.5" />
                    </span>
                )}
                <ChevronDown className="h-3.5 w-3.5 shrink-0 text-graphite-400" aria-hidden="true" />
            </button>

            {open && (
                <div className="absolute z-[120] mt-1 w-full overflow-hidden rounded-lg border border-graphite-200 bg-white shadow-card-hover dark:border-slate-700 dark:bg-slate-900">
                    {showSearch && (
                        <div className="border-b border-graphite-100 p-2 dark:border-slate-800">
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" aria-hidden="true" />
                                <Input
                                    ref={searchRef}
                                    value={query}
                                    onChange={(e) => { setQuery(e.target.value); setHighlight(0); }}
                                    onKeyDown={onKeyDown}
                                    placeholder="Search…"
                                    className="h-8 pl-8"
                                    aria-label="Filter options"
                                />
                            </div>
                        </div>
                    )}

                    <ul
                        ref={listRef}
                        id={listboxId}
                        role="listbox"
                        aria-activedescendant={filtered[highlight] ? `${listboxId}-${highlight}` : undefined}
                        className="max-h-56 overflow-y-auto py-1"
                    >
                        {filtered.length === 0 && (
                            <li className="px-3 py-2 text-xs text-graphite-400">{emptyLabel}</li>
                        )}

                        {filtered.map((option, index) => {
                            const isSelected = String(option.value) === String(value);

                            return (
                                <li key={option.value} data-index={index}>
                                    <button
                                        type="button"
                                        id={`${listboxId}-${index}`}
                                        role="option"
                                        aria-selected={isSelected}
                                        onMouseEnter={() => setHighlight(index)}
                                        onMouseDown={(e) => e.preventDefault()}
                                        onClick={() => commit(option)}
                                        className={cn(
                                            'flex w-full items-center gap-2 px-3 py-2 text-left text-[13px] transition-colors',
                                            index === highlight ? 'bg-graphite-50 dark:bg-slate-800' : 'bg-transparent',
                                            isSelected ? 'font-medium text-navy-900 dark:text-slate-100' : 'text-graphite-700 dark:text-slate-300'
                                        )}
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate">{option.label}</span>
                                            {option.hint && (
                                                <span className="block truncate text-[11px] text-graphite-400">{option.hint}</span>
                                            )}
                                        </span>
                                        {isSelected && <Check className="h-3.5 w-3.5 shrink-0 text-brand-600" aria-hidden="true" />}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}
        </div>
    );
}
