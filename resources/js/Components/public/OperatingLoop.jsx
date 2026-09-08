import { useEffect, useRef, useState } from 'react';
import { Database, Hammer, Stamp, Activity, TrendingUp, RotateCcw } from 'lucide-react';
import { prefersReducedMotion } from '@/lib/useReveal';
import { cn } from '@/lib/utils';

/**
 * v2.64.0 -- THE OPERATING LOOP.
 *
 * What stood here was five bordered cards in a `lg:grid-cols-4` grid --
 * which, for five items, left one card orphaned on a second row on every
 * large screen. Beyond the layout bug, the shape was wrong for the
 * argument: five equal boxes say "here are five features", when the point
 * is that ONE record travels through all five and comes out the other end
 * as management reporting.
 *
 * THE CONCEPT: FIVE STATIONS ON ONE RAIL, AND THE RAIL CLOSES.
 *
 * The stages sit ON a line rather than beside each other, so the eye reads
 * a route instead of a list. After stage 05 the rail curves back to 01 --
 * because IOMS is a loop, not a funnel, and "what that reporting shows is
 * what the next cycle starts from" is the actual product claim. That
 * return arc is the idea the old strip could not express at all.
 *
 * MOTION, AND WHAT EACH PIECE IS SAYING:
 *
 *   PROGRESSIVE ILLUMINATION -- each station lights as it enters the
 *   viewport, in order. The rail behind it fills to match. Scrolling the
 *   section IS travelling the route; nothing moves on its own while you
 *   read.
 *
 *   ONE TRAVELLING HEAD -- a small lit dot rides the end of the filled
 *   rail, so the "signal" advances 01 -> 05 as the reader scrolls and is
 *   perfectly still while they read. A looping dash on the existing
 *   `animate-dataflow` keyframe was built here first and removed: it
 *   rendered wrong (see the note at the call site), and it was redundant
 *   anyway, because the fill already says "travel". A second thing moving
 *   on top of it was decoration, which is the exact failure this redesign
 *   exists to correct.
 *
 *   FOCUS DIMS THE REST -- hovering or focusing a station brightens it and
 *   quiets its neighbours. One thing at a time, which is what the copy is
 *   describing anyway.
 *
 * WHAT IT IS NOT. No canvas, no particles, no library, no autoplay
 * carousel, no glassmorphism, and -- after the dash came out -- nothing on
 * this section animates on a timer at all. One IntersectionObserver, CSS
 * transitions, and an inline SVG for the return arc.
 *
 * REDUCED MOTION, TWICE OVER. `prefersReducedMotion()` short-circuits the
 * observer, so every station renders lit and the rail renders full; and
 * the two TRANSFORM responses (the node's scale, the card's lift) are
 * `motion-safe:` only, while their colour and shadow changes stay -- the
 * interaction still answers you, it just stops moving. Nothing here can
 * leave content hidden: "lit" is a colour change, never a visibility one.
 */

// The five stages, in the order the server sends them. Icons match
// Pages/Public/HowItWorks.jsx exactly, so the landing page and the detail
// page label the same stage with the same mark.
const ICONS = [Database, Hammer, Stamp, Activity, TrendingUp];

export default function OperatingLoop({ steps = [] }) {
    const [lit, setLit] = useState(() => (prefersReducedMotion() ? steps.length : 0));
    const [focused, setFocused] = useState(null);

    // BOTH LAYOUTS ARE IN THE DOM -- only one is displayed, via `hidden
    // lg:block` / `lg:hidden`. They therefore need SEPARATE ref arrays: a
    // single shared one would be overwritten by whichever rendered last
    // (the mobile spine), leaving the observer watching `display:none`
    // elements that can never intersect, and no station would ever light
    // on desktop. Both are observed; the hidden one simply never fires.
    const railRefs = useRef([]);
    const spineRefs = useRef([]);

    // Progressive illumination. One observer for the whole rail; a station
    // lights when it arrives and never unlights, so scrolling back up does
    // not undo the journey.
    //
    // AND A DEADMAN SWITCH, which is the part that matters.
    //
    // `Reveal` can afford a silent observer because it only ever ADDS a
    // keyframe -- content is visible either way (see lib/useReveal). This
    // component is different in a way that is easy to miss: `lit` drives
    // COLOUR, so a station that is never reported stays pale forever and
    // the section looks broken rather than un-animated. Found while
    // verifying this build, on a page where the observer had gone quiet and
    // every existing Reveal on the page had stopped firing too.
    //
    // An IntersectionObserver always delivers an initial callback for each
    // target it observes. So: if nothing has arrived shortly after mount,
    // the mechanism is not running and the honest resting state is fully
    // lit. Costs the choreography, never the design.
    useEffect(() => {
        if (prefersReducedMotion() || typeof IntersectionObserver === 'undefined') {
            setLit(steps.length);

            return undefined;
        }

        let reported = false;

        const observer = new IntersectionObserver(
            (entries) => {
                reported = true;

                entries.forEach((entry) => {
                    if (! entry.isIntersecting) return;

                    const index = Number(entry.target.dataset.station);
                    setLit((current) => Math.max(current, index + 1));
                    observer.unobserve(entry.target);
                });
            },
            { threshold: 0.4, rootMargin: '0px 0px -10% 0px' }
        );

        [...railRefs.current, ...spineRefs.current].filter(Boolean).forEach((el) => observer.observe(el));

        const deadman = window.setTimeout(() => {
            if (! reported) setLit(steps.length);
        }, 1200);

        return () => {
            window.clearTimeout(deadman);
            observer.disconnect();
        };
    }, [steps.length]);

    if (steps.length === 0) {
        return null;
    }

    const progress = steps.length > 1 ? (Math.max(lit - 1, 0) / (steps.length - 1)) * 100 : 100;

    // Half of one station's column, as a percentage of the whole rail --
    // which is exactly how far in the first and last node centres sit.
    const halfColumn = 100 / (steps.length * 2);

    return (
        <div className="relative">
            {/* ============================================ DESKTOP RAIL */}
            <div className="hidden lg:block">
                <div className="relative">
                    {/* The rail, positioned to run BETWEEN THE OUTER NODE
                        CENTRES rather than edge to edge. Each station owns
                        1/n of the width and its node is centred in it, so
                        the first centre sits half a column in -- inset the
                        rail by exactly that and the line starts and stops on
                        a node instead of overshooting into empty gutter.
                        `top` is the node radius (38px / 2), so the rail
                        passes through the nodes rather than under them. */}
                    <div
                        className="absolute top-[19px] h-px"
                        style={{ left: `${halfColumn}%`, right: `${halfColumn}%` }}
                        aria-hidden="true"
                    >
                        <div className="relative h-px w-full bg-navy-900/10">
                            {/* Filled portion -- how far the visitor has
                                travelled. Width is the only thing that
                                animates, and it is transitioned, not
                                looped. */}
                            <div
                                className="absolute inset-y-0 left-0 bg-gradient-to-r from-brand-500 to-steel-500 transition-[width] duration-700 ease-out"
                                style={{ width: `${progress}%` }}
                            />

                            {/* THE LEADING HEAD -- the signal the brief asked
                                for, driven by the reader instead of a timer.
                                It sits at the end of the filled rail, so it
                                advances 01 -> 05 as you scroll and is
                                perfectly still while you read.

                                A looping dash was tried here first and
                                removed for two reasons. It rendered wrong:
                                `vectorEffect="non-scaling-stroke"` makes
                                strokeDasharray resolve in PIXELS rather than
                                the normalised pathLength units, so one dash
                                became about fourteen across an 870px rail.
                                And it was the wrong idea anyway -- the fill
                                already says "travel", so a second
                                continuously-animating element on top of it
                                was decoration, which is the thing this
                                redesign is meant to avoid. */}
                            <span
                                className="absolute top-1/2 h-2 w-2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-brand-500 shadow-[0_0_0_4px_rgba(59,133,221,0.16)] transition-[left] duration-700 ease-out"
                                style={{ left: `${progress}%` }}
                            />
                        </div>
                    </div>

                    <ol className="relative grid grid-cols-5 gap-4">
                        {steps.map((step, i) => (
                            <Station
                                key={step.step}
                                innerRef={(el) => { railRefs.current[i] = el; }}
                                index={i}
                                step={step}
                                icon={ICONS[i] ?? Database}
                                isLit={i < lit}
                                isFocused={focused === i}
                                isQuieted={focused !== null && focused !== i}
                                onFocus={() => setFocused(i)}
                                onBlur={() => setFocused(null)}
                            />
                        ))}
                    </ol>
                </div>

                {/* The return arc: 05 feeds 01. Drawn under the rail so it
                    reads as the route continuing rather than a decoration
                    laid on top. */}
                <div className="relative mt-8 h-16" aria-hidden="true">
                    <svg className="h-full w-full" viewBox="0 0 1000 64" fill="none" preserveAspectRatio="none">
                        <path
                            d="M 900 0 C 900 46, 860 58, 740 58 L 260 58 C 140 58, 100 46, 100 0"
                            stroke="rgb(15 39 71)"
                            strokeOpacity="0.14"
                            strokeWidth="1.5"
                            strokeDasharray="5 5"
                            fill="none"
                        />
                    </svg>
                    <p className="absolute inset-x-0 bottom-0 text-center text-xs text-graphite-500">
                        <RotateCcw className="mr-1.5 inline h-3.5 w-3.5 -translate-y-px text-brand-600" />
                        What the reporting shows is what the next cycle starts from.
                    </p>
                </div>
            </div>

            {/* ============================================= MOBILE SPINE */}
            {/* Not the desktop layout shrunk: five cards at 375px would be
                five illegible slivers. The rail turns vertical and the
                stations stack against it, which is the same journey read
                the way a phone is read. */}
            <ol className="relative lg:hidden">
                <div className="absolute bottom-6 left-[19px] top-6 w-px bg-navy-900/10" aria-hidden="true">
                    <div
                        className="w-px bg-gradient-to-b from-brand-500 to-steel-500 transition-[height] duration-700 ease-out"
                        style={{ height: `${progress}%` }}
                    />
                </div>

                {steps.map((step, i) => {
                    const Icon = ICONS[i] ?? Database;
                    const isLit = i < lit;

                    return (
                        <li
                            key={step.step}
                            ref={(el) => { spineRefs.current[i] = el; }}
                            data-station={i}
                            className="relative flex gap-4 pb-5 last:pb-0"
                        >
                            <span
                                className={cn(
                                    'relative z-10 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-4 ring-brand-50/60 transition-all duration-500',
                                    isLit
                                        ? 'bg-gradient-to-br from-navy-800 to-brand-600 text-white shadow-card'
                                        : 'bg-white text-graphite-300 shadow-[inset_0_0_0_1px_rgb(226_232_240)]'
                                )}
                            >
                                <Icon className="h-[18px] w-[18px]" />
                            </span>

                            <div
                                className={cn(
                                    'min-w-0 flex-1 rounded-xl border bg-white p-4 transition-all duration-500',
                                    isLit ? 'border-graphite-200 shadow-card' : 'border-graphite-100'
                                )}
                            >
                                <p className="font-mono text-[11px] text-graphite-400">{step.step}</p>
                                <h3 className="mt-0.5 text-[15px] font-semibold tracking-tight text-navy-900">{step.title}</h3>
                                <p className="mt-1.5 text-[13px] leading-relaxed text-graphite-600">
                                    {step.summary ?? step.body}
                                </p>
                            </div>
                        </li>
                    );
                })}

                <li className="flex gap-4 pt-1">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center" aria-hidden="true">
                        <RotateCcw className="h-4 w-4 text-brand-600" />
                    </span>
                    <p className="flex-1 pt-2.5 text-xs leading-relaxed text-graphite-500">
                        What the reporting shows is what the next cycle starts from.
                    </p>
                </li>
            </ol>
        </div>
    );
}

/**
 * One station. A node sitting on the rail, with its card beneath.
 *
 * `isLit` is a colour and shadow change only -- text is at full contrast
 * whether or not the observer ever fires, so an unlit station is still
 * completely readable. That is the same rule `Reveal` follows.
 */
function Station({ innerRef, index, step, icon: Icon, isLit, isFocused, isQuieted, onFocus, onBlur }) {
    return (
        <li
            ref={innerRef}
            data-station={index}
            className={cn(
                'group relative pt-[4.5rem] transition-opacity duration-300',
                isQuieted ? 'opacity-60' : 'opacity-100'
            )}
        >
            {/* Node. Centred on the rail line above the card. */}
            <span
                className={cn(
                    'absolute left-1/2 top-0 flex h-[72px] -translate-x-1/2 flex-col items-center',
                )}
                aria-hidden="true"
            >
                <span
                    className={cn(
                        'flex h-[38px] w-[38px] items-center justify-center rounded-xl transition-all duration-500',
                        isLit
                            ? 'bg-gradient-to-br from-navy-800 to-brand-600 text-white shadow-[0_4px_12px_-4px_rgba(33,102,196,0.55)]'
                            : 'bg-white text-graphite-300 shadow-[inset_0_0_0_1px_rgb(226_232_240)]',
                        // The two TRANSFORM-based responses are motion-safe:
                        // only. Colour and shadow changes are benign under
                        // reduced motion and stay, so the interaction still
                        // answers the visitor -- it just stops moving.
                        isFocused && 'shadow-[0_6px_18px_-4px_rgba(33,102,196,0.65)] motion-safe:scale-110'
                    )}
                >
                    <Icon className="h-[17px] w-[17px]" />
                </span>
                {/* The drop from node to card, so the two read as attached. */}
                <span
                    className={cn(
                        'w-px flex-1 transition-colors duration-500',
                        isLit ? 'bg-brand-300' : 'bg-graphite-200'
                    )}
                />
            </span>

            <div
                tabIndex={0}
                onMouseEnter={onFocus}
                onMouseLeave={onBlur}
                onFocus={onFocus}
                onBlur={onBlur}
                className={cn(
                    'h-full rounded-xl border bg-white p-5 text-left transition-all duration-300',
                    'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2',
                    isLit ? 'border-graphite-200 shadow-card' : 'border-graphite-100',
                    isFocused && 'border-brand-300 shadow-lift motion-safe:-translate-y-1'
                )}
            >
                <p className="font-mono text-[11px] text-graphite-400">{step.step}</p>
                <h3 className="mt-1 text-[15px] font-semibold leading-snug tracking-tight text-navy-900">
                    {step.title}
                </h3>
                <p className="mt-2 text-[13px] leading-relaxed text-graphite-600">
                    {step.summary ?? step.body}
                </p>
            </div>
        </li>
    );
};
