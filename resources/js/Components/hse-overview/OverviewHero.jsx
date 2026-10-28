import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Building2, ArrowUpRight } from 'lucide-react';

/**
 * v2.116.0 -- THE HEADER BAND OF THE HSE OVERVIEW.
 *
 * The approved design opens the page on a dark industrial band rather
 * than on a white card, and the reason is not decoration: the first
 * element on an operations dashboard should say WHERE you are and WHAT
 * this page is scoped to before it starts showing numbers. Navy also
 * separates the page's own identity from the white working surface
 * underneath it, so the KPI row reads as the first content rather than
 * as a continuation of the chrome.
 *
 * THE PHOTOGRAPH IS ONE THE REPOSITORY ALREADY SHIPS.
 * `public/images/website/shipyard-*.webp` is the public site's own
 * shipyard image, already optimised and already served from this origin.
 * No external asset is fetched and nothing new was added: a dashboard
 * that pulls a decorative image off a third-party host is a privacy
 * question and a load-time question for a header nobody looks at twice.
 * It sits under a navy gradient heavy enough that the text contrast does
 * not depend on the photograph, so a failed image degrades to a plain
 * navy band rather than to unreadable white-on-white.
 *
 * ONLY REAL FILTERS APPEAR HERE. The Operating Unit selector narrows
 * every figure on the page through the same tenant-safe helper the main
 * Dashboard uses. The approved reference also sketches period, project
 * and workforce chips; those are NOT rendered, because IOMS does not
 * filter this page by any of them today and a control that changes
 * nothing is worse than an absent one -- it tells the reader their
 * figures are narrowed when they are not.
 *
 * THERE IS NO CLOCK IN THIS BAND, and the approved reference shows one.
 * The reference was drawn against a header that does not carry a date;
 * the real one does, live, on every page. Two clocks on one screen is a
 * defect whichever of them is prettier, and removing the global one to
 * suit a single page would take the date off every other page in the
 * product. So the band's right-hand side carries the page's CONTROLS
 * instead, which is also what balances it at desktop width.
 */
export default function OverviewHero({ companies = [], selectedCompany = 'all', onCompanyChange, fullCalendarHref }) {
    return (
        <section className="surface-feature mb-4">
            {/* The photograph, then the gradient that guarantees contrast
                over it. `aria-hidden` and empty alt: it carries no
                information a screen reader needs. */}
            <img
                src="/images/website/shipyard-1600.webp"
                alt=""
                aria-hidden="true"
                loading="lazy"
                decoding="async"
                className="absolute inset-0 h-full w-full object-cover object-center opacity-30 mix-blend-luminosity"
            />
            <div className="absolute inset-0 bg-gradient-to-r from-[#1A4AA8]/95 via-[#1E5BC6]/85 to-sky-500/45" />

            <div className="relative flex flex-col gap-4 p-5 sm:p-6 lg:flex-row lg:items-end lg:justify-between">
                <div className="min-w-0">
                    <span className="inline-flex items-center rounded-md bg-white/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.12em] text-blue-200 ring-1 ring-inset ring-white/15">
                        HSE
                    </span>

                    <h1 className="type-page-title mt-1.5 text-[22px] leading-tight text-white sm:text-[24px]">
                        HSE Overview
                    </h1>

                    <p className="mt-1.5 max-w-xl text-[13px] leading-snug text-sky-100">
                        Pantau kinerja keselamatan, pekerjaan yang terlambat, dan agenda HSE seluruh operasi dalam satu halaman.
                    </p>
                </div>

                <div className="flex shrink-0 flex-wrap items-center gap-2 lg:justify-end">
                    {/* Rendered only when there is more than one unit to
                        choose between: a selector that opens onto one
                        option implies there is somewhere else to go. */}
                    {companies.length > 1 && (
                        <Select value={selectedCompany} onValueChange={onCompanyChange}>
                            <SelectTrigger
                                className={cn(
                                    'h-9 w-full gap-2 rounded-xl border-white/15 bg-white/10 pl-3 text-[12px] font-medium text-white sm:w-56',
                                    'backdrop-blur-sm hover:bg-white/15 focus:ring-white/30 [&>svg]:text-blue-200'
                                )}
                            >
                                <Building2 className="h-3.5 w-3.5 shrink-0 text-blue-200" />
                                <SelectValue placeholder="All Operating Units" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Operating Units</SelectItem>
                                {companies.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}

                    {fullCalendarHref && (
                        <Link
                            href={fullCalendarHref}
                            className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-white/15 bg-white/10 px-3 text-[12px] font-medium text-white backdrop-blur-sm transition-colors hover:bg-white/15"
                        >
                            Open Full Calendar <ArrowUpRight className="h-3.5 w-3.5 text-blue-200" />
                        </Link>
                    )}
                </div>
            </div>
        </section>
    );
}
