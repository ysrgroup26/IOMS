import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/**
 * The one reusable subtle background watermark component. Dashboard,
 * Home, Login, and About all use this instead of each hardcoding their
 * own absolutely-positioned icon. Respects the per-context
 * enable/disable flag and the configurable opacity from the centralized
 * `branding` shared prop (Settings > Branding, future release -- see
 * ROADMAP.md), so turning watermarks off or adjusting their strength
 * will apply everywhere at once without touching any page.
 *
 * v1.6.0: fixed two issues found on review --
 *   1. `blur` used to be a single hardcoded 2px value reused at every
 *      render size (256px on Dashboard up to 640px on Login), so it was
 *      imperceptible at large sizes and comparatively too strong at
 *      small ones. It's now a prop, sized appropriately per usage.
 *   2. Dropped the unconditional `grayscale` -- it was desaturating the
 *      brand's actual blue on every instance, which muted visual impact
 *      and discarded brand color identity for no real benefit at these
 *      opacity levels. The brand blue at low opacity reads as more
 *      present, not less premium.
 *
 * Props:
 *   context - 'dashboard' | 'login' | 'home' | 'about', checked against
 *             branding.{context}_watermark_enabled
 *   size    - Tailwind height/width classes for the mark (default: large)
 *   blur    - Tailwind blur class, scaled to `size` by the caller
 *             (default: blur-sm, appropriate for the smallest usage)
 *   className - extra positioning classes for the specific placement
 *               each page needs (centered, center-right, etc.)
 * v1.6.2: added an `opacity` override prop -- some pages need an exact,
 * specific opacity (e.g. Dashboard's hero at exactly 4%) distinct from
 * the global default (3%) used everywhere else. Also: the actual root
 * cause of "the watermark isn't visible" on Dashboard/Home/About wasn't
 * this component at all -- it was each PAGE's own wrapping element using
 * `overflow-hidden` (or `overflow-y-auto`, which can't scroll to
 * negative offsets either) on a container considerably SHORTER than the
 * watermark itself, silently clipping most of the image away. Fixed at
 * each call site -- see Dashboard/Home/AboutDialog for the corrected
 * containers.
 */
export default function BrandWatermark({ context, size = 'h-[32rem] w-[32rem]', blur = 'blur-sm', opacity, className }) {
    const { branding } = usePage().props;

    const contextEnabled = branding?.[`${context}_watermark_enabled`] ?? true;
    if (!branding?.watermark_enabled || !contextEnabled) return null;

    return (
        <img
            src={branding.icon_url}
            alt=""
            aria-hidden="true"
            className={cn('pointer-events-none absolute select-none', blur, size, className)}
            style={{ opacity: opacity ?? branding.watermark_opacity ?? 0.03 }}
        />
    );
}
