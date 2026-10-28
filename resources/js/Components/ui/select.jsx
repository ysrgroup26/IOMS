import * as React from 'react';
import * as SelectPrimitive from '@radix-ui/react-select';
import { Check, ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';

// z-[120] on SelectContent below (v1.6.8) -- root cause of "dropdowns
// appear behind modals": Radix portals both Dialog and Select content to
// document.body as separate elements. Portaling only affects WHERE in
// the DOM something renders, not stacking order -- the browser still
// stacks purely by z-index value regardless of visual nesting. Dialog's
// content uses z-[110] (see ui/dialog.jsx); this was z-50, well below
// that, so any Select opened inside a Dialog rendered behind it. Every
// portal-based popover component in the app (Select, DropdownMenu,
// Combobox, GlobalSearch) now uses this same z-[120] value so none of
// them can ever be hidden by a modal, instead of patching this
// page-by-page.
const Select = SelectPrimitive.Root;
const SelectValue = SelectPrimitive.Value;
const SelectGroup = SelectPrimitive.Group;

const SelectTrigger = React.forwardRef(({ className, children, ...props }, ref) => (
    <SelectPrimitive.Trigger
        ref={ref}
        // v1.11.12: same fix as Input -- h-9(36px) uniform (was h-8
        // shrinking to lg:h-7/28px), px-3(12px) horizontal padding.
        className={cn(
            'flex h-9 w-full items-center justify-between rounded-lg border border-input bg-white px-3 py-1.5 text-[13px] text-graphite-900 shadow-[0_1px_1px_0_rgba(15,39,71,0.04)] outline-none transition-[border-color,box-shadow] duration-fast ease-out-soft hover:border-graphite-300 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20 data-[state=open]:border-ring data-[state=open]:ring-[3px] data-[state=open]:ring-ring/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:border-slate-600',
            'placeholder:text-graphite-400',
            'disabled:cursor-not-allowed disabled:opacity-50',
            className
        )}
        {...props}
    >
        {children}
        <SelectPrimitive.Icon asChild>
            <ChevronDown className="h-4 w-4 opacity-50" />
        </SelectPrimitive.Icon>
    </SelectPrimitive.Trigger>
));
SelectTrigger.displayName = SelectPrimitive.Trigger.displayName;

const SelectContent = React.forwardRef(({ className, children, position = 'popper', ...props }, ref) => (
    <SelectPrimitive.Portal>
        <SelectPrimitive.Content
            ref={ref}
            position={position}
            className={cn(
                'relative z-[120] max-h-96 min-w-[8rem] overflow-hidden rounded-xl border border-graphite-200/80 bg-white shadow-lift duration-base ease-out-soft dark:border-slate-700 dark:bg-slate-900',
                'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
                position === 'popper' && 'translate-y-1',
                className
            )}
            {...props}
        >
            <SelectPrimitive.Viewport className="p-1">{children}</SelectPrimitive.Viewport>
        </SelectPrimitive.Content>
    </SelectPrimitive.Portal>
));
SelectContent.displayName = SelectPrimitive.Content.displayName;

const SelectItem = React.forwardRef(({ className, children, ...props }, ref) => (
    <SelectPrimitive.Item
        ref={ref}
        className={cn(
            'relative flex w-full cursor-pointer select-none items-center rounded-md py-1.5 pl-8 pr-2 text-[13px] text-graphite-800 outline-none transition-colors duration-fast dark:text-slate-200',
            'focus:bg-brand-50 focus:text-brand-700 data-[disabled]:pointer-events-none data-[disabled]:opacity-50 dark:focus:bg-slate-800 dark:focus:text-brand-400',
            className
        )}
        {...props}
    >
        <span className="absolute left-2 flex h-3.5 w-3.5 items-center justify-center">
            <SelectPrimitive.ItemIndicator>
                <Check className="h-4 w-4 text-brand-600 dark:text-brand-400" />
            </SelectPrimitive.ItemIndicator>
        </span>
        <SelectPrimitive.ItemText>{children}</SelectPrimitive.ItemText>
    </SelectPrimitive.Item>
));
SelectItem.displayName = SelectPrimitive.Item.displayName;

const SelectLabel = React.forwardRef(({ className, ...props }, ref) => (
    <SelectPrimitive.Label
        ref={ref}
        className={cn('px-2 py-1.5 text-xs font-semibold uppercase tracking-wide text-graphite-400 dark:text-slate-500', className)}
        {...props}
    />
));
SelectLabel.displayName = SelectPrimitive.Label.displayName;

export { Select, SelectGroup, SelectValue, SelectTrigger, SelectContent, SelectItem, SelectLabel };
