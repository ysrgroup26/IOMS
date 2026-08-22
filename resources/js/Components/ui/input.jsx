import * as React from 'react';
import { cn } from '@/lib/utils';

const Input = React.forwardRef(({ className, type = 'text', ...props }, ref) => (
    <input
        type={type}
        ref={ref}
        // v1.11.12 (Final Visual Design System pass): was h-8(32px)
        // shrinking to lg:h-7(28px) -- under this pass's exact "Filter/
        // Search height: 36px" target, and in the wrong direction (spec
        // wants the SAME height on desktop, not a further desktop-only
        // shrink). h-9(36px) uniform; horizontal padding bumped
        // px-2.5(10px)->px-3(12px) to match the spec's own number exactly.
        className={cn(
            'flex h-9 w-full rounded-lg border border-input bg-white px-3 py-1.5 text-[13px] text-graphite-900 shadow-sm transition-colors dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 lg:text-xs',
            'file:border-0 file:bg-transparent file:text-sm file:font-medium',
            'placeholder:text-graphite-400 dark:placeholder:text-slate-500',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1',
            'disabled:cursor-not-allowed disabled:opacity-50',
            className
        )}
        {...props}
    />
));
Input.displayName = 'Input';

export { Input };
