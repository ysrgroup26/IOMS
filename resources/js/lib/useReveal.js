import { useEffect, useRef, useState } from 'react';

/**
 * v2.59.0 -- scroll reveal that CANNOT strand content, and needs no library.
 *
 * WHY NOT A LIBRARY. The page needed motion, not an animation engine.
 * Framer Motion is ~50KB gzipped for what an IntersectionObserver and a
 * CSS keyframe already do, on a bundle already at 420KB. The whole reveal
 * system cost about 6KB.
 *
 * THE DESIGN DECISION THAT MATTERS, and it was made after a failure found
 * in browser testing rather than reasoned about in advance.
 *
 * The obvious implementation is "render at opacity 0, transition to 1 when
 * observed". It has a failure mode: IntersectionObserver reports only
 * THRESHOLD CROSSINGS, so an element that moves from below the viewport to
 * above it within a single frame -- a programmatic jump, an in-page
 * anchor, the browser restoring scroll position -- was never intersecting
 * at either sample, no callback fires, and the element stays at opacity 0
 * FOREVER. Verified: a single scrollTo() past a heading left it invisible.
 *
 * Patching that with scroll listeners only narrows the window. So the
 * state is inverted instead: the element is ALWAYS VISIBLE, and entering
 * the viewport merely adds a one-shot CSS animation. If the observer never
 * fires, never runs, or is unsupported, the visitor sees the content with
 * no animation -- which is the correct degradation. There is no code path
 * that can hide content.
 *
 * REDUCED MOTION short-circuits before any of it: no observer is created
 * and no animation class is ever applied.
 */
function prefersReducedMotion() {
    if (typeof window === 'undefined' || !window.matchMedia) return false;

    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * @returns [ref, animate] -- `animate` is true once the element has
 *          entered the viewport. It never gates visibility, only motion.
 */
export function useReveal({ threshold = 0.12, rootMargin = '0px 0px -6% 0px' } = {}) {
    const ref = useRef(null);
    const [animate, setAnimate] = useState(false);

    useEffect(() => {
        if (animate || !ref.current) return undefined;
        if (prefersReducedMotion() || typeof IntersectionObserver === 'undefined') return undefined;

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((e) => e.isIntersecting)) {
                    setAnimate(true);
                    observer.disconnect();
                }
            },
            { threshold, rootMargin }
        );

        observer.observe(ref.current);

        return () => observer.disconnect();
    }, [animate, threshold, rootMargin]);

    return [ref, animate];
}

export { prefersReducedMotion };
