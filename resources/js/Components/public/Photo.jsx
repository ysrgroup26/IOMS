import { cn } from '@/lib/utils';

/**
 * v2.88.0 -- ONE WAY TO PUT A PHOTOGRAPH ON THE PUBLIC SITE.
 *
 * Every photograph on the marketing site goes through here, so the things
 * that are easy to forget per call site are impossible to forget once:
 *
 *   RESERVED SPACE. The wrapper carries the aspect ratio, so the layout does
 *   not jump when the image arrives. On a page with six photographs that is
 *   the difference between a calm load and a page that rearranges itself
 *   under the reader.
 *
 *   THE RIGHT FILE FOR THE VIEWPORT. `sizes` tells the browser how wide this
 *   image will actually be rendered, which is what lets it pick the 640
 *   instead of the 1600 on a phone. Getting `sizes` wrong is the usual reason
 *   a responsive image still downloads the largest file.
 *
 *   A FALLBACK THAT IS NOT A BROKEN ICON. WebP for everything that reads it,
 *   one JPEG underneath for anything that does not.
 *
 *   ALT TEXT IS REQUIRED, not optional with a default of empty string. A
 *   decorative backdrop passes `alt=""` deliberately and says so at the call
 *   site; a photograph that carries meaning has to describe itself.
 *
 * WIDTHS come from the manifest below rather than being guessed, because
 * three of the photographs are only 787px wide and offering a browser a 1600
 * that does not exist produces a 404 on some and a stretched image on others.
 */

/**
 * What `scripts/build-website-images.mjs` actually produced, per image.
 * `fallback` is the JPEG width, which differs for the narrow sources.
 */
const MANIFEST = {
    operational: { widths: [640, 1024, 1600], fallback: 1024 },
    shipyard: { widths: [640, 1024, 1600], fallback: 1024 },
    construction: { widths: [640, 1024, 1600], fallback: 1024 },
    mining: { widths: [640, 1024, 1600], fallback: 1024 },
    management: { widths: [640, 1024, 1600], fallback: 1024 },
    energy: { widths: [640], fallback: 787 },
    warehouse: { widths: [640], fallback: 783 },
    workshop: { widths: [640], fallback: 787 },
};

const BASE = '/images/website';

export default function Photo({
    name,
    alt,
    className,
    imgClassName,
    // How wide this renders, so the browser can choose. Default assumes a
    // panel at roughly half the content width on a desktop.
    sizes = '(min-width: 1024px) 50vw, 100vw',
    // `eager` for anything above the fold; everything else waits.
    priority = false,
    ratio = '16 / 9',
    position = 'center',
}) {
    const entry = MANIFEST[name];

    if (!entry) {
        // A missing manifest entry is a build mistake, not a runtime state to
        // design around. Render nothing rather than a broken image.
        return null;
    }

    const srcSet = entry.widths.map((w) => `${BASE}/${name}-${w}.webp ${w}w`).join(', ');

    return (
        <div
            className={cn('relative overflow-hidden', className)}
            style={ratio === 'auto' ? undefined : { aspectRatio: ratio }}
        >
            <picture>
                <source type="image/webp" srcSet={srcSet} sizes={sizes} />
                <img
                    src={`${BASE}/${name}-${entry.fallback}.jpg`}
                    alt={alt}
                    loading={priority ? 'eager' : 'lazy'}
                    decoding={priority ? 'sync' : 'async'}
                    fetchPriority={priority ? 'high' : 'auto'}
                    className={cn('h-full w-full object-cover', imgClassName)}
                    style={{ objectPosition: position }}
                />
            </picture>
        </div>
    );
}
