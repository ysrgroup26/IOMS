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
const buttonVariants = cva(
    'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground shadow-sm hover:bg-brand-700',
                destructive: 'bg-destructive text-destructive-foreground shadow-sm hover:bg-red-700',
                outline: 'border border-input bg-background shadow-sm hover:bg-graphite-50 hover:text-graphite-900 dark:hover:bg-slate-800 dark:hover:text-slate-100',
                secondary: 'bg-secondary text-secondary-foreground hover:bg-graphite-200 dark:hover:bg-slate-700',
                ghost: 'hover:bg-graphite-100 hover:text-graphite-900 dark:hover:bg-slate-800 dark:hover:text-slate-100',
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
