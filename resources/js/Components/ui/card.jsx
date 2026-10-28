import * as React from 'react';
import { cn } from '@/lib/utils';

// v1.11.14: radius one step down, rounded-xl (12px) -> rounded-[10px] --
// the directive's own repeated exact "radius: 10px" for cards/KPI boxes.
// v2.44.0: an OPTIONAL `tone`. Passing nothing renders exactly the card
// every existing caller already gets -- this only gives a page a way to
// mark a panel as belonging to a domain or carrying a semantic, without
// each page inventing its own gradient. Deliberately a short, closed set:
// an open colour prop is how design systems rot.
// v2.136.0 (Icy Frosted, ADR 079): every tone is now a FROSTED surface
// with a faint semantic wash rather than a flat fill. `navy` -- the one
// full-colour tone -- becomes the azure feature panel (.surface-feature);
// its callers keep white ink and need no change.
const CARD_TONES = {
    brand: 'tint-blue',
    steel: 'tint-slate',
    navy: 'surface-feature border-white/20',
    success: 'tint-green',
    warning: 'tint-amber',
    danger: 'tint-rose',
};

const Card = React.forwardRef(({ className, tone, ...props }, ref) => (
    // The bubble card: 20px radius, frosted white over the icy canvas, a lit
    // top edge and a cool halo (see .surface in app.css).
    <div ref={ref} className={cn('surface', tone && CARD_TONES[tone], className)} {...props} />
));
Card.displayName = 'Card';

// v1.11.11 (Final Visual Redesign -- reference-image pass): padding
// tightened one more notch (p-4/p-3.5 -> p-3.5/p-3) for the reference's
// more compact card proportions -- still comfortably readable, just
// less empty margin around section headers.
const CardHeader = React.forwardRef(({ className, ...props }, ref) => (
    <div ref={ref} className={cn('flex flex-col space-y-0.5 p-4 pb-2.5', className)} {...props} />
));
CardHeader.displayName = 'CardHeader';

// v1.11.9 fixed a backwards mobile-first shrink (13px->12px at desktop).
// v1.11.14 maps this component to the directive's own "Card Title: 13px/
// 600" line exactly (distinct from "Section Title: 14px/600" -- this
// component is the per-Card header, not a page-level section divider).
const CardTitle = React.forwardRef(({ className, ...props }, ref) => (
    <h3 ref={ref} className={cn('text-[14px] font-bold tracking-[-0.01em] text-navy-900 dark:text-slate-100', className)} {...props} />
));
CardTitle.displayName = 'CardTitle';

// Same backwards mobile-first shrink as CardTitle above (text-xs -> 11px
// at desktop) -- fixed the same way, one non-shrinking 12px.
const CardDescription = React.forwardRef(({ className, ...props }, ref) => (
    <p ref={ref} className={cn('text-xs leading-relaxed text-slate-500 dark:text-slate-400', className)} {...props} />
));
CardDescription.displayName = 'CardDescription';

const CardContent = React.forwardRef(({ className, ...props }, ref) => (
    <div ref={ref} className={cn('p-4 pt-0', className)} {...props} />
));
CardContent.displayName = 'CardContent';

const CardFooter = React.forwardRef(({ className, ...props }, ref) => (
    <div ref={ref} className={cn('flex items-center p-4 pt-0', className)} {...props} />
));
CardFooter.displayName = 'CardFooter';

export { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter };
