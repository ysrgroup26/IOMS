import * as React from 'react';
import * as DropdownMenuPrimitive from '@radix-ui/react-dropdown-menu';
import { cn } from '@/lib/utils';

const DropdownMenu = DropdownMenuPrimitive.Root;
const DropdownMenuTrigger = DropdownMenuPrimitive.Trigger;
const DropdownMenuGroup = DropdownMenuPrimitive.Group;

const DropdownMenuContent = React.forwardRef(({ className, sideOffset = 6, align = 'end', ...props }, ref) => (
    <DropdownMenuPrimitive.Portal>
        <DropdownMenuPrimitive.Content
            ref={ref}
            sideOffset={sideOffset}
            align={align}
            className={cn(
                'z-[120] min-w-[12rem] overflow-hidden rounded-xl border border-graphite-200 bg-white p-1.5 shadow-card-hover dark:border-slate-700 dark:bg-slate-900',
                'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
                className
            )}
            {...props}
        />
    </DropdownMenuPrimitive.Portal>
));
DropdownMenuContent.displayName = DropdownMenuPrimitive.Content.displayName;

const DropdownMenuItem = React.forwardRef(({ className, ...props }, ref) => (
    <DropdownMenuPrimitive.Item
        ref={ref}
        className={cn(
            'flex cursor-pointer select-none items-center gap-2 rounded-lg px-2.5 py-2 text-[13px] text-graphite-700 outline-none transition-colors dark:text-slate-300 lg:py-1.5 lg:text-xs',
            'focus:bg-graphite-50 focus:text-graphite-900 data-[disabled]:pointer-events-none data-[disabled]:opacity-50 dark:focus:bg-slate-800 dark:focus:text-slate-100',
            className
        )}
        {...props}
    />
));
DropdownMenuItem.displayName = DropdownMenuPrimitive.Item.displayName;

const DropdownMenuLabel = React.forwardRef(({ className, ...props }, ref) => (
    <DropdownMenuPrimitive.Label
        ref={ref}
        className={cn('px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-graphite-400 dark:text-slate-500', className)}
        {...props}
    />
));
DropdownMenuLabel.displayName = DropdownMenuPrimitive.Label.displayName;

const DropdownMenuSeparator = React.forwardRef(({ className, ...props }, ref) => (
    <DropdownMenuPrimitive.Separator ref={ref} className={cn('my-1 h-px bg-graphite-100 dark:bg-slate-700', className)} {...props} />
));
DropdownMenuSeparator.displayName = DropdownMenuPrimitive.Separator.displayName;

export {
    DropdownMenu, DropdownMenuTrigger, DropdownMenuGroup,
    DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator,
};
