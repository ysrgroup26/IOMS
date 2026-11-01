/**
 * v2.138.0 -- ONE MEANING, ONE COLOUR (ADR 081).
 *
 * The single map from a stored status (or priority) value to its semantic
 * tone. `StatusBadge` reads it, and so does every page that used to carry its
 * own STATUS_VARIANT copy -- which is how "in progress" came to be green on
 * Tasks (success), "expiring soon" blue on Competency and grey on PPE, and a
 * high priority amber on one register and red on the next.
 *
 * The tones are the Badge variants:
 *   default     -- INFORMATIONAL blue: work underway, approved-but-not-done
 *   success     -- a positive outcome or a compliant standing
 *   warning     -- attention: awaiting a decision, due soon, high priority
 *   destructive -- CRITICAL: expired, overdue, rejected, failed, critical
 *   secondary   -- NEUTRAL: drafts, archived, closed, inactive, cancelled
 *   outline     -- quiet neutral: open, medium, issued, generated
 *
 * Stored values are never renamed here; this only says how they LOOK. A value
 * missing from the map renders as `outline`, so a new status is neutral until
 * somebody decides what it means.
 */
export const STATUS_TONE = {
    // Neutral -- nothing to act on, or no longer live.
    draft: 'secondary',
    planned: 'secondary',
    on_hold: 'secondary',
    waiting: 'secondary',
    cancelled: 'secondary',
    closed: 'secondary',
    archived: 'secondary',
    inactive: 'secondary',
    dismissed: 'secondary',
    void: 'secondary',
    no_expiry: 'secondary',
    replacement_completed: 'secondary',
    low: 'secondary',

    // Quiet neutral -- live, but not asking for anything yet.
    open: 'outline',
    medium: 'outline',
    issued: 'outline',
    generated: 'outline',
    stored: 'outline',
    trial: 'outline',

    // Informational -- underway.
    in_progress: 'default',
    ongoing: 'default',
    processing: 'default',
    consolidating: 'default',
    scheduled_pickup: 'default',
    in_transit: 'default',
    replacement_approved: 'default',

    // Success -- done well, or in good standing.
    completed: 'success',
    approved: 'success',
    active: 'success',
    valid: 'success',
    in_use: 'success',
    paid: 'success',
    disposed: 'success',

    // Warning -- somebody has to look at it soon.
    submitted: 'warning',
    pending_approval: 'warning',
    verified: 'warning',
    under_review: 'warning',
    replacement_requested: 'warning',
    expiring_soon: 'warning',
    due_soon: 'warning',
    grace: 'warning',
    high: 'warning',

    // Critical -- it has already gone wrong.
    overdue: 'destructive',
    expired: 'destructive',
    critical: 'destructive',
    rejected: 'destructive',
    action_issued: 'destructive',
    lapsed: 'destructive',
    suspended: 'destructive',
};

export function statusTone(value, fallback = 'outline') {
    return STATUS_TONE[value] ?? fallback;
}
