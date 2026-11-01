import { Badge } from '@/Components/ui/badge';
import { humanizeToken } from '@/lib/humanizeToken';
import { statusTone } from '@/lib/semantics';

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
// v2.138.0: the map moved to lib/semantics.js so every page reads the same
// one (ADR 081).


export default function StatusBadge({ value, label }) {
    if (!value) return null;
    return <Badge variant={statusTone(value)}>{label || humanizeToken(value)}</Badge>;
}
