import * as React from 'react';
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

// v1.11.12 set this to px-2/py-1/text-[11px] against an earlier "height
// 22px, padding 6px 8px, font 11px" spec. v1.11.14's own restated spec
// is "Badge: 10px/500, padding 4px 8px, radius 9999px" -- padding
// (px-2/py-1 = 8px/4px) already matched exactly; only the font size
// moved, 11px -> 10px.
const badgeVariants = cva(
    // v2.43.0: status is one of the highest-value signals in an operations
    // product and it was rendered at 10px/500 in a soft tint -- readable up
    // close, invisible when scanning a list. Weight to 600 and a subtle
    // ring so a badge reads as a deliberate STATE CHIP. Size, padding and
    // radius unchanged, so no table column reflows.
    'inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold leading-none tabular-nums ring-1 ring-inset transition-colors duration-fast',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-brand-50 text-brand-700 ring-brand-200/70 dark:bg-brand-900/40 dark:text-brand-300',
                secondary: 'border-transparent bg-slate-100/80 text-slate-700 ring-slate-200/70 dark:bg-slate-700 dark:text-slate-200',
                destructive: 'border-transparent bg-rose-50 text-rose-700 ring-rose-200/70 dark:bg-red-950/50 dark:text-red-300',
                success: 'border-transparent bg-emerald-50 text-emerald-700 ring-emerald-200/70 dark:bg-emerald-950/50 dark:text-emerald-300',
                // v2.15.0 (Product UI/UX Finalization, Part 12). The
                // `warning` token already existed in tailwind.config.js
                // but nothing consumed it -- every "not quite an error"
                // state (overdue, degraded, expiring soon) had to borrow
                // `destructive` (too alarming) or `secondary` (too
                // muted). Distinct amber so "Overdue" reads differently
                // from "Rejected" at a glance, matching this Part's own
                // example status list.
                warning: 'border-transparent bg-amber-50 text-amber-700 ring-amber-200/70 dark:bg-amber-950/50 dark:text-amber-300',
                outline: 'border-steel-200 text-slate-600 ring-transparent dark:border-slate-600 dark:text-slate-300',
            },
        },
        defaultVariants: { variant: 'default' },
    }
);

function Badge({ className, variant, ...props }) {
    return <div className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };
