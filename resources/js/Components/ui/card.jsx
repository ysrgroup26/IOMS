import * as React from 'react';
import { cn } from '@/lib/utils';

// v1.11.14: radius one step down, rounded-xl (12px) -> rounded-[10px] --
// the directive's own repeated exact "radius: 10px" for cards/KPI boxes.
// v2.44.0: an OPTIONAL `tone`. Passing nothing renders exactly the card
// every existing caller already gets -- this only gives a page a way to
// mark a panel as belonging to a domain or carrying a semantic, without
// each page inventing its own gradient. Deliberately a short, closed set:
// an open colour prop is how design systems rot.
const CARD_TONES = {
    brand: 'border-brand-200/60 bg-gradient-to-br from-brand-50/70 via-white to-white dark:border-brand-900/40 dark:from-brand-950/20 dark:via-slate-900 dark:to-slate-900',
    steel: 'border-steel-200/70 bg-gradient-to-br from-steel-50 via-white to-white dark:border-slate-800 dark:from-slate-900 dark:via-slate-900 dark:to-slate-900',
    navy: 'border-navy-800 bg-gradient-to-br from-navy-900 via-navy-900 to-navy-800 text-white shadow-panel dark:border-slate-800',
    success: 'border-success/25 bg-gradient-to-br from-success/[0.07] via-white to-white dark:border-emerald-900/40 dark:from-emerald-950/20 dark:via-slate-900 dark:to-slate-900',
    warning: 'border-warning/25 bg-gradient-to-br from-warning/[0.08] via-white to-white dark:border-amber-900/40 dark:from-amber-950/20 dark:via-slate-900 dark:to-slate-900',
    danger: 'border-danger/25 bg-gradient-to-br from-danger/[0.07] via-white to-white dark:border-red-900/40 dark:from-red-950/20 dark:via-slate-900 dark:to-slate-900',
};

const Card = React.forwardRef(({ className, tone, ...props }, ref) => (
    // v2.43.0: border moved from neutral graphite to the cool steel family
    // so cards belong to the same palette as the ground they now sit on,
    // and the card shadow token was nudged (see tailwind.config.js) so the
    // surface reads as raised rather than drawn. Radius, padding and every
    // sub-component are untouched.
    <div ref={ref} className={cn('rounded-[10px] border shadow-card', tone ? CARD_TONES[tone] : 'border-steel-100 bg-white dark:border-slate-800 dark:bg-slate-900', className)} {...props} />
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
    <h3 ref={ref} className={cn('text-[13px] font-semibold text-navy-800 dark:text-slate-100', className)} {...props} />
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
