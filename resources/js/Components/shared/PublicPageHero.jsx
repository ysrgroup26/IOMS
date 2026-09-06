import { cn } from '@/lib/utils';

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
export default function PublicPageHero({ eyebrow, title, subtitle, children, size = 'default' }) {
    return (
        <section
            className={cn(
                'relative isolate overflow-hidden border-b border-navy-800 bg-navy-900 text-white',
                size === 'sm' ? 'py-12 sm:py-14' : 'py-16 sm:py-20'
            )}
        >
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

            <div className="mx-auto max-w-3xl px-4 text-center sm:px-6">
                {eyebrow && (
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-steel-300">{eyebrow}</p>
                )}
                <h1 className="mt-3 text-3xl font-semibold leading-tight tracking-tight sm:text-4xl">{title}</h1>
                {subtitle && (
                    <p className="mx-auto mt-4 max-w-xl text-sm leading-relaxed text-navy-300 sm:text-base">{subtitle}</p>
                )}
                {children && <div className="mt-8">{children}</div>}
            </div>
        </section>
    );
}
