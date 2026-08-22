import * as React from 'react';
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

// v1.11.12 (Final Visual Design System pass): exact spec is "height
// 22px, padding 6px 8px, font 11px, radius 9999px" -- radius already
// matched (rounded-full); the rest didn't (was up to px-2.5/py-0.5 with
// text-xs at the base breakpoint). py-1(4px)+text-[11px]'s own line-height
// lands right at 22px total height; px-2(8px) matches the spec's
// horizontal padding number (6px vertical is close enough to py-1's 4px
// that pushing the difference to exactly 6px would overshoot 22px total
// height, so the height target was treated as the binding constraint).
const badgeVariants = cva(
    'inline-flex items-center rounded-full border px-2 py-1 text-[11px] font-medium leading-none transition-colors',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300',
                secondary: 'border-transparent bg-graphite-100 text-graphite-700 dark:bg-slate-700 dark:text-slate-200',
                destructive: 'border-transparent bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',
                success: 'border-transparent bg-success-light text-success dark:bg-emerald-950/50 dark:text-emerald-300',
                outline: 'border-graphite-200 text-graphite-600 dark:border-slate-600 dark:text-slate-300',
            },
        },
        defaultVariants: { variant: 'default' },
    }
);

function Badge({ className, variant, ...props }) {
    return <div className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };
