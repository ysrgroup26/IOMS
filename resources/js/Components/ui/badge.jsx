import * as React from 'react';
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const badgeVariants = cva(
    'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors lg:px-2 lg:py-[1px] lg:text-[11px]',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300',
                secondary: 'border-transparent bg-graphite-100 text-graphite-700 dark:bg-slate-700 dark:text-slate-200',
                destructive: 'border-transparent bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',
                success: 'border-transparent bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
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
