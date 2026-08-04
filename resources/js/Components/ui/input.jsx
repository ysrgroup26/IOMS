import * as React from 'react';
import { cn } from '@/lib/utils';

const Input = React.forwardRef(({ className, type = 'text', ...props }, ref) => (
    <input
        type={type}
        ref={ref}
        className={cn(
            'flex h-8 w-full rounded-lg border border-input bg-white px-2.5 py-1 text-[13px] text-graphite-900 shadow-sm transition-colors dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 lg:h-7 lg:text-xs',
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
