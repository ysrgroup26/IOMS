import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/**
 * Renders the IOMS wordmark. This is the ONE place in the whole app that
 * references the wordmark asset -- every page uses this component instead
 * of hardcoding an <img src="..."> path, so replacing the logo later is a
 * one-file change, never a page-by-page find-and-replace (v1.5.3).
 *
 * v2.38.0 -- CONFIRMED BRAND DEFECT, found by actually opening the app in
 * a browser rather than reading the code. The shipped default asset
 * (`public/branding/wordmark.png`) is NOT an IOMS mark at all: it is a
 * 2 MB, 1536x1024 photograph of a neon sign reading "icms" -- the
 * pre-rebrand product name -- on an opaque grey background. It was
 * rendering on the login screen, in the sidebar, on the public site and
 * on every PDF header, i.e. the first thing every user and every external
 * auditor sees.
 *
 * A correct IOMS logo does not exist anywhere in this repository, and
 * inventing one is a branding decision that is not mine to make. So this
 * component now degrades honestly instead: when a tenant has uploaded
 * their own wordmark it is used exactly as before, and otherwise the
 * product name is set as TYPE rather than showing the wrong brand.
 * Setting the product's own name in the product's own typeface is not a
 * logo design -- it is the correct neutral default until a real asset is
 * supplied, and it reverses itself automatically the moment one is.
 *
 * `alt` and sizing behaviour are unchanged for every existing caller.
 */
export default function BrandWordmark({ className = 'h-6 w-auto', alt }) {
    const { branding, company } = usePage().props;

    if (branding?.has_custom_wordmark && branding?.wordmark_url) {
        return (
            <img
                src={branding.wordmark_url}
                alt={alt || company?.name || 'IOMS'}
                className={className}
            />
        );
    }

    // Typographic fallback. Caller classNames are written for an IMAGE:
    // height-oriented sizing ("h-[70px] w-auto"), and in the sidebar a
    // negative margin ("-ml-[22px]") that compensates for the PNG's own
    // internal transparent padding. Applied verbatim to text those are
    // actively wrong -- browser verification caught the wordmark
    // rendering as "OMS", with the "I" clipped off the viewport edge by
    // that negative margin. Both families of utility are stripped here so
    // no caller has to know which mode is active.
    return (
        <span
            className={cn(
                'inline-flex select-none items-baseline font-semibold tracking-tight text-brand-600 dark:text-brand-400',
                className?.includes('text-') ? '' : 'text-3xl',
                (className || '')
                    .replace(/(^|\s)-?m[trblxy]?-\[?[\w.%-]+\]?/g, ' ')
                    .replace(/(^|\s)h-\[?[\w.%-]+\]?/g, ' ')
                    .trim()
            )}
            aria-label={alt || 'IOMS'}
        >
            IOMS
        </span>
    );
}
