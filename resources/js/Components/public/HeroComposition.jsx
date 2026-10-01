import Photo from '@/Components/public/Photo';

/**
 * v2.90.0 -- ONE PANORAMIC SCENE, NOT TWO FRAMES.
 *
 * The previous version put each environment in its own bordered frame with a
 * hairline between them and navy showing around the edges. It read as two
 * pictures on a dark page, which is the diptych this was supposed to avoid.
 *
 * This fills the hero. Management occupies the left, field the right, and
 * the two meet through a feathered overlap rather than an edge:
 *
 *   THE RIGHT PHOTOGRAPH IS MASKED, NOT BUTTED UP. Its left edge fades out
 *   over roughly a fifth of the width with a mask gradient, so the office
 *   dissolves into the dock instead of ending at a line. There is no divider,
 *   no border and no gap for the background to show through, because the two
 *   images physically overlap in the blend zone.
 *
 *   THE SEAM SITS WHERE BOTH IMAGES ARE QUIET. The boardroom's right side is
 *   a dark wall and the dock's left side is hull and shadow, so the blend
 *   crosses two low-detail regions. Feathering between two busy areas is what
 *   makes a composite look like a composite.
 *
 *   ONE GRADE OVER BOTH. A single navy wash and one warm-to-cool balance pass
 *   sit above both photographs rather than per image, so they share a grade
 *   and read as one exposure. Per-image scrims were what made the old version
 *   look like two separate pictures.
 *
 * THE PHOTOGRAPHY IS THE HERO SURFACE. Navy is no longer the field the hero
 * is built on; it survives only as the scrim that keeps the copy legible and
 * as the ground beneath the blend.
 *
 * MOBILE KEEPS THE TWO WORLDS. Below `md` the scene stacks vertically,
 * management above and field below, blended through the same feather rotated
 * ninety degrees. Cropping one environment away, or squeezing both into a
 * single narrow strip, would lose the one thing the composition exists to
 * say.
 */
export default function HeroComposition() {
    return (
        <div className="absolute inset-0 -z-10" aria-hidden="true">
            {/* ---------------------------------------------- wide: left / right */}
            <div className="absolute inset-0 hidden md:block">
                {/* Management fills the whole field. The field photograph is
                    laid over its right side, so there is never an uncovered
                    pixel between them. */}
                <Photo
                    name="management"
                    alt=""
                    priority
                    ratio="auto"
                    className="absolute inset-0 h-full w-full"
                    position="38% 50%"
                    sizes="60vw"
                />

                <div
                    className="absolute inset-y-0 right-0 w-[62%]"
                    style={{
                        // The feather. Transparent at the left edge, solid by
                        // 34%, so the overlap is a gradient rather than a cut.
                        WebkitMaskImage: 'linear-gradient(to right, transparent 0%, rgba(0,0,0,0.55) 16%, black 34%)',
                        maskImage: 'linear-gradient(to right, transparent 0%, rgba(0,0,0,0.55) 16%, black 34%)',
                    }}
                >
                    <Photo
                        name="operational"
                        alt=""
                        priority
                        ratio="auto"
                        className="h-full w-full"
                        position="52% 50%"
                        sizes="62vw"
                    />
                </div>
            </div>

            {/* ---------------------------------------------- narrow: top / bottom */}
            <div className="absolute inset-0 md:hidden">
                <Photo
                    name="management"
                    alt=""
                    priority
                    ratio="auto"
                    className="absolute inset-0 h-full w-full"
                    position="42% 44%"
                    sizes="100vw"
                />

                <div
                    className="absolute inset-x-0 bottom-0 h-[58%]"
                    style={{
                        WebkitMaskImage: 'linear-gradient(to bottom, transparent 0%, rgba(0,0,0,0.55) 14%, black 32%)',
                        maskImage: 'linear-gradient(to bottom, transparent 0%, rgba(0,0,0,0.55) 14%, black 32%)',
                    }}
                >
                    <Photo
                        name="operational"
                        alt=""
                        priority
                        ratio="auto"
                        className="h-full w-full"
                        position="46% 50%"
                        sizes="100vw"
                    />
                </div>
            </div>

            {/* ---------------------------------------------- one grade, over both */}

            {/* Ties the two exposures together and settles the whole scene
                into the brand's navy without flattening it. */}
            <div className="absolute inset-0 bg-navy-950/32" />

            {/* Readability under the copy. Weighted to the left and the
                bottom, which is where the text sits, and left almost clear on
                the right so the dock keeps its sunset. */}
            <div className="absolute inset-0 bg-gradient-to-r from-navy-950/80 via-navy-950/18 to-transparent" />
            <div className="absolute inset-0 bg-gradient-to-t from-navy-950/80 via-navy-950/5 to-navy-950/45" />
        </div>
    );
}
