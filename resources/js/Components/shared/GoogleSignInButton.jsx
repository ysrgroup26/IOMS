/**
 * v2.74.0 -- CONTINUE WITH GOOGLE.
 *
 * A plain anchor, not an Inertia `<Link>` and not a fetch. The OAuth
 * redirect has to be a real, full-page browser navigation to Google's own
 * domain: an XHR cannot follow a cross-origin redirect to a consent
 * screen, and Inertia would try to parse Google's HTML as a page
 * component. `<a href>` is not a shortcut here, it is the mechanism.
 *
 * WHY THE MARK IS INLINE SVG. Google's brand guidelines require their
 * own multi-colour "G" on a sign-in button, and it must not be recoloured
 * to match a theme. Inlining it keeps it correct on both light and dark
 * surfaces without a network request and without the risk of a CSS rule
 * tinting it.
 *
 * The button is rendered only when the deployment has Google credentials
 * configured -- see GoogleAuthController::configured(). A button that
 * leads to a 404 is worse than no button.
 */
export default function GoogleSignInButton({ label = 'Continue with Google', className = '' }) {
    return (
        <a
            href="/auth/google/redirect"
            className={
                'flex w-full items-center justify-center gap-2.5 rounded-lg border border-graphite-200 bg-white px-4 py-2.5 '
                + 'text-sm font-medium text-graphite-800 transition-colors hover:bg-graphite-50 '
                + 'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-1 '
                + className
            }
        >
            <GoogleMark />
            {label}
        </a>
    );
}

function GoogleMark() {
    return (
        <svg className="h-[18px] w-[18px] shrink-0" viewBox="0 0 18 18" aria-hidden="true" focusable="false">
            <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62Z" />
            <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18Z" />
            <path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33Z" />
            <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58Z" />
        </svg>
    );
}
