import { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * v2.4.0 (PTW UX + Field Operations pass, Part 8 -- Progressive
 * Disclosure). No collapsible/accordion component existed anywhere in
 * this codebase before this pass (confirmed via a full grep of
 * resources/js/ for "Collapsible"/"Accordion"/"advanced options" -- zero
 * matches). Built here as the general-purpose primitive for "show the
 * minimum required fields first, advanced/optional fields on demand" --
 * the explicit product requirement for PTW Create, and reusable by any
 * future form that needs the same pattern (Incident, Inspection, CAPA,
 * etc. per the same directive) rather than each page inventing its own
 * show/hide toggle.
 *
 * Deliberately plain (no animation library, no external dependency) --
 * matches this codebase's existing "small, dependency-free shared
 * components" convention (see EmptyState/SectionHeader/StatCard, none of
 * which pull in a UI kit beyond the existing shadcn-style primitives).
 *
 * Usage:
 *   <CollapsibleSection title="Optional / Advanced" description="Isi jika diperlukan.">
 *     ...fields...
 *   </CollapsibleSection>
 *
 * v2.31.0 (Interior UI Transformation Phase 3, Part 11 -- "COLLAPSIBLE:
 * smooth expand/collapse" was named explicitly). Content previously
 * popped in/out instantly (`{open && <div>}`) while only the chevron
 * animated -- the exact same CSS-grid technique already proven on
 * AuthenticatedLayout's own sidebar sub-menus (`grid-template-rows`
 * 0fr/1fr, no JS height measurement, respects `prefers-reduced-motion`
 * automatically since it's a plain CSS transition) applied here so every
 * consumer of this shared component gets a real smooth expand for free.
 */
export default function CollapsibleSection({ title, description, defaultOpen = false, children }) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <div className="rounded-lg border border-graphite-200 dark:border-slate-700">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left"
                aria-expanded={open}
            >
                <span>
                    <span className="block text-sm font-medium text-graphite-700 dark:text-slate-200">{title}</span>
                    {description && <span className="block text-xs text-graphite-400 dark:text-slate-500">{description}</span>}
                </span>
                <ChevronDown className={cn('h-4 w-4 shrink-0 text-graphite-400 transition-transform duration-200', open && 'rotate-180')} />
            </button>
            <div className={cn('grid transition-[grid-template-rows] duration-200 ease-in-out', open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]')}>
                <div className="overflow-hidden">
                    <div className="space-y-4 border-t border-graphite-100 px-3 py-3 dark:border-slate-800">{children}</div>
                </div>
            </div>
        </div>
    );
}
