import { usePage } from '@inertiajs/react';

/**
 * Renders the official IOMS brand icon (the standalone mark, distinct
 * from the wordmark). The ONE place in the app that references this
 * asset -- see BrandWordmark for the same principle applied to the
 * wordmark (v1.5.3).
 */
export default function BrandIcon({ className = 'h-8 w-8', alt }) {
    const { branding, company } = usePage().props;

    return (
        <img
            src={branding?.icon_url}
            alt={alt || company?.name || 'IOMS'}
            className={className}
        />
    );
}
