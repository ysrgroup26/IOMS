import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cva } from 'class-variance-authority';
import { Loader2 } from 'lucide-react';
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
// v2.135.0 (dashboard redesign): the press was `transition-colors` with an
// `active:scale`, so the colour eased and the scale SNAPPED -- the one
// motion meant as feedback was the one that was not animated. Colour,
// shadow and transform now share one curve (`out-soft`, 150ms). Text is
// 13px: 12px buttons were the most-cited "too small" in the review. A
// `loading` button is disabled while it waits (no double submission),
// says so to assistive tech (`aria-busy`), and keeps its width so the row
// it sits in does not jump.
const buttonVariants = cva(
    'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-full text-[13px] font-semibold transition-[color,background-color,border-color,box-shadow,transform,opacity] duration-fast ease-out-soft motion-safe:active:scale-[0.97] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:shrink-0',
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
                default: 'bg-gradient-to-b from-brand-500 to-brand-600 text-white shadow-[0_6px_16px_-8px_rgba(36,112,221,0.75),inset_0_1px_0_0_rgba(255,255,255,0.22)] hover:from-brand-600 hover:to-brand-700 hover:shadow-[0_10px_22px_-10px_rgba(36,112,221,0.85),inset_0_1px_0_0_rgba(255,255,255,0.22)]',
                destructive: 'bg-gradient-to-b from-rose-500 to-red-600 text-white shadow-[0_6px_16px_-8px_rgba(225,29,72,0.7),inset_0_1px_0_0_rgba(255,255,255,0.2)] hover:from-rose-600 hover:to-red-700',
                // Secondary actions live in the same cool family as the
                // surfaces, so an outline button beside a primary reads as a
                // quieter sibling rather than a different design system.
                outline: 'border border-white/90 bg-white/80 text-navy-800 shadow-[0_1px_2px_0_rgba(30,64,120,0.06)] backdrop-blur hover:border-steel-200 hover:bg-white hover:text-navy-900 dark:border-slate-700 dark:bg-transparent dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-slate-100',
                secondary: 'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-100 hover:bg-brand-100 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700',
                ghost: 'text-navy-700 hover:bg-white/70 hover:text-navy-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-slate-100',
                link: 'text-primary underline-offset-4 hover:underline',
            },
            size: {
                default: 'h-10 px-4',
                sm: 'h-8 px-3 text-xs',
                lg: 'h-11 px-6',
                icon: 'h-9 w-9',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    }
);

const Button = React.forwardRef(({ className, variant, size, asChild = false, loading = false, disabled, children, ...props }, ref) => {
    const Comp = asChild ? Slot : 'button';
    // A Slot renders exactly one child, so the spinner is only added to a
    // real <button>; an asChild link has nothing to submit twice anyway.
    const busy = loading && !asChild;
    return (
        <Comp
            className={cn(buttonVariants({ variant, size, className }))}
            ref={ref}
            disabled={asChild ? disabled : disabled || busy}
            aria-busy={busy || undefined}
            {...props}
        >
            {/* A Slot accepts exactly ONE child: `{false}{children}` is two and
                breaks every asChild button, so the child passes through alone. */}
            {asChild ? children : <>{busy && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}{children}</>}
        </Comp>
    );
});
Button.displayName = 'Button';

export { Button, buttonVariants };
