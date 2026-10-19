import * as React from 'react';
import { cn } from '@/lib/utils';

/**
 * v1.10.0 -- filling a real gap: several new forms (Leave, Incident, Goods
 * Receipt) need multi-line text; existing forms had only ever used
 * single-line Input for that. Same visual language as Input.
 *
 * v2.125.0 -- IT GROWS WITH WHAT IS TYPED INTO IT.
 *
 * `rows={3}` is a fixed window, so a work description longer than three
 * lines -- which on a permit is most of them -- was typed into a box that
 * scrolled internally, hiding what had already been written at exactly
 * the moment the writer wanted to re-read it. On a phone that is the
 * whole field.
 *
 * Done with `field-sizing: content`, which is the browser doing this
 * natively: no resize observer, no shadow element, no scroll-height
 * write-back loop, and nothing to get wrong when the font loads late.
 * Where it is unsupported the element falls back to its `rows` exactly as
 * before, so this cannot make any existing form worse.
 *
 * `min-h` keeps the empty state from collapsing to one line (the box has
 * to LOOK like it takes a paragraph) and `max-h` with scrolling keeps a
 * pasted essay from pushing the submit button off a phone screen -- a
 * field that grows without limit is its own usability problem.
 */
const Textarea = React.forwardRef(({ className, ...props }, ref) => (
    <textarea
        ref={ref}
        className={cn(
            'flex w-full rounded-lg border border-input bg-white px-2.5 py-1.5 text-[13px] text-graphite-900 shadow-sm transition-colors dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 lg:text-xs',
            '[field-sizing:content] min-h-[4.5rem] max-h-[60vh] overflow-y-auto',
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
