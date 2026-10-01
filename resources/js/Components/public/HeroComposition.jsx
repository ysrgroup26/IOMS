import { cn } from '@/lib/utils';
import Photo from '@/Components/public/Photo';
import PermitArtifact from '@/Components/public/PermitArtifact';

/**
 * v2.89.0 -- TWO WORLDS, ONE RECORD.
 *
 * The hero now carries the argument IOMS is actually selling: an office
 * plans the work, a field crew executes it, and the thing that connects them
 * is a single record.
 *
 * WHY THIS IS NOT A SPLIT SCREEN. A 50/50 screen split with a photograph on
 * each side is the generic SaaS composition, and it says "two things" rather
 * than "one thing with two ends". Three choices keep this one composition:
 *
 *   STACKED, NOT SIDE BY SIDE. The two photographs occupy one column, one
 *   above the other, so the eye reads them as a sequence in time rather than
 *   as a comparison. Planning is above, execution below, which is the order
 *   the work happens in.
 *
 *   THE PERMIT CROSSES THE SEAM. The artifact is positioned over the join,
 *   overlapping both photographs. It is not decoration placed near them: it
 *   is the literal subject of the claim, the one record that exists in both
 *   environments. Remove it and the composition becomes two pictures.
 *
 *   ONE LIGHT, ONE SCRIM. Both frames carry the same navy wash and the same
 *   gradient dissolving their left edge into the text column, so they read as
 *   one surface the copy sits on rather than as two cards dropped on navy.
 *
 * THE COLOUR RELATIONSHIP IS IN THE PHOTOGRAPHS, NOT ADDED. The boardroom is
 * cool daylight over a refinery; the dock is warm dusk. Cool above, warm
 * below, is a temperature gradient the set already contained, and the scrims
 * are tuned to preserve it rather than flatten both to the same blue.
 *
 * MOTION PERFORMS THE SEQUENCE. The upper frame settles first, the lower one
 * follows, then the permit, then its approval seal. Four beats in the order
 * the work happens: plan, execute, record, approve. It plays once. Under
 * reduced motion every element is simply present, because each animation
 * holds its end state and nothing starts from hidden.
 *
 * MOBILE IS A SEQUENCE, NOT A SQUEEZE. Below `lg` the same story is told
 * vertically at a readable size, with the permit beneath rather than
 * overlapping, because an artifact straddling two 160px bands on a phone is
 * a smaller version of nothing.
 */
export default function HeroComposition() {
    return (
        <div className="relative">
            {/* ---------------------------------------------- desktop */}
            <div className="relative hidden lg:block">
                <Frame
                    name="management"
                    label="Office / Management"
                    alt=""
                    position="center 46%"
                    priority
                    className="motion-safe:animate-frame-settle"
                />

                {/* The seam. A hairline with a soft wash either side, so the
                    two environments MEET rather than being cut apart. */}
                <div className="relative h-px bg-steel-400/40">
                    <div className="absolute inset-x-0 -top-10 h-10 bg-gradient-to-t from-navy-900/70 to-transparent" />
                    <div className="absolute inset-x-0 top-0 h-10 bg-gradient-to-b from-navy-900/70 to-transparent" />
                </div>

                <Frame
                    name="operational"
                    label="Field / Operations"
                    alt=""
                    position="46% 46%"
                    scrim="soft"
                    className="motion-safe:animate-frame-settle [animation-delay:160ms]"
                />

                {/* The record, across the join. Offset left so the right of
                    both photographs stays visible: the permit has to look
                    like it is lying ON the composition, which means the
                    composition has to still be there behind it. */}
                <div className="absolute -left-10 top-1/2 w-[68%] -translate-y-1/2 xl:-left-14">
                    <PermitArtifact />
                </div>
            </div>

            {/* ---------------------------------------------- mobile */}
            <div className="lg:hidden">
                <div className="overflow-hidden rounded-xl border border-white/10">
                    <Frame name="management" label="Office / Management" alt="" position="center 46%" ratio="16 / 7" priority />
                    <div className="h-px bg-steel-400/40" />
                    <Frame name="operational" label="Field / Operations" alt="" position="46% 46%" ratio="16 / 7" scrim="soft" />
                </div>

                <div className="mt-6">
                    <PermitArtifact />
                </div>
            </div>
        </div>
    );
}

/**
 * One environment. The label is a quiet mono caption rather than a heading:
 * it names which world you are looking at, and at this size a photograph of
 * a boardroom and a photograph of a dock are not self-explanatory.
 */
function Frame({ name, label, alt, position, ratio = '16 / 9', priority = false, className = '', scrim = 'strong' }) {
    return (
        <div className={`relative overflow-hidden ${className}`}>
            <Photo
                name={name}
                alt={alt}
                ratio={ratio}
                position={position}
                priority={priority}
                sizes="(min-width: 1024px) 46vw, 100vw"
            />
            {/* Left edge into the copy, and a floor so the frame never ends
                on a hard horizontal line.

                TWO STRENGTHS, because the two photographs do not start at the
                same brightness. The boardroom is daylight and survives a
                strong wash; the dock is already dusk, and the same wash took
                it to near black. Matching the scrim to the exposure is what
                keeps the temperature relationship the set came with, instead
                of flattening both frames to the same navy. */}
            <div
                className={cn(
                    'pointer-events-none absolute inset-0 bg-gradient-to-r to-transparent',
                    scrim === 'soft' ? 'from-navy-900/90 via-navy-900/25' : 'from-navy-900 via-navy-900/45'
                )}
            />
            <div
                className={cn(
                    'pointer-events-none absolute inset-0 bg-gradient-to-t to-transparent',
                    scrim === 'soft' ? 'from-navy-900/35' : 'from-navy-900/55'
                )}
            />

            <p className="absolute bottom-3 right-4 font-mono text-[10px] uppercase tracking-[0.16em] text-white/70">
                {label}
            </p>
        </div>
    );
}
