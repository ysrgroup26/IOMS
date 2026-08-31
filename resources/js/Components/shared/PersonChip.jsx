import { cn } from '@/lib/utils';

/**
 * v2.20.0 (PTW Experience & Visual Polish pass). A single reusable
 * read-only "person" presentation -- initials avatar + name + optional
 * department -- for anywhere IOMS shows a real Employee/User reference
 * (PTW's PIC/Workforce today; any future module referencing People data
 * should reuse this rather than a fourth copy of the same
 * initials-circle markup). Deliberately read-only/no interaction (no
 * remove button, no click handler) -- `PermitsToWork/Form.jsx`'s own
 * REMOVABLE workforce chips (add/remove personnel while building a PTW)
 * are a different, already-correct pattern and are NOT replaced by this
 * component; this one is for DISPLAYING an already-saved PTW's People
 * data on Document/Show, where there is nothing to remove.
 *
 * No new dependency, no avatar image -- IOMS's Employee data has no
 * photo field wired into this flow, so initials-on-a-tinted-circle is
 * the honest default (never a fabricated photo), matching the same
 * "real data only" rule the rest of this module follows.
 */
export default function PersonChip({ name, subtitle, size = 'md', className }) {
    const initials = getInitials(name);
    const dims = size === 'sm' ? 'h-7 w-7 text-[10px]' : 'h-9 w-9 text-xs';

    return (
        <div className={cn('flex min-w-0 items-center gap-2.5', className)}>
            <span
                className={cn(
                    'flex shrink-0 items-center justify-center rounded-full bg-brand-50 font-semibold text-brand-700 dark:bg-brand-950/40 dark:text-brand-400',
                    dims
                )}
                aria-hidden="true"
            >
                {initials}
            </span>
            <div className="min-w-0">
                <p className="truncate text-sm font-medium text-graphite-900 dark:text-slate-100">{name || '-'}</p>
                {subtitle && <p className="truncate text-xs text-graphite-500 dark:text-slate-400">{subtitle}</p>}
            </div>
        </div>
    );
}

function getInitials(name) {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}
