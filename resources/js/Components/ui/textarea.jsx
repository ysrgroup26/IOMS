import * as React from 'react';
import { cn } from '@/lib/utils';

/** v1.10.0 -- filling a real gap: several new forms (Leave, Incident, Goods Receipt) need multi-line text; existing forms had only ever used single-line Input for that. Same visual language as Input. */
const Textarea = React.forwardRef(({ className, ...props }, ref) => (
    <textarea
        ref={ref}
        className={cn(
            'flex w-full rounded-lg border border-input bg-white px-2.5 py-1.5 text-[13px] text-graphite-900 shadow-sm transition-colors dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 lg:text-xs',
            'placeholder:text-graphite-400 dark:placeholder:text-slate-500',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1',
            'disabled:cursor-not-allowed disabled:opacity-50',
            className
        )}
        {...props}
    />
));
Textarea.displayName = 'Textarea';

export { Textarea };
