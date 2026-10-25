/**
 * v2.133.0 (ADR 074) -- the register's vocabulary, in one place.
 *
 * A unit's standing is DERIVED on the server (SafetyEquipment::
 * complianceState()); this file only names and colours the states it can
 * return. The order matches the server's: worst first. Labels are English
 * (they are status labels), hints are Indonesian (they are help text).
 */
export const COMPLIANCE = {
    out_of_service: { label: 'Out of Service', tone: 'neutral', hint: 'Unit ditarik dari pemakaian dan tidak dihitung sebagai siap pakai.' },
    action_required: { label: 'Action Required', tone: 'danger', hint: 'Inspeksi terakhir menghasilkan Fail atau Needs Action. Perlu tindakan perbaikan.' },
    expired: { label: 'Expired', tone: 'danger', hint: 'Tanggal kedaluwarsa sudah lewat.' },
    overdue: { label: 'Overdue', tone: 'danger', hint: 'Inspeksi, kalibrasi, atau servis sudah lewat jatuh tempo.' },
    due_soon: { label: 'Due Soon', tone: 'warning', hint: 'Jatuh tempo dalam 30 hari ke depan.' },
    not_scheduled: { label: 'Not Scheduled', tone: 'info', hint: 'Belum ada tanggal jatuh tempo, sehingga statusnya belum bisa dinilai.' },
    compliant: { label: 'Up to Date', tone: 'success', hint: 'Semua tanggal yang berlaku masih dalam batas.' },
};

export const COMPLIANCE_ORDER = Object.keys(COMPLIANCE);

/** Tone -> classes. Restrained: a tinted chip, never a filled block of colour. */
export const TONE_CLASSES = {
    danger: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300',
    warning: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-300',
    success: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-300',
    info: 'border-steel-200 bg-steel-50 text-steel-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
    neutral: 'border-graphite-200 bg-graphite-100 text-graphite-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400',
};

export const TONE_DOT = {
    danger: 'bg-red-500', warning: 'bg-amber-500', success: 'bg-emerald-500', info: 'bg-steel-400', neutral: 'bg-graphite-400',
};

export const LIFECYCLES = {
    inspection: { label: 'Inspection', field: 'next_inspection_due', fieldLabel: 'Next Inspection Due' },
    calibration: { label: 'Calibration', field: 'next_calibration_due', fieldLabel: 'Next Calibration Due' },
    service: { label: 'Service', field: 'next_service_due', fieldLabel: 'Next Service Due' },
    expiry: { label: 'Expiry', field: 'expiry_date', fieldLabel: 'Expiry Date' },
};

export const RESULTS = {
    pass: { label: 'Pass', tone: 'success' },
    fail: { label: 'Fail', tone: 'danger' },
    needs_action: { label: 'Needs Action', tone: 'warning' },
};

export const CONDITIONS = { good: 'Good', fair: 'Fair', poor: 'Poor', damaged: 'Damaged' };

export function formatDate(value) {
    if (!value) return null;
    const d = new Date(`${String(value).slice(0, 10)}T00:00:00`);
    if (Number.isNaN(d.getTime())) return null;
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

/** Whole days from today to a date: negative when it has passed. */
export function daysFrom(value) {
    if (!value) return null;
    const d = new Date(`${String(value).slice(0, 10)}T00:00:00`);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.round((d - today) / 86400000);
}

export function relativeDue(value) {
    const days = daysFrom(value);
    if (days === null) return null;
    if (days === 0) return 'hari ini';
    return days < 0 ? `lewat ${Math.abs(days)} hari` : `${days} hari lagi`;
}
