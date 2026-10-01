import { useEffect, useState } from 'react';
import { MODULES } from '@/Components/public/PlatformShowcase';
import { cn } from '@/lib/utils';

/**
 * v2.90.0 -- THE PLATFORM, RUNNING.
 *
 * A translucent IOMS interface over the photographic hero, cycling slowly
 * through the workspaces a customer actually has. The point is not to show a
 * dashboard; it is to say that this is a system in use rather than a product
 * shot. A static card says "here is a screen". A rail whose active item moves
 * on its own says "somebody is working in this".
 *
 * IT READS THE SAME DATA AS THE SHOWCASE. `MODULES` is exported from
 * PlatformShowcase and shared, so the two product previews on this page
 * cannot drift into describing two different products. Everything here is a
 * real workspace with its real label and its real icon.
 *
 * THE CYCLE IS THE FIVE WORKSPACES THAT EXIST. The brief's example sequence
 * named Projects, which was retired from the customer-facing product in
 * v2.84.0, and Permit To Work, which is a capability inside HSE rather than a
 * workspace of its own. Advertising either in a loop on the landing page
 * would promise something no plan opens, so the loop runs the five real ones:
 * Dashboard, HSE, People / HRD, Warehouse Logistics, Management.
 *
 * SUBDUED ON PURPOSE. Low-opacity navy glass with a hairline edge, not a
 * white card: the photography has to stay legible behind it, and an opaque
 * panel over a photograph is just a screenshot with a drop shadow. It is
 * sized to sit across the blend between the two environments, which is the
 * one place on the page where an interface genuinely connects an office to a
 * dock.
 *
 * MOTION. One module every five seconds, a slow cross-fade, nothing sliding
 * or flashing. Under `prefers-reduced-motion` the cycle does not start at
 * all and the panel holds its first state, because an element that changes
 * by itself is exactly what that preference is asking not to see.
 *
 * ARIA. The panel is decorative narrative, not a control: it is marked
 * `aria-hidden`, because a screen reader user gains nothing from a silent
 * carousel of numbers they cannot act on, and the same figures are read
 * properly in the showcase further down the page.
 */

/** How long each module holds. Slow enough to read, not a slideshow. */
const DWELL_MS = 5000;

export default function HeroDashboard({ className }) {
    const [index, setIndex] = useState(0);

    useEffect(() => {
        const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)');

        if (reduced?.matches) {
            return undefined;
        }

        const id = setInterval(() => setIndex((i) => (i + 1) % MODULES.length), DWELL_MS);

        return () => clearInterval(id);
    }, []);

    const active = MODULES[index];

    return (
        <div
            aria-hidden="true"
            className={cn(
                'relative overflow-hidden rounded-xl border border-white/15 shadow-[0_30px_80px_-28px_rgba(3,12,26,0.85)]',
                // The glass. Navy rather than white, so it belongs to the
                // scene instead of sitting on top of it, and blurred enough
                // to stay readable over a busy photograph without erasing it.
                'bg-navy-950/55 backdrop-blur-md supports-[backdrop-filter]:bg-navy-950/45',
                className
            )}
        >
            {/* Chrome. Three dots and a workspace name: the least interface
                that still reads as an application window. */}
            <div className="flex items-center gap-2 border-b border-white/10 px-3 py-2">
                <span className="flex gap-1" aria-hidden="true">
                    {['bg-white/25', 'bg-white/20', 'bg-white/15'].map((c) => (
                        <span key={c} className={cn('h-1.5 w-1.5 rounded-full', c)} />
                    ))}
                </span>
                <p className="font-mono text-[10px] uppercase tracking-[0.16em] text-white/50">
                    IOMS / {active.label}
                </p>
            </div>

            <div className="flex">
                {/* The rail. The active item moves on its own, which is the
                    whole mechanism: it is what makes the panel read as used. */}
                <nav className="hidden w-[132px] shrink-0 border-r border-white/10 py-2 sm:block">
                    {MODULES.map((m, i) => (
                        <div
                            key={m.key}
                            className={cn(
                                'mx-1.5 flex items-center gap-2 rounded-md px-2 py-1.5 transition-colors duration-700',
                                i === index ? 'bg-white/[0.12] text-white' : 'text-white/45'
                            )}
                        >
                            <m.icon className="h-3.5 w-3.5 shrink-0" />
                            <span className="truncate text-[11px] font-medium">{m.label}</span>
                        </div>
                    ))}
                </nav>

                {/* The content. Keyed on the module so React swaps the
                    subtree and the fade runs from the start each time. */}
                <div key={active.key} className="motion-safe:animate-panel-in min-w-0 flex-1 p-3.5">
                    <p className="font-mono text-[9px] uppercase tracking-[0.18em] text-steel-300/70">
                        {active.eyebrow}
                    </p>
                    <p className="mt-1 font-display text-[13px] font-semibold tracking-tight text-white">
                        {active.title}
                    </p>

                    <div className="mt-3 grid grid-cols-3 gap-2">
                        {active.stats.slice(0, 3).map((s) => (
                            <div key={s.label} className="rounded-md border border-white/10 bg-white/[0.06] px-2 py-1.5">
                                <p className="font-display text-sm font-semibold leading-none text-white">{s.value}</p>
                                <p className="mt-1 truncate text-[9px] leading-tight text-white/50">{s.label}</p>
                            </div>
                        ))}
                    </div>

                    <div className="mt-2.5 space-y-1">
                        {(active.rows ?? []).slice(0, 3).map(([label, value]) => (
                            <div key={label} className="flex items-center justify-between gap-3 border-t border-white/[0.08] pt-1.5">
                                <span className="truncate text-[10px] text-white/55">{label}</span>
                                <span className="shrink-0 font-mono text-[10px] text-white/80">{value}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
