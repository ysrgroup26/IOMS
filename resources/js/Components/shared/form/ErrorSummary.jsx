import { useEffect, useRef } from 'react';
import { AlertTriangle } from 'lucide-react';

/**
 * v2.65.0 -- WHAT HAPPENS WHEN SUBMISSION FAILS.
 *
 * Before this, nothing did. All 27 module forms rendered errors per
 * field and nowhere else, so on a twenty-field form whose invalid field
 * was below the fold, pressing Save appeared to do nothing at all. The
 * page did not move, no message appeared in view, and the user's only
 * recourse was to scroll and hunt for red text. For a screen reader user
 * there was no recourse at all -- the failure was never announced.
 *
 * This renders once, above the form, after a failed submit:
 *
 *   - It is focused programmatically, which both moves the viewport to
 *     it and makes assistive technology read it. `tabIndex={-1}` makes it
 *     focusable without adding it to the tab order.
 *   - It is `role="alert"`, so it is announced even if focus were to
 *     land elsewhere.
 *   - Every entry is a BUTTON that focuses and scrolls to its field,
 *     using the `data-field` anchor and control id FormField provides.
 *     Reaching the problem is one click rather than a hunt.
 *   - It names the count, because "3 fields need attention" sets an
 *     expectation that a single generic "please check the form" does not.
 *
 * IT DOES NOT REPLACE PER-FIELD ERRORS. Both are wanted: the summary
 * answers "what went wrong and where", the inline message answers "what
 * is wrong with THIS field" at the point of correction.
 *
 * SERVER-AUTHORITATIVE. It renders Laravel's own validation bag exactly
 * as received. No client-side rule is introduced and no server rule is
 * relaxed to make the UI simpler -- the messages shown are the messages
 * the backend produced.
 */
export default function ErrorSummary({ errors, labels = {}, title }) {
    const ref = useRef(null);
    const keys = Object.keys(errors ?? {});
    const count = keys.length;

    // Focus on ARRIVAL only -- keyed on the count so correcting one field
    // of three does not yank focus back to the top while the user is
    // still working through the list.
    useEffect(() => {
        if (count > 0) ref.current?.focus();
    }, [count]);

    if (count === 0) return null;

    function goToField(key) {
        // Attribute-selector escaping without CSS.escape: field names
        // here are Laravel validation keys (snake_case, dots for array
        // members), so only the quote needs handling -- and this avoids
        // depending on a global the lint config does not know about.
        const root = document.querySelector(`[data-field="${key.replace(/"/g, '\\\\"')}"]`);
        const control = document.getElementById(`field-${key}`)
            ?? root?.querySelector('input, select, textarea, button, [tabindex]');

        (root ?? control)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        control?.focus({ preventScroll: true });
    }

    return (
        <div
            ref={ref}
            tabIndex={-1}
            role="alert"
            className="rounded-lg border border-danger/30 bg-danger-light p-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-danger"
        >
            <div className="flex items-start gap-2.5">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-danger" aria-hidden="true" />
                <div className="min-w-0">
                    <p className="text-[13px] font-semibold text-danger">
                        {title ?? `${count} ${count === 1 ? 'field needs' : 'fields need'} your attention`}
                    </p>
                    <ul className="mt-2 space-y-1">
                        {keys.map((key) => (
                            <li key={key}>
                                <button
                                    type="button"
                                    onClick={() => goToField(key)}
                                    className="text-left text-xs text-danger underline-offset-2 hover:underline focus:outline-none focus-visible:underline"
                                >
                                    <span className="font-medium">{labels[key] ?? humanise(key)}</span>
                                    {' — '}
                                    {errors[key]}
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </div>
    );
}

/** `company_id` -> "Company", `start_datetime` -> "Start datetime". */
function humanise(key) {
    return key
        .replace(/\.\d+$/, '')
        .replace(/_id$/, '')
        .replace(/[._]/g, ' ')
        .replace(/^\w/, (c) => c.toUpperCase());
}
