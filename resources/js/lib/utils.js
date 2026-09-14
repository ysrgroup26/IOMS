import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs) {
    return twMerge(clsx(inputs));
}

export function formatNumber(n) {
    return new Intl.NumberFormat('en-US').format(n ?? 0);
}

export const MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

/**
 * v2.69.0 -- how a disciplinary action type is written for a reader.
 *
 * Here rather than in each page because it is used on the case record and
 * on the employee profile, and the two must not drift: `sp1` has to read
 * as "SP 1" in both, since that is the document the company actually
 * issues (Surat Peringatan I). A generic humanize() renders it "Sp1",
 * which matches nothing on paper.
 */
export function disciplinaryActionLabel(type) {
    if (!type) return '';

    const match = String(type).match(/^sp([123])$/);
    if (match) return `SP ${match[1]}`;

    return String(type).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
