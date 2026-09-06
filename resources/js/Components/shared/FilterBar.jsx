/**
 * Shared Filter Bar (v2.47.0).
 *
 * WHY: every list page in IOMS renders a search box plus two or three
 * selects, and they were all doing it the same way -- a bare
 * `flex flex-wrap gap-2` row of white controls floating directly on the
 * page background. That pattern is the clearest remaining marker of the
 * older visual language: the header above it is a surface and the results
 * below it are a surface, but the filters in between are loose controls
 * with nothing holding them, so a list page reads as three unrelated
 * fragments rather than one composed screen.
 *
 * This gives them a real container in the same family as PageHeader and
 * Card -- a light steel-washed panel -- so a list page reads top to bottom
 * as header / filters / results. Deliberately quieter than PageHeader
 * (thinner padding, softer border, no accent rule): filters are a control
 * strip, not a heading.
 *
 * Deliberately layout-only. It takes children and arranges them; it owns no
 * filter state, no query-string handling and no routing, so every page
 * keeps its own existing `applyFilters` logic untouched and adopting this
 * is a pure presentation change.
 *
 * Usage:
 *   <FilterBar>
 *       <FilterBar.Search value={...} onChange={...} placeholder="..." />
 *       <Select .../>
 *   </FilterBar>
 */
import { Search } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';

export default function FilterBar({ children, className }) {
    return (
        <div
            className={cn(
                'mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-steel-200/70 bg-gradient-to-br from-steel-50/80 via-white to-white p-2.5 shadow-card',
                'dark:border-slate-800 dark:from-slate-900 dark:via-slate-900 dark:to-slate-900',
                className
            )}
        >
            {children}
        </div>
    );
}

/**
 * The search field every list page repeats. Grows to fill the row so the
 * selects stay their natural width at the end of the bar, which is the
 * arrangement all of these pages already hand-rolled.
 */
function FilterSearch({ placeholder = 'Search...', defaultValue, value, onChange, className }) {
    return (
        <div className={cn('relative min-w-[200px] flex-1', className)}>
            <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" aria-hidden="true" />
            <Input
                className="border-steel-200 bg-white pl-8 shadow-none focus-visible:ring-1"
                placeholder={placeholder}
                defaultValue={defaultValue}
                value={value}
                onChange={onChange}
            />
        </div>
    );
}

FilterBar.Search = FilterSearch;
