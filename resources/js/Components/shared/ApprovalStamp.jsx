import { usePage } from '@inertiajs/react';
import { DocumentActionStamp } from '@/Components/shared/DocumentStamp';
import { cn } from '@/lib/utils';

/**
 * v2.72.0 -- THE DIGITAL APPROVAL MARK ON A CONTROLLED DOCUMENT.
 *
 * A permit to work is not a record that happens to have a status field.
 * It is an instrument that authorises dangerous work to begin, and the
 * one question everybody asks of it -- on a phone, at a gate, on paper
 * taped to a bulkhead -- is "has this been approved, by whom, and when".
 * A small grey status pill answers that the same way it answers "is this
 * task done", which is the wrong register entirely.
 *
 * WHAT MAKES IT READ AS A SEAL RATHER THAN A BADGE:
 *   - it names the act ("APPROVED"), not the record's state;
 *   - it carries the authority: who, in what role, at what time;
 *   - it is ruled and bordered like something applied to a document,
 *     with the double rule a stamp impression has;
 *   - it is set in the document's own type, slightly tracked out, at a
 *     size that survives being printed and photographed.
 *
 * WHAT KEEPS IT HONEST, which matters more than how it looks:
 *
 *   IT CANNOT BE RENDERED WITHOUT A SERVER-SIDE APPROVAL RECORD. There is
 *   no `approved` prop and no boolean to pass. The component takes the
 *   authorization record itself and returns null when there isn't one --
 *   so "show the stamp" is not a decision a page can make. The record is
 *   built by PermitToWorkController::authorizationFor(), which returns
 *   null unless PermitToWork::isAuthorised() is true, which requires both
 *   an approved-or-later status AND a persisted `hse_approver_id` that
 *   only a canManageHse() user can cause to be written.
 *
 *   There is deliberately no variant for "pending", "draft" or any other
 *   state. A stamp that can render in more than one colour is a status
 *   badge wearing a costume, and the failure mode -- someone reading a
 *   grey or amber seal as authorisation at a glance on site -- is exactly
 *   the one worth designing out.
 *
 * GREEN, and only here. The product reserves `success` for "this is
 * complete and correct"; nothing else in the document uses it, so the eye
 * finds the seal immediately on a page that is otherwise navy, graphite
 * and white.
 *
 * THE TIME IS RENDERED IN THE PRODUCT'S DISPLAY TIMEZONE, not the
 * reader's. This was caught in the browser by comparing the screen
 * against the generated PDF: the two disagreed by an hour, because the
 * PDF resolves against `config('ioms.display_timezone')` and this
 * formatted in whatever timezone the device happened to be in. That is
 * the SAME defect v2.37.0 fixed for the permit's validity window (see
 * Pages/PermitsToWork/Document.jsx's formatDateTime note) and it matters
 * more here, not less: two people reading one approval must read one
 * time, and a permit whose seal says a different hour on paper than on
 * screen is a controlled document contradicting itself.
 */
export default function ApprovalStamp({ authorization, stamp = null, className, compact = false }) {
    // Falls back to the device timezone if the prop is somehow absent,
    // so the seal can never render a blank where a time should be.
    const displayTimeZone = usePage().props.display_timezone;

    // The guard IS the API. No record, no stamp.
    if (! authorization?.approver) {
        return null;
    }

    const at = authorization.at
        ? new Date(authorization.at).toLocaleString('en-US', {
            day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
            ...(displayTimeZone ? { timeZone: displayTimeZone } : {}),
        })
        : null;

    /*
     * v2.126.0 -- THE SEAL IS NOW DRAWN ON THE SERVER, ONCE.
     *
     * v2.125.0 drew it here in CSS and gave the PDF a plain bordered box,
     * on the stated belief that Dompdf could honour neither the rotation
     * nor the ink texture. That belief was never tested and is wrong, and
     * the owner's reply to the release was that the PDF stamp was still a
     * plain blue box -- which it was. Two implementations of one design
     * had already drifted by the time anyone looked at both.
     *
     * So `StampRenderer` draws it as SVG and the controller hands the SAME
     * data URI to this page and to the PDF. An <img> rather than inline
     * SVG because the bytes arrive as a data URI and must stay byte-
     * identical to the sheet: re-expressing them in JSX is how the two
     * surfaces diverge again.
     *
     * `role="img"` with the full sentence as its label, since the drawing
     * carries no text a screen reader can reach.
     */
    const spoken = `Approved by ${authorization.approver}`
        + (authorization.role ? `, ${authorization.role}` : '')
        + (at ? `, ${at}` : '');

    if (stamp) {
        return (
            <img
                src={stamp}
                alt={spoken}
                role="img"
                className={cn('block h-auto max-w-full', compact ? 'w-[150px]' : 'w-[200px]', className)}
            />
        );
    }

    /*
     * No server-drawn stamp reached this page -- an older cached payload,
     * or a surface that has not been passed the prop yet. The component
     * still refuses to invent a seal, and falls back to the typographic
     * one rather than rendering nothing, because the APPROVAL ITSELF is a
     * fact the reader needs even when the drawing is missing.
     */
    return (
        <DocumentActionStamp
            variant="approved"
            by={authorization.approver}
            title={authorization.role ?? null}
            at={at}
            compact={compact}
            className={className}
        />
    );
}
