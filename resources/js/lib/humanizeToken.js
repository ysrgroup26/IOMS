/**
 * v2.100.0 -- A STORED IDENTIFIER IS NOT SOMETHING TO SHOW A PERSON.
 *
 * Statuses, workflow actions and type keys are stored snake_case because
 * that is what a column holds: `under_maintenance`, `in_progress`,
 * `pending_approval`. `StatusBadge` has always turned those into words
 * before rendering them, but it is not the only place they surface --
 * activity feeds render `ActivityLog.action`, and an asset's history
 * renders its previous and new status -- and each of those was printing
 * the raw identifier, underscores and all.
 *
 * Exported from its own module rather than from `StatusBadge` so that
 * using it does not mean importing a badge, and so there is exactly one
 * definition of what "readable" means for these values.
 */
export function humanizeToken(value) {
    if (value === null || value === undefined || value === '') return '';

    return String(value)
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

export default humanizeToken;
