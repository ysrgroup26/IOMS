import { usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
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

    return (
        <div
            className={cn(
                'inline-flex max-w-full flex-col items-center gap-1 rounded-md border-2 border-success/60 bg-success/[0.06] text-success',
                // A second, inset rule -- what gives a stamp its
                // impression rather than the flatness of a chip.
                'shadow-[inset_0_0_0_1px_rgba(255,255,255,0.75)]',
                compact ? 'px-3 py-1.5' : 'px-4 py-2.5',
                className
            )}
            // One accessible sentence, because the visual grouping below
            // reads as fragments to a screen reader.
            role="img"
            aria-label={`Approved by ${authorization.approver}${authorization.role ? `, ${authorization.role}` : ''}${at ? `, ${at}` : ''}`}
        >
            <span className="flex items-center gap-1.5" aria-hidden="true">
                <ShieldCheck className={cn('shrink-0', compact ? 'h-3.5 w-3.5' : 'h-4 w-4')} />
                <span className={cn('font-bold uppercase leading-none tracking-[0.18em]', compact ? 'text-[11px]' : 'text-[13px]')}>
                    Approved
                </span>
            </span>

            <span className="w-full border-t border-success/30" aria-hidden="true" />

            <span className="text-center leading-tight text-emerald-900 dark:text-emerald-300" aria-hidden="true">
                <span className={cn('block font-semibold', compact ? 'text-[11px]' : 'text-xs')}>{authorization.approver}</span>
                {authorization.role && (
                    <span className="block text-[10px] uppercase tracking-wide opacity-70">{authorization.role}</span>
                )}
                {at && <span className="block text-[10px] tabular-nums opacity-70">{at}</span>}
            </span>
        </div>
    );
}
