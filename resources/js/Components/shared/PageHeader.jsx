/**
 * Shared Page Header (v1.6.5 foundation, tightened across v1.11.3/9/11,
 * pinned to an exact spec in v1.11.12 -- Final Visual Design System pass).
 * That pass gives literal values: Page Title 22px/600, Page Subtitle
 * 13px/400, header bottom spacing 16px. Tailwind has no built-in step at
 * 22px/13px, so arbitrary values hit the spec exactly rather than rounding.
 *
 * v2.15.0: below `sm` this stacks title-then-actions rather than relying on
 * `flex-wrap` to produce an accidental second line; side-by-side returns at
 * `sm:` and up. Shared component, so every page gets that at once.
 *
 * ---------------------------------------------------------------------
 * v2.43.0 -- THE MODULE-PAGE SURFACE.
 *
 * This is the top of 35 module pages and it was a bare `<h1>` on the page
 * background: no surface, no anchor, nothing that said "a product designed
 * this". The Dashboard and the department Overviews had both been given
 * real header surfaces in earlier passes, which left every ordinary module
 * page visibly older than the shell around it.
 *
 * It now renders a genuine header panel -- but deliberately the THIRD tier
 * of the surface hierarchy, not a copy of the Dashboard's:
 *
 *   Dashboard hero      deep navy -> IOMS blue gradient, white text
 *                       (the company-wide command surface; unchanged)
 *   Overview band       soft steel/blue tint (a domain surface)
 *   Module page header  white panel + navy accent rule  <-- this
 *
 * Making this navy too would have flattened that ladder and turned the
 * product blue everywhere, which is exactly what was ruled out. Instead it
 * earns weight from being a real raised surface with a navy identity mark
 * on the cool ground, and the title moves to navy-900 so page titles across
 * the app share the brand's ink rather than generic near-black.
 *
 * The contract is unchanged -- `title`, `subtitle`, `children` -- so all 35
 * callers upgrade without edits.
 */
export default function PageHeader({ title, subtitle, children }) {
    return (
        // v2.44.0: the panel gained a soft steel wash and a hairline top
        // highlight. Flat white with a border read as an outline drawn on the
        // page; a wash plus a lit top edge reads as a physical surface catching
        // light, which is what separates this from a generic admin header at a
        // glance. Still overwhelmingly light -- this is tier three of the
        // hierarchy, not a second hero.
        <div className="relative mb-4 flex flex-col items-start gap-3 overflow-hidden rounded-xl border border-steel-200/70 bg-gradient-to-br from-white via-white to-steel-50 px-4 py-3.5 shadow-panel before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-white/90 before:to-transparent sm:flex-row sm:flex-wrap sm:items-center sm:justify-between dark:border-slate-800 dark:from-slate-900 dark:via-slate-900 dark:to-slate-900">
            <div className="flex min-w-0 items-start gap-3">
                {/* Vertical navy rule: the smallest possible mark that ties a
                    module page back to the same brand ink as the rail and the
                    Dashboard, without tinting the whole panel. */}
                <span className="mt-0.5 hidden h-8 w-1 shrink-0 rounded-full bg-gradient-to-b from-navy-800 to-brand-600 sm:block" aria-hidden="true" />
                <div className="min-w-0">
                    <h1 className="text-[22px] font-semibold leading-tight tracking-tight text-navy-900 dark:text-slate-50">{title}</h1>
                    {subtitle && <p className="mt-0.5 text-[13px] leading-snug text-graphite-500 dark:text-slate-400">{subtitle}</p>}
                </div>
            </div>
            {children && <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">{children}</div>}
        </div>
    );
}
