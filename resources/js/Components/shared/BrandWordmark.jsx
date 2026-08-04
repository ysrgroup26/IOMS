import { usePage } from '@inertiajs/react';

/**
 * Renders the official IOMS wordmark. This is the ONE place in the whole
 * app that references the wordmark asset -- every page uses this
 * component instead of hardcoding an <img src="..."> path, so replacing
 * the logo later is a one-file config/asset change, never a page-by-page
 * find-and-replace (v1.5.3).
 */
export default function BrandWordmark({ className = 'h-6 w-auto', alt }) {
    const { branding, company } = usePage().props;

    return (
        <img
            src={branding?.wordmark_url}
            alt={alt || company?.name || 'IOMS'}
            className={className}
        />
    );
}
