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
/**
 * ---------------------------------------------------------------------
 * v2.51.0 -- BROUGHT INTO THE DASHBOARD'S BLUE/NAVY HIERARCHY.
 *
 * The panel was still reading as a pale white strip: its wash sat at
 * steel-50 on one corner, which on white is below the threshold where the
 * eye registers a tint at all, and its navy rule was hidden below `sm`.
 * Beside the Dashboard's navy command surface the module header looked
 * like a different product.
 *
 * Three changes, none of which turn it into a second hero:
 *   - the wash now runs steel-100 -> white across the panel, so the
 *     surface reads as cool and lit rather than flat;
 *   - the identity rule is a real navy -> IOMS-blue gradient and is
 *     visible at every breakpoint;
 *   - an optional `icon` renders the SAME chip the rest of the product
 *     uses -- strong filled navy-to-brand gradient, white glyph, soft
 *     shadow -- so a page title carries the established iconography
 *     instead of inventing a header-only treatment.
 *
 * The ladder is intact: Dashboard hero (navy ground, white text) >
 * Overview band (tinted) > module header (light panel, navy ink). This is
 * still tier three; it just no longer looks like tier zero.
 */
/**
 * ---------------------------------------------------------------------
 * v2.71.0 -- WHAT KIND OF PAGE IS THIS?
 *
 * Reported directly: "I have difficulty understanding which screens are
 * intended to manage/edit master data and which are intended to record
 * operational information." Nothing in the product answered that. A
 * configuration screen and a daily-work screen were the same white panel
 * with the same title and the same blue Save button, so the only way to
 * tell was to already know.
 *
 * FOUR KINDS, MATCHING WHAT THE PRODUCT ACTUALLY CONTAINS:
 *
 *   master          definitions the system is configured with -- equipment
 *                   types, hazard categories, checklist templates. Editing
 *                   one changes what everyone else can choose from later.
 *   operational     the records of daily work -- an incident, a permit, a
 *                   material request. The default.
 *   monitoring      reading, not writing -- dashboards, registers, reports.
 *   administration  the system itself -- users, roles, settings.
 *
 * ONLY THREE OF THEM ARE LABELLED. `operational` renders no chip at all,
 * and that is the point: it is the overwhelming majority of the product
 * and the state a user is right to assume. Badging every page would make
 * the badge furniture and stop it carrying information -- the same reason
 * the sidebar does not mark every row. The absence of a chip means "this
 * is ordinary work", and a page that creates records says so far more
 * loudly through its own primary action ("New Material Request") than a
 * label ever would.
 *
 * WHY NOT RED, which was floated as an option. Red already means
 * destructive-or-wrong everywhere else in IOMS -- `text-danger`, a
 * rejected status, a validation error, the Delete button. Spending it on
 * "this is configuration" would teach two meanings for one colour and
 * make a genuine error harder to notice. Master data is not dangerous; it
 * is consequential, which is a different thing and reads better as a
 * calm, deliberately unexciting slate chip than as an alarm.
 *
 * The chip is a LABEL (English, per the language hierarchy). The sentence
 * explaining consequence belongs in `subtitle`, which is prose
 * (Indonesian).
 */
const KIND_CHIP = {
    master: {
        label: 'Master Data',
        className: 'border-steel-200 bg-steel-50 text-navy-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
    },
    monitoring: {
        label: 'Monitoring',
        className: 'border-brand-200 bg-brand-50 text-brand-700 dark:border-brand-900 dark:bg-brand-950/40 dark:text-brand-300',
    },
    administration: {
        label: 'Administration',
        className: 'border-graphite-300 bg-graphite-100 text-graphite-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
    },
};

export default function PageHeader({ title, subtitle, icon: Icon, kind, children }) {
    const chip = KIND_CHIP[kind] ?? null;
    return (
        // v2.44.0: the panel gained a soft steel wash and a hairline top
        // highlight. Flat white with a border read as an outline drawn on the
        // page; a wash plus a lit top edge reads as a physical surface catching
        // light, which is what separates this from a generic admin header at a
        // glance. Still overwhelmingly light -- this is tier three of the
        // hierarchy, not a second hero.
        <div className="relative mb-4 flex flex-col items-start gap-3 overflow-hidden rounded-xl border border-steel-200/70 bg-gradient-to-br from-steel-100/80 via-white to-white px-4 py-3.5 shadow-panel before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-white/90 before:to-transparent sm:flex-row sm:flex-wrap sm:items-center sm:justify-between dark:border-slate-800 dark:from-slate-900 dark:via-slate-900 dark:to-slate-900">
            <div className="flex min-w-0 items-start gap-3">
                {/* Vertical navy rule: the smallest possible mark that ties a
                    module page back to the same brand ink as the rail and the
                    Dashboard, without tinting the whole panel. */}
                {Icon ? (
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] bg-gradient-to-br from-navy-800 to-brand-600 text-white shadow-[0_3px_8px_-3px_rgba(33,102,196,0.38)] dark:from-brand-950 dark:to-brand-900 dark:text-brand-300">
                        <Icon className="h-[18px] w-[18px]" />
                    </span>
                ) : (
                    <span className="mt-1 h-7 w-[3px] shrink-0 rounded-full bg-gradient-to-b from-navy-800 to-brand-500" aria-hidden="true" />
                )}
                <div className="min-w-0">
                    {/* flex so a title that carries an inline StatusBadge lines
                        up with its text instead of the badge sitting on the
                        baseline. A plain string title is unaffected. */}
                    <h1 className="flex flex-wrap items-center gap-2 text-[22px] font-semibold leading-tight tracking-tight text-navy-900 dark:text-slate-50">
                        {title}
                        {/* Beside the title, not above it: the kind qualifies
                            this page, and a chip on its own line reads as an
                            unrelated status. */}
                        {chip && (
                            <span className={`rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${chip.className}`}>
                                {chip.label}
                            </span>
                        )}
                    </h1>
                    {subtitle && <p className="mt-0.5 text-[13px] leading-snug text-graphite-500 dark:text-slate-400">{subtitle}</p>}
                </div>
            </div>
            {children && <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">{children}</div>}
        </div>
    );
}
