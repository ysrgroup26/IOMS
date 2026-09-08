import { useReveal } from '@/lib/useReveal';
import { cn } from '@/lib/utils';

/**
 * v2.59.0 -- the one motion primitive on the public site.
 *
 * A short rise and fade as a block enters the viewport. Deliberately ONE
 * gesture, used everywhere, rather than a vocabulary of effects: a page
 * where each section arrives differently reads as a demo of animation, and
 * IOMS sells operational software to people evaluating it in daylight on a
 * laptop.
 *
 * THE ELEMENT IS ALWAYS VISIBLE. Entering the viewport adds a one-shot
 * keyframe; it never removes one. So a failed, skipped or unsupported
 * observer costs the animation and nothing else — see useReveal for the
 * failure this deliberately designs out.
 *
 * `delay` staggers siblings in a grid. Kept small and capped by the
 * caller: a twelve-card grid at 100ms each takes over a second to finish,
 * which is where a reveal stops being polish and becomes a loading screen.
 */
export default function Reveal({ children, className, delay = 0, as: Tag = 'div' }) {
    const [ref, animate] = useReveal();

    return (
        <Tag
            ref={ref}
            className={cn(animate && 'motion-safe:animate-reveal', className)}
            style={animate && delay ? { animationDelay: `${delay}ms` } : undefined}
        >
            {children}
        </Tag>
    );
}
