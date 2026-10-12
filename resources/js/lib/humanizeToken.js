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
/**
 * v2.106.0 -- ACRONYMS ARE NOT WORDS.
 *
 * This used to capitalise the first letter of every word, which is what CSS
 * `text-transform: capitalize` does too, and both produce "Hse Inspection",
 * "Jsa", "Loto" and "Ptw" -- initialisms rendered as if they were ordinary
 * words. Those appear in statuses, activity actions and asset history all
 * over the product.
 *
 * Mirrored from `config/naming.php`, and pinned against it by
 * `ProductTerminologyTest` so the two cannot drift.
 */
const ACRONYMS = new Set([
    'HSE', 'HSSE', 'SHE', 'PPE', 'JSA', 'HIRADC', 'PTW', 'LOTO',
    'CAPA', 'TBM', 'NCR', 'RFQ', 'FPB', 'SPK', 'K3', 'P3K', 'APD',
    'HRD', 'HR', 'GA', 'IT', 'QA', 'QC', 'QHSE', 'HSEQ', 'MTC',
    'PPIC', 'SDM', 'RND', 'EHS',
    'GAJ', 'PT', 'CV', 'UD', 'PLN', 'BUMN', 'KSO',
    'ABK', 'ROV', 'NDT', 'WPS', 'PQR', 'AC', 'DC', 'HVAC',
    // v2.112.0 -- the employment-type vocabulary. Kept in step with
    // config/naming.php, which ProductTerminologyTest pins.
    'PKWTT', 'PKWT', 'PKL',
]);

export function humanizeToken(value) {
    if (value === null || value === undefined || value === '') return '';

    return String(value)
        .replace(/_/g, ' ')
        .split(' ')
        .map((word) => (ACRONYMS.has(word.toUpperCase())
            ? word.toUpperCase()
            : word.charAt(0).toUpperCase() + word.slice(1).toLowerCase()))
        .join(' ');
}

export default humanizeToken;
