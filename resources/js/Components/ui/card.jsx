import * as React from 'react';
import { cn } from '@/lib/utils';

// v1.11.14: radius one step down, rounded-xl (12px) -> rounded-[10px] --
// the directive's own repeated exact "radius: 10px" for cards/KPI boxes.
const Card = React.forwardRef(({ className, ...props }, ref) => (
    <div ref={ref} className={cn('rounded-[10px] border border-graphite-200 bg-white shadow-card dark:border-slate-800 dark:bg-slate-900', className)} {...props} />
));
Card.displayName = 'Card';

// v1.11.11 (Final Visual Redesign -- reference-image pass): padding
// tightened one more notch (p-4/p-3.5 -> p-3.5/p-3) for the reference's
// more compact card proportions -- still comfortably readable, just
// less empty margin around section headers.
const CardHeader = React.forwardRef(({ className, ...props }, ref) => (
    <div ref={ref} className={cn('flex flex-col space-y-1 p-3.5 lg:space-y-1 lg:p-3', className)} {...props} />
));
CardHeader.displayName = 'CardHeader';

// v1.11.9 fixed a backwards mobile-first shrink (13px->12px at desktop).
// v1.11.14 maps this component to the directive's own "Card Title: 13px/
// 600" line exactly (distinct from "Section Title: 14px/600" -- this
// component is the per-Card header, not a page-level section divider).
const CardTitle = React.forwardRef(({ className, ...props }, ref) => (
    <h3 ref={ref} className={cn('text-[13px] font-semibold text-graphite-800 dark:text-slate-100', className)} {...props} />
));
CardTitle.displayName = 'CardTitle';

// Same backwards mobile-first shrink as CardTitle above (text-xs -> 11px
// at desktop) -- fixed the same way, one non-shrinking 12px.
const CardDescription = React.forwardRef(({ className, ...props }, ref) => (
    <p ref={ref} className={cn('text-xs text-graphite-500 dark:text-slate-400', className)} {...props} />
));
CardDescription.displayName = 'CardDescription';

const CardContent = React.forwardRef(({ className, ...props }, ref) => (
    <div ref={ref} className={cn('p-3.5 pt-0 lg:p-3 lg:pt-1', className)} {...props} />
));
CardContent.displayName = 'CardContent';

const CardFooter = React.forwardRef(({ className, ...props }, ref) => (
    <div ref={ref} className={cn('flex items-center p-3.5 pt-0 lg:p-3 lg:pt-2', className)} {...props} />
));
CardFooter.displayName = 'CardFooter';

export { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter };
