import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/**
 * Renders the IOMS logo. This is the ONE place in the whole app that
 * references the asset -- every page uses this component instead of
 * hardcoding an <img src="...">, so changing the logo is a one-file
 * change, never a page-by-page find-and-replace (v1.5.3).
 *
 * ---------------------------------------------------------------------
 * v2.38.0 -- THE MARK THAT WAS NOT OURS.
 *
 * A browser check found that the shipped default asset
 * (`public/branding/wordmark.png`) was not an IOMS mark at all: a 2 MB
 * photograph of a neon sign reading "icms", the pre-rebrand name, on an
 * opaque grey background -- rendering on the login screen, in the rail,
 * on the public site and on every PDF header. No correct logo existed in
 * the repository and inventing one was not a decision to make in code, so
 * this component degraded honestly to the product name set as TYPE.
 *
 * ---------------------------------------------------------------------
 * v2.72.0 -- THE OFFICIAL ARTWORK ARRIVED, SO THE STAND-IN IS GONE.
 *
 * The real lockup now ships (see config/branding.php for how the web
 * files were derived from the designer's originals without redrawing
 * anything). The typographic fallback has been removed rather than left
 * behind: it existed only because there was no logo, and keeping a second
 * rendering path alive invites the two to drift.
 *
 * `tone` NAMES THE SURFACE, NOT THE ARTWORK. The official set contains
 * two lockups -- a navy wordmark for light grounds and a near-white one
 * for dark grounds -- because a single lockup cannot serve both: on white
 * the near-white wordmark is invisible, and on the navy rail the navy one
 * is. Callers say where they are (`tone="dark"` on the rail, the email
 * header, a navy footer) and the right official variant is chosen. This
 * is selection among supplied variants, never a recolour.
 *
 * `tone="adaptive"` IS FOR A SURFACE THAT IS ITSELF BOTH. Most places
 * know what they are: the public header is always white, the rail is
 * always navy. A few flip with the theme -- the About dialog is
 * `bg-white dark:bg-slate-900` -- and on those, ONE lockup is wrong half
 * the time. Browser-checked: the navy wordmark (#00004f) on slate-900
 * (#0f172a) is very nearly invisible. Adaptive renders both and lets the
 * `dark` class decide, which is deterministic, needs no JavaScript, and
 * cannot flash the wrong mark before hydration the way reading a theme
 * hook would.
 *
 * A tenant that has uploaded its own mark keeps it on both surfaces --
 * IOMS ships two variants of its own logo; a customer's upload is simply
 * whatever they gave us.
 */
export default function BrandWordmark({ className = 'h-6 w-auto', alt, tone = 'light' }) {
    const { branding, company } = usePage().props;

    const label = alt || (branding?.has_custom_wordmark ? (company?.name || 'IOMS') : 'IOMS');
    const light = branding?.wordmark_url;
    const dark = branding?.wordmark_dark_url ?? branding?.wordmark_url;

    // The lockup is a fixed 3.85:1. Declaring its intrinsic size gives the
    // browser the ratio to reserve before the SVG loads, so a header does
    // not reflow -- the caller's own `h-6 w-auto` still decides the
    // rendered size.
    const intrinsic = { width: '1353', height: '351' };

    if (tone === 'adaptive') {
        return (
            <>
                <img src={light} alt={label} className={cn(className, 'dark:hidden')} {...intrinsic} />
                {/* aria-hidden on the twin: two <img> with the same alt
                    would otherwise announce the brand twice, since only
                    one is ever painted but both are in the tree. */}
                <img src={dark} alt="" aria-hidden="true" className={cn(className, 'hidden dark:block')} {...intrinsic} />
            </>
        );
    }

    return (
        <img
            src={tone === 'dark' ? dark : light}
            alt={label}
            className={className}
            {...intrinsic}
        />
    );
}
