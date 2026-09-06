import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

// v1.11.12 (Final Visual Design System pass): default button height was
// h-8(32px) shrinking to lg:h-7(28px) on desktop -- this pass's own exact
// spec wants "Button height: 34-36px", the OPPOSITE direction from that
// desktop-only shrink (a comfortable click target, not a density
// target -- density comes from KPI/table/typography sizing, not making
// buttons smaller). Base+desktop both set to h-9 (36px) for `default`;
// text dropped from text-[13px] to text-xs (12px/500), matching the
// spec's "Button: 12px" exactly. `sm`/`icon` left as their own smaller,
// deliberately-secondary scale -- the spec's number is for the everyday
// primary/secondary button, not every size variant.
// v2.31.0 (Interior UI Transformation Phase 3, Part 11 -- "BUTTON: press
// feedback" named explicitly): a tiny `active:scale-[0.98]` on every
// button in the app, motion-safe-guarded so a `prefers-reduced-motion`
// user gets none of it -- the smallest possible real interaction fix,
// deliberately not a new animation dependency.
const buttonVariants = cva(
    'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg text-xs font-medium transition-colors motion-safe:active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                // v2.45.0: a primary action should read as the most SOLID thing
                // on the page, not the brightest. The fill is the re-cut IOMS
                // blue, and the weight now comes from a navy-tinted shadow and a
                // hairline inner highlight along the top edge -- the same "lit
                // surface" logic used on panels -- rather than from saturation.
                // Deliberately NOT navy: a navy button on a navy-anchored shell
                // loses its affordance, and the action hierarchy matters more
                // than tonal uniformity.
                default: 'bg-primary text-primary-foreground shadow-[0_1px_2px_0_rgba(15,39,71,0.20),inset_0_1px_0_0_rgba(255,255,255,0.14)] hover:bg-brand-700 hover:shadow-[0_2px_6px_-1px_rgba(15,39,71,0.28),inset_0_1px_0_0_rgba(255,255,255,0.14)]',
                destructive: 'bg-destructive text-destructive-foreground shadow-sm hover:bg-red-700',
                // Secondary actions live in the same cool family as the
                // surfaces, so an outline button beside a primary reads as a
                // quieter sibling rather than a different design system.
                outline: 'border border-steel-200 bg-white text-navy-700 shadow-[0_1px_2px_0_rgba(15,39,71,0.05)] hover:border-steel-300 hover:bg-steel-50 hover:text-navy-900 dark:border-slate-700 dark:bg-transparent dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-slate-100',
                secondary: 'bg-steel-100 text-navy-800 hover:bg-steel-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700',
                ghost: 'text-navy-600 hover:bg-steel-50 hover:text-navy-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-slate-100',
                link: 'text-primary underline-offset-4 hover:underline',
            },
            size: {
                default: 'h-9 px-3',
                sm: 'h-7 rounded-md px-2.5 text-xs lg:h-6',
                lg: 'h-9 rounded-md px-6',
                icon: 'h-9 w-9 lg:h-8 lg:w-8',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    }
);

const Button = React.forwardRef(({ className, variant, size, asChild = false, ...props }, ref) => {
    const Comp = asChild ? Slot : 'button';
    return (
        <Comp className={cn(buttonVariants({ variant, size, className }))} ref={ref} {...props} />
    );
});
Button.displayName = 'Button';

export { Button, buttonVariants };
