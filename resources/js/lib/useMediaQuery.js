import { useEffect, useState } from 'react';

/**
 * v2.67.0 -- a media query as React state.
 *
 * Needed because one thing in the shell genuinely cannot be expressed in
 * CSS: the sidebar is a PERMANENT NAVIGATION LANDMARK on a desktop and a
 * MODAL DIALOG on a phone, and `role` is an attribute, not a style. A
 * media query in CSS can move the rail on and off screen; it cannot tell
 * a screen reader that the same element changed what kind of thing it is.
 *
 * Deliberately not a dependency. `matchMedia` is in every browser this
 * product supports, and the whole hook is fifteen lines.
 */
export function useMediaQuery(query) {
    const [matches, setMatches] = useState(
        () => typeof window !== 'undefined' && window.matchMedia(query).matches
    );

    useEffect(() => {
        const mql = window.matchMedia(query);
        const onChange = (event) => setMatches(event.matches);

        // Re-read on mount: the initial state above is computed during
        // render, and the viewport can change before this effect runs.
        setMatches(mql.matches);
        mql.addEventListener('change', onChange);

        return () => mql.removeEventListener('change', onChange);
    }, [query]);

    return matches;
}
