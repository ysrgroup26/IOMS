import * as React from 'react';
import { cn } from '@/lib/utils';

const Table = React.forwardRef(({ className, ...props }, ref) => (
    <div className="relative w-full overflow-auto">
        <table ref={ref} className={cn('w-full caption-bottom text-sm', className)} {...props} />
    </div>
));
Table.displayName = 'Table';

const TableHeader = React.forwardRef(({ className, ...props }, ref) => (
    <thead ref={ref} className={cn('[&_tr]:border-b [&_tr]:border-graphite-200 dark:[&_tr]:border-slate-700', className)} {...props} />
));
TableHeader.displayName = 'TableHeader';

const TableBody = React.forwardRef(({ className, ...props }, ref) => (
    <tbody ref={ref} className={cn('[&_tr:last-child]:border-0', className)} {...props} />
));
TableBody.displayName = 'TableBody';

const TableRow = React.forwardRef(({ className, ...props }, ref) => (
    <tr ref={ref} className={cn('border-b border-graphite-100 transition-colors hover:bg-graphite-50/60 dark:border-slate-800 dark:hover:bg-slate-800/60', className)} {...props} />
));
TableRow.displayName = 'TableRow';

// v1.11.12 (Final Visual Design System pass): header font/color already
// matched the exact spec (11px/600/#64748B at desktop, via the existing
// lg:text-[11px] + font-semibold + text-graphite-500 -- graphite-500 IS
// #64748b) -- unchanged. Row height didn't: this pass's own "Row height
// 40-48px" is taller than the previous compaction pass's padding
// produced (~28-32px at desktop). Cell vertical padding bumped to a
// uniform py-2.5 (20px) -- no further desktop-only shrink for padding
// specifically, since shrinking it further would fall back under the
// spec's own floor -- landing the row in the 40-48px band at both the
// 13px (base) and 12px (desktop) text sizes.
const TableHead = React.forwardRef(({ className, ...props }, ref) => (
    <th ref={ref} className={cn('h-9 px-3 text-left align-middle text-xs font-semibold uppercase tracking-wide text-graphite-500 dark:text-slate-400 lg:h-8 lg:px-2.5 lg:text-[11px]', className)} {...props} />
));
TableHead.displayName = 'TableHead';

const TableCell = React.forwardRef(({ className, ...props }, ref) => (
    <td ref={ref} className={cn('px-3 py-2.5 align-middle text-[13px] text-graphite-700 dark:text-slate-300 lg:px-2.5 lg:text-xs', className)} {...props} />
));
TableCell.displayName = 'TableCell';

export { Table, TableHeader, TableBody, TableRow, TableHead, TableCell };
