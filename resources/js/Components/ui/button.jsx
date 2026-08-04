import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const buttonVariants = cva(
    'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg text-[13px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:shrink-0',
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
                // v1.6.7 Beta UI compaction (first pass): h-9 -> h-8 etc.
                // as the mobile/tablet-safe base. This session's density
                // pass adds `lg:` overrides on TOP of that base for
                // desktop specifically -- touch targets on mobile/tablet
                // stay exactly as they were; only pointer/desktop screens
                // get the further reduction, per the explicit "do not
                // negatively impact tablet or mobile" requirement.
                default: 'h-8 px-3.5 py-1.5 lg:h-7',
                sm: 'h-7 rounded-md px-2.5 text-xs lg:h-6',
                lg: 'h-9 rounded-md px-6 lg:h-8',
                icon: 'h-8 w-8 lg:h-7 lg:w-7',
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
