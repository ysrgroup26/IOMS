import * as React from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

const Dialog = DialogPrimitive.Root;
const DialogTrigger = DialogPrimitive.Trigger;
const DialogPortal = DialogPrimitive.Portal;
const DialogClose = DialogPrimitive.Close;

const DialogOverlay = React.forwardRef(({ className, ...props }, ref) => (
    <DialogPrimitive.Overlay
        ref={ref}
        className={cn(
            'fixed inset-0 z-[100] bg-graphite-900/40 backdrop-blur-[2px]',
            // Root-cause replacement (Final UI Stabilization): the old
            // `animate-fade-in` was a generic, one-shot CSS keyframe with
            // no relationship to Radix's actual open/closed state -- it
            // just always played 0->1 opacity once on mount, with no
            // `animation-fill-mode` and no data-state awareness at all.
            // `tailwindcss-animate` (already an installed plugin, unused
            // until now) is specifically built to drive Radix primitives
            // via the `data-state` attribute Radix itself manages --
            // `data-[state=open]:animate-in` only plays the enter
            // animation when Radix says the dialog IS open, and
            // `data-[state=closed]:animate-out` plays a real exit
            // animation instead of the content just vanishing. This is
            // the standard, battle-tested pattern, not a custom one-off.
            'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
            className
        )}
        {...props}
    />
));
DialogOverlay.displayName = DialogPrimitive.Overlay.displayName;

/**
 * v2.15.0 (Product UI/UX Finalization, Part 14F). Base dialog was
 * previously `w-full max-w-lg` with NO horizontal safety margin and NO
 * `max-h`/`overflow-y-auto` at all -- fine on desktop, but on a narrow or
 * short mobile viewport a tall form dialog had nowhere to go: it could
 * touch the viewport edges with zero margin, and any content past the
 * viewport height was simply unreachable (no scroll mechanism), clipped
 * by the `-translate-y-1/2` centering. Confirmed by audit that only ONE
 * dialog anywhere in the app (Ppe/ReplacementDue.jsx) had opted into
 * `max-h-[85vh] overflow-y-auto` itself -- every other dialog (including
 * ones using `max-w-xl`/`max-w-2xl`) had no such protection.
 *
 * Fixed once, here, for every dialog in the app at once (Part 19: "if a
 * shared component can solve an issue globally, use it") rather than
 * patching each page:
 *   - `w-[calc(100%-2rem)]` guarantees a 1rem margin on each side on any
 *     viewport narrower than the `max-w-*` cap -- `max-w-lg` (or a
 *     page's own wider override via `className`, e.g. `max-w-2xl`) still
 *     governs the upper bound on larger screens exactly as before.
 *   - `max-h-[85vh] overflow-y-auto` makes every dialog's content
 *     scrollable within itself once it exceeds 85% of viewport height,
 *     instead of silently clipping. A page that already sets its own
 *     `max-h-*`/`overflow-y-*` (via `className`, merged through
 *     `cn()`/`tailwind-merge`) still wins -- this is a default, not an
 *     override.
 */
const DialogContent = React.forwardRef(({ className, children, ...props }, ref) => (
    <DialogPortal>
        <DialogOverlay />
        <DialogPrimitive.Content
            ref={ref}
            className={cn(
                'fixed left-1/2 top-1/2 z-[110] grid w-[calc(100%-2rem)] max-w-lg max-h-[85vh] -translate-x-1/2 -translate-y-1/2 gap-4 overflow-y-auto',
                'rounded-xl border border-graphite-200 bg-white p-5 shadow-card-hover dark:border-slate-700 dark:bg-slate-900',
                'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
                className
            )}
            {...props}
        >
            {children}
            <DialogPrimitive.Close className="absolute right-4 top-4 rounded-md p-1 text-graphite-400 hover:bg-graphite-100 hover:text-graphite-600 focus:outline-none dark:text-slate-500 dark:hover:bg-slate-800 dark:hover:text-slate-300">
                <X className="h-4 w-4" />
                <span className="sr-only">Close</span>
            </DialogPrimitive.Close>
        </DialogPrimitive.Content>
    </DialogPortal>
));
DialogContent.displayName = DialogPrimitive.Content.displayName;

const DialogHeader = ({ className, ...props }) => (
    <div className={cn('flex flex-col space-y-1.5 text-left', className)} {...props} />
);

const DialogFooter = ({ className, ...props }) => (
    <div className={cn('flex flex-col-reverse gap-2 sm:flex-row sm:justify-end', className)} {...props} />
);

const DialogTitle = React.forwardRef(({ className, ...props }, ref) => (
    <DialogPrimitive.Title ref={ref} className={cn('text-base font-semibold text-graphite-800 dark:text-slate-100', className)} {...props} />
));
DialogTitle.displayName = DialogPrimitive.Title.displayName;

const DialogDescription = React.forwardRef(({ className, ...props }, ref) => (
    <DialogPrimitive.Description ref={ref} className={cn('text-sm text-graphite-500 dark:text-slate-400', className)} {...props} />
));
DialogDescription.displayName = DialogPrimitive.Description.displayName;

export {
    Dialog, DialogPortal, DialogOverlay, DialogTrigger, DialogClose,
    DialogContent, DialogHeader, DialogFooter, DialogTitle, DialogDescription,
};
