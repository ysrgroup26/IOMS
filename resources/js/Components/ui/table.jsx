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

const TableHead = React.forwardRef(({ className, ...props }, ref) => (
    <th ref={ref} className={cn('h-9 px-3 text-left align-middle text-xs font-semibold uppercase tracking-wide text-graphite-500 dark:text-slate-400 lg:h-8 lg:px-2.5 lg:text-[11px]', className)} {...props} />
));
TableHead.displayName = 'TableHead';

const TableCell = React.forwardRef(({ className, ...props }, ref) => (
    <td ref={ref} className={cn('px-3 py-2 align-middle text-[13px] text-graphite-700 dark:text-slate-300 lg:px-2.5 lg:py-1.5 lg:text-xs', className)} {...props} />
));
TableCell.displayName = 'TableCell';

export { Table, TableHeader, TableBody, TableRow, TableHead, TableCell };
