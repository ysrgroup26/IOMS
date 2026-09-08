import { cn } from '@/lib/utils';

/**
 * v2.59.0 -- ATMOSPHERE FOR THE NAVY SECTIONS.
 *
 * Every dark band on the landing page used the same treatment: a 56px
 * grid plus two large blurred colour blobs. Three sections deep that reads
 * as one flat blue field repeated, which was the specific weakness in this
 * page — not a lack of decoration, a lack of *depth*.
 *
 * WHAT REPLACES IT, and why each layer is here rather than being pretty:
 *
 *   GRID        a technical drawing grid, finer than before and fading out
 *               downward. It is the surface everything else sits on.
 *   SILHOUETTE  an abstract industrial skyline in flat navy — gantry
 *               crane, storage tanks, structural frames, a stack. Drawn as
 *               geometry, not illustration: no gradients, no detail, no
 *               attempt at a picture. It says "this software is for places
 *               that look like this" and then gets out of the way.
 *   GLOW        one soft light source, not two competing blobs.
 *
 * ALL THREE ARE INLINE SVG AND CSS. No image request, nothing to load,
 * nothing to lazy-load, and it scales to any viewport without a second
 * asset. The whole component is `aria-hidden` and `pointer-events-none`:
 * it is texture, and a screen reader should never meet it.
 *
 * `variant` picks how much of it appears. `hero` gets the full stack;
 * `band` is the quieter version for mid-page dark sections so they read as
 * the same material without competing with the hero.
 */
export default function BlueprintBackdrop({ variant = 'band', className }) {
    const isHero = variant === 'hero';

    return (
        <div className={cn('pointer-events-none absolute inset-0 -z-10 overflow-hidden', className)} aria-hidden="true">
            {/* Technical grid */}
            <div
                className="absolute inset-0"
                style={{
                    backgroundImage:
                        'linear-gradient(to right, rgba(255,255,255,0.07) 1px, transparent 1px), linear-gradient(to bottom, rgba(255,255,255,0.07) 1px, transparent 1px)',
                    backgroundSize: isHero ? '48px 48px' : '64px 64px',
                    maskImage: 'linear-gradient(to bottom, rgba(0,0,0,0.9), transparent 85%)',
                    WebkitMaskImage: 'linear-gradient(to bottom, rgba(0,0,0,0.9), transparent 85%)',
                }}
            />

            {/* One light source. */}
            <div
                className={cn(
                    'absolute rounded-full blur-3xl',
                    isHero
                        ? '-right-32 -top-40 h-[34rem] w-[34rem] bg-brand-500 opacity-[0.16]'
                        : '-right-40 -top-52 h-[26rem] w-[26rem] bg-brand-500 opacity-[0.10]'
                )}
            />

            {/* Industrial silhouette, anchored to the bottom edge. */}
            <svg
                className={cn('absolute inset-x-0 bottom-0 w-full', isHero ? 'h-56 opacity-[0.22]' : 'h-40 opacity-[0.14]')}
                viewBox="0 0 1440 220"
                preserveAspectRatio="xMidYMax slice"
                fill="none"
            >
                {/* Far plane: structural frames and a stack. */}
                <g fill="rgb(15 39 71)" opacity="0.75">
                    <rect x="60" y="120" width="120" height="100" />
                    <rect x="196" y="150" width="64" height="70" />
                    <rect x="286" y="96" width="26" height="124" />
                    <rect x="276" y="88" width="46" height="10" />
                    <rect x="1180" y="132" width="150" height="88" />
                    <rect x="1344" y="160" width="70" height="60" />
                </g>

                {/* Storage tanks. */}
                <g fill="rgb(15 39 71)">
                    <rect x="820" y="140" width="104" height="80" />
                    <ellipse cx="872" cy="140" rx="52" ry="14" />
                    <rect x="944" y="162" width="72" height="58" />
                    <ellipse cx="980" cy="162" rx="36" ry="10" />
                </g>

                {/* Gantry crane: the one recognisable form, kept simple. */}
                <g stroke="rgb(15 39 71)" strokeWidth="7" strokeLinecap="square">
                    <path d="M430 220 V70" />
                    <path d="M690 220 V70" />
                    <path d="M400 70 H720" />
                    <path d="M560 70 V118" />
                </g>
                <rect x="524" y="118" width="72" height="26" fill="rgb(15 39 71)" />
                {/* Diagonal bracing -- what makes it read as a structure. */}
                <g stroke="rgb(15 39 71)" strokeWidth="3" opacity="0.8">
                    <path d="M430 150 L690 150" />
                    <path d="M430 150 L560 100" />
                    <path d="M690 150 L560 100" />
                </g>

                {/* Ground line. */}
                <rect x="0" y="216" width="1440" height="4" fill="rgb(15 39 71)" />
            </svg>
        </div>
    );
}
