import { Badge } from '@/Components/ui/badge';

/**
 * Shared Status Badge (v1.6.5 foundation). Several pages (Tasks/Show,
 * PPE) each hand-roll their own `STATUS_VARIANT`-style object mapping
 * status strings to Badge colors, duplicating the same idea slightly
 * differently. This is one canonical mapping covering the status/priority
 * vocabulary already used across the app (Task Engine statuses, PPE
 * lifecycle statuses, generic active/inactive) for new use going forward
 * -- existing per-page maps were left as-is, since they already work and
 * retrofitting them is a separate task.
 *
 * Usage:
 *   <StatusBadge value="in_progress" />
 *   <StatusBadge value="critical" />
 */
const STATUS_MAP = {
    // Task Engine / generic lifecycle
    draft: 'secondary',
    open: 'outline',
    in_progress: 'success',
    on_hold: 'secondary',
    waiting: 'secondary',
    completed: 'success',
    cancelled: 'secondary',
    // v2.4.0 (PTW UX + Field Operations pass, Part 6): PermitToWork's
    // 'closed' status fell through to the generic 'outline' default
    // (confirmed via audit -- 'active' was already mapped 'success' but
    // 'closed' never had its own entry), making a finished PTW visually
    // indistinguishable from an in-progress one on the list.
    closed: 'secondary',
    // Priority
    low: 'secondary',
    medium: 'outline',
    high: 'destructive',
    critical: 'destructive',
    // PPE lifecycle
    issued: 'outline',
    in_use: 'success',
    replacement_requested: 'secondary',
    replacement_approved: 'secondary',
    archived: 'secondary',
    expired: 'destructive',
    // Material Request workflow (v1.6.9.1) -- "pending_approval" isn't a
    // separate stored status (see the Workflow ADR for why: it's how
    // "submitted" is *labeled* while an Approval is pending, not a
    // distinct database value), but included here so any future module
    // that DOES store it literally still gets a sensible color for free.
    submitted: 'outline',
    pending_approval: 'outline',
    approved: 'success',
    rejected: 'destructive',
    processing: 'outline',
    // Generic
    active: 'success',
    inactive: 'secondary',
};

function humanize(value) {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export default function StatusBadge({ value, label }) {
    if (!value) return null;
    return <Badge variant={STATUS_MAP[value] || 'outline'}>{label || humanize(value)}</Badge>;
}
