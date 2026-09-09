import { useEffect, useRef } from 'react';
import { router } from '@inertiajs/react';

/**
 * v2.65.0 -- DON'T LOSE SOMEBODY'S WORK.
 *
 * No form in IOMS guarded against navigating away mid-edit. A misclick on
 * a half-completed Permit To Work -- or on any of the twenty-field master
 * data forms -- discarded it silently. For a field supervisor filling in
 * a permit on a phone, an accidental back gesture is not a rare event.
 *
 * TWO EXITS, BOTH COVERED:
 *
 *   In-app navigation -- Inertia's `router.on('before')`, which catches a
 *   sidebar click, a breadcrumb, a Link, browser back.
 *   Leaving the site -- `beforeunload`, which is all the browser permits
 *   (the message is the browser's own; a custom string is ignored).
 *
 * IT ONLY FIRES WHEN THE FORM IS ACTUALLY DIRTY. A confirmation on a form
 * nobody has touched is the fastest way to train people to dismiss
 * confirmations without reading them. Dirtiness is a real comparison
 * against the values the form opened with, not a "user typed something"
 * flag -- so typing a character and deleting it again leaves the form
 * clean, correctly.
 *
 * IT MUST BE DISARMED ON SUBMIT. Saving navigates, and that navigation
 * is exactly the one that must not be challenged. Call `release()` in the
 * submit handler (or pass `enabled: false` once submitting) -- every call
 * site in this codebase does it via `onBefore`.
 *
 * @param {object}  current   the form's live data (Inertia's `data`)
 * @param {object}  original  the values it opened with
 * @param {boolean} enabled   set false while submitting, or to opt out
 */
export function useUnsavedChanges(current, original, enabled = true) {
    const released = useRef(false);

    const dirty = enabled && !released.current && !shallowEqual(current, original);
    const dirtyRef = useRef(dirty);
    dirtyRef.current = dirty;

    useEffect(() => {
        function onBeforeUnload(event) {
            if (! dirtyRef.current) return undefined;

            // Both forms are required: `preventDefault` for the spec,
            // `returnValue` for older WebKit/Blink.
            event.preventDefault();
            event.returnValue = '';

            return '';
        }

        window.addEventListener('beforeunload', onBeforeUnload);

        const stopInertia = router.on('before', () => {
            if (! dirtyRef.current) return true;

            return window.confirm(
                'You have unsaved changes on this form. Leave without saving?'
            );
        });

        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
            stopInertia();
        };
    }, []);

    return {
        isDirty: dirty,
        /** Disarm the guard -- call before a deliberate navigation (submit, discard). */
        release: () => { released.current = true; dirtyRef.current = false; },
    };
}

/**
 * One level deep, with arrays compared by membership.
 *
 * Deep equality is not wanted here: Inertia form state is a flat bag of
 * scalars, arrays of ids, and the occasional File. A File never compares
 * equal to itself across renders, so attaching one marks the form dirty
 * -- which is correct, since it IS a change.
 */
function shallowEqual(a, b) {
    if (a === b) return true;
    if (! a || ! b) return false;

    const keys = new Set([...Object.keys(a), ...Object.keys(b)]);

    for (const key of keys) {
        const left = a[key];
        const right = b[key];

        if (Array.isArray(left) || Array.isArray(right)) {
            const l = Array.isArray(left) ? left : [];
            const r = Array.isArray(right) ? right : [];
            if (l.length !== r.length) return false;
            if (l.some((v, i) => String(v) !== String(r[i]))) return false;
            continue;
        }

        // '' and null both mean "empty" in this codebase's form state --
        // Inertia initialises optional fields to '' while the server sends
        // null, and treating that difference as a change would mark every
        // edit form dirty the moment it opened.
        const leftEmpty = left === '' || left === null || left === undefined;
        const rightEmpty = right === '' || right === null || right === undefined;
        if (leftEmpty && rightEmpty) continue;

        if (String(left) !== String(right)) return false;
    }

    return true;
}

export { shallowEqual };
