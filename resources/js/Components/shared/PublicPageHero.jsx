import { cn } from '@/lib/utils';
import Photo from '@/Components/public/Photo';

/**
 * v2.51.0 -- the navy band every public page opens with.
 *
 * Extracted rather than copied a fifth time: /pricing and /get-started had
 * each hand-rolled the same grid overlay, the same steel bloom and the
 * same eyebrow/title/subtitle stack, and four more marketing pages were
 * about to repeat it. One component means the public site cannot drift
 * page by page the way the authenticated app once did.
 *
 * Restrained on purpose: a single flat navy ground, a faint 56px grid that
 * masks out before the lower edge, and one soft steel bloom. No neon, no
 * animated gradient, no full-colour panel — the same industrial register
 * as the authenticated shell.
 */
export default function PublicPageHero({ eyebrow, title, subtitle, children, size = 'default', align = 'start', photo = null }) {
    return (
        <section
            className={cn(
                'relative isolate overflow-hidden border-b border-navy-800 bg-navy-900 text-white',
                size === 'sm' ? 'py-12 sm:py-14' : 'py-16 sm:py-20'
            )}
        >
            {/* v2.89.0 -- AN OPTIONAL PHOTOGRAPH BEHIND THE BAND.
                The sub-pages had no imagery at all, so each one opened on the
                same navy rectangle and the site lost its atmosphere the
                moment a visitor left the landing page.

                Deliberately quieter than the landing hero: this is a page
                header rather than a composition, so the photograph sits
                behind the whole band at low contrast and the copy keeps
                priority. A page that is not the landing page should not be
                competing with it.

                Opt-in. A transactional header (an order, a status) passes
                nothing and keeps the plain band, because atmosphere is not
                what somebody checking a payment needs. */}
            {photo && (
                <div className="absolute inset-0 -z-10" aria-hidden="true">
                    <Photo
                        name={photo.name}
                        alt=""
                        ratio="auto"
                        className="h-full w-full"
                        position={photo.position ?? 'center'}
                        sizes="100vw"
                        priority
                    />
                    <div className="absolute inset-0 bg-navy-900/82" />
                    <div className="absolute inset-0 bg-gradient-to-r from-navy-900 via-navy-900/70 to-navy-900/85" />
                </div>
            )}

            <div
                className="pointer-events-none absolute inset-0 -z-10 opacity-[0.16]"
                aria-hidden="true"
                style={{
                    backgroundImage:
                        'linear-gradient(to right, rgba(255,255,255,0.10) 1px, transparent 1px), linear-gradient(to bottom, rgba(255,255,255,0.10) 1px, transparent 1px)',
                    backgroundSize: '56px 56px',
                    maskImage: 'linear-gradient(to bottom, black, transparent 92%)',
                }}
            />
            <div
                className="pointer-events-none absolute -right-40 -top-40 -z-10 h-[32rem] w-[32rem] rounded-full bg-steel-500 opacity-[0.14] blur-3xl"
                aria-hidden="true"
            />

            {/* v2.85.0 -- LEFT-ALIGNED, AND ON THE SAME MARGIN AS THE PAGE.
                Centred intros put every line on a different left edge, which
                is the slowest way to read a paragraph, and they gave each
                sub-page a different axis from the landing page's own hero.
                The eyebrow is now a mono instrument label and the title is
                set in the display face, so a section on /platform and a
                section on / are recognisably the same system.

                `align="center"` remains available for the short
                single-sentence intros (an order page, a status page) where a
                measure this narrow genuinely reads better centred. */}
            <div
                className={cn(
                    'mx-auto px-4 sm:px-6 lg:px-8',
                    align === 'center' ? 'max-w-3xl text-center' : 'max-w-7xl'
                )}
            >
                <div className={cn(align === 'center' ? '' : 'max-w-3xl')}>
                    {eyebrow && (
                        <p className="font-mono text-[11px] uppercase tracking-[0.18em] text-steel-300">{eyebrow}</p>
                    )}
                    <h1 className="mt-4 font-display text-[2rem] font-semibold leading-[1.08] tracking-[-0.02em] sm:text-[2.6rem]">
                        {title}
                    </h1>
                    {subtitle && (
                        <p className={cn(
                            'mt-5 text-sm leading-relaxed text-navy-300 sm:text-base',
                            align === 'center' ? 'mx-auto max-w-xl' : 'max-w-2xl'
                        )}>
                            {subtitle}
                        </p>
                    )}
                    {children && <div className="mt-8">{children}</div>}
                </div>
            </div>
        </section>
    );
}
