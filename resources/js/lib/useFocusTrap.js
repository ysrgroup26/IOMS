import { useCallback, useEffect, useRef } from 'react';

const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

/**
 * v2.67.0 -- what makes an overlay a DIALOG rather than a div on top.
 *
 * The mobile sidebar looked modal (it had an overlay and a close button)
 * but behaved like ordinary page content: Tab walked straight out of it
 * into the page underneath, which was still there and still focusable, so
 * a keyboard user ended up operating a page they could not see through a
 * scrim. Escape did nothing. Closing the drawer dropped focus back to
 * `<body>`, so the next Tab restarted from the top of the document rather
 * than from the control that opened it.
 *
 * Three behaviours, which are the three a dialog owes its user:
 *
 *   ENTER   focus moves into the dialog when it opens
 *   STAY    Tab and Shift+Tab cycle within it, and Escape closes it
 *   RETURN  focus goes back to whatever opened it
 *
 * RETURN is the one most often missed and the most disorienting to lose,
 * so the opener is captured on the way IN rather than looked up on the
 * way out -- by then it may no longer exist.
 *
 * Returns a ref to put on the dialog container.
 */
export function useFocusTrap(active, onDismiss) {
    const containerRef = useRef(null);
    const openerRef = useRef(null);

    // Held in a ref so a caller passing an inline arrow doesn't re-run
    // the effect (and re-steal focus) on every render.
    const dismissRef = useRef(onDismiss);
    dismissRef.current = onDismiss;

    const focusable = useCallback(
        () => Array.from(containerRef.current?.querySelectorAll(FOCUSABLE) ?? [])
            .filter((el) => el.offsetParent !== null || el === document.activeElement),
        []
    );

    useEffect(() => {
        if (! active) return undefined;

        const container = containerRef.current;
        if (! container) return undefined;

        openerRef.current = document.activeElement;

        // ENTER. Prefer the first real control; fall back to the
        // container so the announcement still moves off the page behind.
        const first = focusable()[0];
        if (first) {
            first.focus();
        } else {
            container.setAttribute('tabindex', '-1');
            container.focus();
        }

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                event.stopPropagation();
                dismissRef.current?.();
                return;
            }

            if (event.key !== 'Tab') return;

            const items = focusable();
            if (items.length === 0) {
                event.preventDefault();
                return;
            }

            const firstItem = items[0];
            const lastItem = items[items.length - 1];

            // STAY. Also catches the case where focus has escaped the
            // container entirely (a click on the page behind), which a
            // naive first/last check silently allows to continue.
            if (! container.contains(document.activeElement)) {
                event.preventDefault();
                firstItem.focus();
                return;
            }

            if (event.shiftKey && document.activeElement === firstItem) {
                event.preventDefault();
                lastItem.focus();
            } else if (! event.shiftKey && document.activeElement === lastItem) {
                event.preventDefault();
                firstItem.focus();
            }
        }

        document.addEventListener('keydown', onKeyDown, true);

        return () => {
            document.removeEventListener('keydown', onKeyDown, true);

            // RETURN. Only if the opener is still in the document -- a
            // navigation may have replaced it, and focusing a detached
            // node silently sends focus to <body> instead.
            const opener = openerRef.current;
            if (opener && document.contains(opener) && typeof opener.focus === 'function') {
                opener.focus();
            }
        };
    }, [active, focusable]);

    return containerRef;
}
