import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import Reveal from '@/Components/public/Reveal';
import { cn } from '@/lib/utils';

/**
 * v2.76.0 -- ONE STORY: visual → domain → heading → explanation → CTA.
 *
 * The pattern the landing page uses to explain an operational domain, and
 * the one future domain sections should reuse rather than invent.
 *
 * THE TEXT IS ALWAYS HTML. The eyebrow, the heading, the explanation and
 * the CTA are real elements, never baked into an image. When real IOMS
 * imagery arrives it goes into `image` and carries the VISUAL story; what
 * the section says stays readable to people using assistive technology,
 * to search engines, and to anyone whose images did not load. That is the
 * whole reason the visual is a separate slot.
 *
 * WITHOUT AN IMAGE the slot renders <StoryVisual>, a restrained panel in
 * the product's own visual language -- navy header, the domain's icon, and
 * the real capabilities in that domain as a list. It is not a stand-in
 * rectangle: it states something true, so the page reads finished today
 * and simply gets richer when photography or screenshots are added.
 *
 * Deliberately NOT: a card grid, a gradient per domain, or a colour per
 * domain. Rows alternate sides so a long run of them reads as a sequence
 * rather than a wall, and one brand accent is used throughout.
 *
 * Props:
 *   eyebrow   the domain, e.g. "HSE & Safety"
 *   title     the heading (rendered as <h3>; the parent section owns <h2>)
 *   children  the explanation
 *   icon      a lucide icon for the domain
 *   items     real capabilities, shown in the visual placeholder
 *   image     { src, alt } -- replaces the placeholder when supplied
 *   cta       { label, href } -- optional; internal links use Inertia
 *   reverse   put the visual on the right
 */
export default function StorySection({ eyebrow, title, children, icon, items = [], image, cta, reverse = false }) {
    return (
        <Reveal className="grid items-center gap-6 py-8 lg:grid-cols-2 lg:gap-14 lg:py-14">
            <div className={cn('min-w-0', reverse && 'lg:order-2')}>
                <StoryVisual eyebrow={eyebrow} icon={icon} items={items} image={image} />
            </div>

            <div className={cn('min-w-0', reverse && 'lg:order-1')}>
                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-brand-700">{eyebrow}</p>
                <h3 className="mt-3 text-xl font-semibold tracking-tight text-graphite-900 sm:text-2xl">{title}</h3>
                <div className="mt-3 text-[15px] leading-relaxed text-graphite-600">{children}</div>

                {cta && <StoryLink {...cta} />}
            </div>
        </Reveal>
    );
}

/**
 * An in-page anchor (#solutions) is a plain <a>, so the browser scrolls;
 * anything else is an Inertia <Link>. Both are real, crawlable hrefs.
 */
function StoryLink({ label, href }) {
    const className = 'group mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:text-brand-800';
    const content = (
        <>
            {label}
            <ArrowRight className="h-4 w-4 transition-transform duration-200 motion-safe:group-hover:translate-x-0.5" aria-hidden="true" />
        </>
    );

    return href.startsWith('#')
        ? <a href={href} className={className}>{content}</a>
        : <Link href={href} className={className}>{content}</Link>;
}

/**
 * The visual slot. An image when one exists; otherwise a quiet panel that
 * lists the domain's real capabilities.
 *
 * The image keeps a fixed aspect ratio and lazy-loads, so adding one later
 * cannot shift the layout or slow the first screen.
 */
export function StoryVisual({ eyebrow, icon: Icon, items = [], image }) {
    if (image?.src) {
        return (
            <img
                src={image.src}
                alt={image.alt ?? ''}
                loading="lazy"
                decoding="async"
                className="aspect-[16/10] w-full rounded-xl border border-graphite-200 object-cover shadow-card"
            />
        );
    }

    return (
        <div className="overflow-hidden rounded-xl border border-graphite-200 bg-white shadow-card">
            <div className="flex items-center gap-2.5 bg-navy-900 px-4 py-3">
                {Icon && (
                    <span className="flex h-7 w-7 items-center justify-center rounded-md bg-white/[0.08] ring-1 ring-white/10">
                        <Icon className="h-4 w-4 text-[#01c1ed]" aria-hidden="true" />
                    </span>
                )}
                <span className="text-[13px] font-semibold text-white">{eyebrow}</span>
                <span className="ml-auto text-[10px] font-semibold uppercase tracking-[0.18em] text-navy-300">IOMS</span>
            </div>

            <ul className="divide-y divide-graphite-100 bg-gradient-to-b from-white to-steel-50/40">
                {items.map((item) => (
                    <li key={item} className="flex items-center gap-3 px-4 py-2.5 sm:py-3">
                        <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500" aria-hidden="true" />
                        <span className="min-w-0 flex-1 truncate text-[13px] text-graphite-700">{item}</span>
                        {/* A record's shape, not data: no numbers are
                            implied that the product did not produce. */}
                        <span className="hidden h-1.5 w-16 rounded-full bg-graphite-100 sm:block" aria-hidden="true" />
                    </li>
                ))}
            </ul>
        </div>
    );
}
