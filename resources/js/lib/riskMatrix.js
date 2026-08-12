/**
 * v1.10.9 (HSE Domain Hardening, Part H/L). ONE shared 5x5 risk matrix,
 * used by BOTH HIRADC (RiskAssessments) and JSA (JobSafetyAnalyses) --
 * not two independent implementations.
 *
 * Audit finding: HIRADC's own migration
 * (2026_08_20_100065_create_risk_assessments_table) already documented an
 * intended likelihood/severity/risk_rating/residual_rating shape, and its
 * Form.jsx already captures likelihood/severity as 1-5 integers -- but
 * NOTHING anywhere in the codebase ever computed or displayed a risk
 * score or level from them (confirmed via a repo-wide search for
 * "riskLevel"/"RISK_LEVELS"/"getRiskLevel" before this file existed --
 * zero matches). HIRADC's own risk matrix was never actually finished,
 * not just JSA's. This file closes that gap for both at once, exactly
 * the "one reusable engine" architecture requested, rather than building
 * a second one for JSA next to a still-broken one for HIRADC.
 *
 * "Computed, not stored" (the same principle already used elsewhere in
 * this codebase -- Asset::is_overdue, PurchaseOrderItem::delivered_quantity):
 * risk_rating/risk_level are NEVER written to the database. `items`/`steps`
 * JSON rows store only the two raw inputs (likelihood, severity) a human
 * actually assessed; the score and level are derived here, live, every
 * time. This means changing the matrix's band boundaries later
 * re-labels every existing record's displayed risk level automatically,
 * with no backfill migration ever required.
 *
 * Scale: 5x5 (likelihood 1-5 x severity 1-5, matching the 1-5 `min`/`max`
 * already enforced on HIRADC's own Form.jsx inputs, not invented here) --
 * standard AS/NZS 4360-style banding. Documented here as the ONE place
 * this scale is defined; do not hardcode band boundaries anywhere else.
 */

export const LIKELIHOOD_SCALE = [1, 2, 3, 4, 5];
export const SEVERITY_SCALE = [1, 2, 3, 4, 5];

export const LIKELIHOOD_LABELS = {
    1: 'Rare',
    2: 'Unlikely',
    3: 'Possible',
    4: 'Likely',
    5: 'Almost Certain',
};

export const SEVERITY_LABELS = {
    1: 'Negligible',
    2: 'Minor',
    3: 'Moderate',
    4: 'Major',
    5: 'Catastrophic',
};

// `badge` values are real `<Badge variant>` options (Components/ui/badge.jsx
// only defines default/secondary/destructive/success/outline -- no
// dedicated "warning" color exists in this design system). MEDIUM
// deliberately reuses `default` (the app's own brand color) rather than
// inventing a new variant just for this; HIGH and EXTREME both map to
// `destructive` since both are "stop and escalate" outcomes -- EXTREME is
// visually distinguished by its label text, not a 5th color.
const RISK_LEVELS = [
    { level: 'LOW', label: 'Low', max: 4, badge: 'success' },
    { level: 'MEDIUM', label: 'Medium', max: 9, badge: 'default' },
    { level: 'HIGH', label: 'High', max: 14, badge: 'destructive' },
    { level: 'EXTREME', label: 'Extreme', max: 25, badge: 'destructive' },
];

/** likelihood x severity -> integer score (1-25). Returns null if either input is missing/invalid, so a blank/incomplete row never renders a false "Low". */
export function computeRiskScore(likelihood, severity) {
    const l = Number(likelihood);
    const s = Number(severity);
    if (!l || !s || l < 1 || l > 5 || s < 1 || s > 5) return null;

    return l * s;
}

/** Returns { level, label, badge } for a given score, or null if score is null. */
export function getRiskLevel(score) {
    if (score === null || score === undefined) return null;

    return RISK_LEVELS.find((band) => score <= band.max) ?? RISK_LEVELS[RISK_LEVELS.length - 1];
}

/** Convenience: compute score + level in one call, for a job step/hazard row's initial or residual likelihood+severity pair. */
export function assessRisk(likelihood, severity) {
    const score = computeRiskScore(likelihood, severity);

    return { score, ...(getRiskLevel(score) ?? { level: null, label: '-', badge: 'secondary' }) };
}
