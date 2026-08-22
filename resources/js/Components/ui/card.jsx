import * as React from 'react';
import { cn } from '@/lib/utils';

const Card = React.forwardRef(({ className, ...props }, ref) => (
    <div ref={ref} className={cn('rounded-xl border border-graphite-200 bg-white shadow-card dark:border-slate-800 dark:bg-slate-900', className)} {...props} />
));
Card.displayName = 'Card';

const CardHeader = React.forwardRef(({ className, ...props }, ref) => (
    <div ref={ref} className={cn('flex flex-col space-y-1.5 p-4 lg:space-y-1 lg:p-3.5', className)} {...props} />
));
CardHeader.displayName = 'CardHeader';

// v1.11.9 (Enterprise UI/UX Refinement Part 2 -- typography audit): this
// was `text-[13px] lg:text-xs`, meaning every CardTitle in the app got
// SMALLER (13px -> 12px) at desktop width -- backwards for an
// enterprise app whose stated primary target is desktop/laptop (Part
// 12), and well under this pass's own "section title ~15-17px"
// guideline. Fixed to a single, non-shrinking 14px across breakpoints --
// a deliberately conservative step given how many pages this single
// component touches (used by essentially every Card in the app); still
// short of the 15-17px upper guideline, but a safe, verified-by-build
// improvement rather than a blast-radius risk this pass can't visually
// confirm everywhere. Callers that already locally override with
// `text-sm` (14px, several department dashboards) now match the
// default exactly instead of coincidentally happening to agree with it.
const CardTitle = React.forwardRef(({ className, ...props }, ref) => (
    <h3 ref={ref} className={cn('text-sm font-semibold text-graphite-800 dark:text-slate-100', className)} {...props} />
));
CardTitle.displayName = 'CardTitle';

// Same backwards mobile-first shrink as CardTitle above (text-xs -> 11px
// at desktop) -- fixed the same way, one non-shrinking 12px.
const CardDescription = React.forwardRef(({ className, ...props }, ref) => (
    <p ref={ref} className={cn('text-xs text-graphite-500 dark:text-slate-400', className)} {...props} />
));
CardDescription.displayName = 'CardDescription';

const CardContent = React.forwardRef(({ className, ...props }, ref) => (
    <div ref={ref} className={cn('p-4 pt-0 lg:p-3.5 lg:pt-1', className)} {...props} />
));
CardContent.displayName = 'CardContent';

const CardFooter = React.forwardRef(({ className, ...props }, ref) => (
    <div ref={ref} className={cn('flex items-center p-4 pt-0 lg:p-3.5 lg:pt-2', className)} {...props} />
));
CardFooter.displayName = 'CardFooter';

export { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter };
