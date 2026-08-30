/**
 * Shared Page Header (v1.6.5 foundation, tightened across v1.11.3/9/11,
 * pinned to an exact spec in v1.11.12 -- Final Visual Design System
 * pass). That pass gives literal values rather than a relative range:
 * Page Title 22px/600/#0F172A, Page Subtitle 13px/400/#64748B, header
 * bottom spacing 16px. Tailwind has no built-in step at 22px/13px
 * (`text-xl`=20, `text-2xl`=24, `text-xs`=12, `text-sm`=14) -- arbitrary
 * values used to hit the spec exactly rather than rounding to the
 * nearest built-in step. Weight also taken literally: `font-semibold`
 * (600), not this component's previous `font-bold` (700) -- the spec's
 * own number, not a rounding.
 *
 * Usage:
 *   <PageHeader title="Employees" subtitle="Manage your workforce">
 *       <Button>Add Employee</Button>
 *   </PageHeader>
 *
 * v2.15.0 (Product UI/UX Finalization, Part 14B). Previously
 * `flex-wrap items-center justify-between` at every breakpoint -- title
 * and action buttons sat side-by-side even on a narrow phone, so
 * `flex-wrap` only kicked in once they literally didn't fit on one line,
 * producing an accidental, not intentional, second line rather than a
 * deliberate stacked layout. Below `sm` now stacks title-then-actions
 * (`flex-col items-start`, actions block full-width so their own buttons
 * can wrap/grow sensibly) and reverts to the original side-by-side row
 * at `sm:` and up -- this is a shared component, so every page using
 * `PageHeader` gets this fix at once, matching Part 19's "fix shared
 * components first."
 */
export default function PageHeader({ title, subtitle, children }) {
    return (
        <div className="mb-4 flex flex-col items-start gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <div className="min-w-0">
                <h1 className="text-[22px] font-semibold leading-tight tracking-tight text-graphite-900 dark:text-slate-50">{title}</h1>
                {subtitle && <p className="mt-0.5 text-[13px] leading-snug text-graphite-500 dark:text-slate-400">{subtitle}</p>}
            </div>
            {children && <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">{children}</div>}
        </div>
    );
}
