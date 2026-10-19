import { usePage } from '@inertiajs/react';
import { DocumentActionStamp } from '@/Components/shared/DocumentStamp';

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
export default function ApprovalStamp({ authorization, className, compact = false }) {
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
     * v2.125.0 -- THE DRAWING MOVED OUT; THE CONTRACT STAYED HERE.
     *
     * The stamp used to be drawn inline in this file, in the product's
     * success green. Two things changed and neither is cosmetic:
     *
     *  - APPROVAL IS BLUE NOW. The owner's stamp design separates the two
     *    acts by colour -- green marks a VERIFICATION, blue marks an
     *    APPROVAL -- and a product that uses one colour for both makes the
     *    distinction unreadable at exactly the glance it exists for.
     *    Green stays reserved for a verification record, and PTW renders
     *    one only if it ever has one: this product has a single approval
     *    gate (`hse_approver_id`), and inventing a second stage so the
     *    design has somewhere to put green would be inventing authority.
     *
     *  - THE TITLE IS THE COMPANY'S, NOT OURS. `authorization.role` is now
     *    the job title that account held when it signed -- "SPV HSE",
     *    "Manager Shipyard", whatever the customer configured -- read from
     *    the stored action record, so renaming a position cannot rewrite a
     *    permit already filed. See PermitToWorkController::authorizationFor().
     *
     * What did NOT change is the only thing that really matters: there is
     * still no boolean. This component takes the server's authorization
     * record or renders nothing, and `DocumentActionStamp` repeats the
     * same refusal on its own `by` prop -- so neither layer can be talked
     * into drawing an approval that never happened.
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
