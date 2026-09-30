import { Link, usePage } from '@inertiajs/react';
import { ShieldAlert, FileCheck2 } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * v2.86.0 -- the PTW quota warning, on the surfaces where it matters.
 *
 * SILENT UNTIL IT IS NOT. Renders nothing when the plan is unmetered, and
 * nothing while there is comfortable headroom. A banner that is always there
 * is furniture, and furniture is not read on the day it finally says
 * something different.
 *
 * It speaks twice: once when the balance goes low, so there is time to act,
 * and once when it reaches zero, where it also says what still works. That
 * second sentence is the important one. The approved model is explicit that
 * exhausting NEW PTW creation must not lock existing operational work, and a
 * field supervisor who reads "quota habis" without it will reasonably assume
 * the whole system is shut.
 *
 * Reads the shared `ptw_quota` prop, so any page can drop it in without
 * fetching anything.
 */
export default function PtwQuotaNotice({ className }) {
    const { ptw_quota: quota } = usePage().props;

    if (!quota?.metered) {
        return null;
    }

    const exhausted = quota.total === 0;

    if (!exhausted && !quota.low) {
        return null;
    }

    return (
        <div
            className={cn(
                'flex items-start gap-3 rounded-xl border px-4 py-3.5',
                exhausted ? 'border-danger/30 bg-danger-light' : 'border-warning/30 bg-warning-light',
                className
            )}
        >
            {exhausted
                ? <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0 text-danger" />
                : <FileCheck2 className="mt-0.5 h-5 w-5 shrink-0 text-warning" />}

            <div className="min-w-0">
                <p className={cn('text-sm font-semibold', exhausted ? 'text-danger' : 'text-graphite-900')}>
                    {exhausted ? 'Kuota dokumen PTW habis' : `Kuota PTW menipis, tersisa ${quota.total} dokumen`}
                </p>
                <p className="mt-1 text-sm leading-relaxed text-graphite-700">
                    {exhausted
                        ? 'PTW baru tidak dapat dibuat sampai kuota ditambah. My Work, pekerjaan yang sedang berjalan, dan PTW yang sudah ada tetap dapat diakses.'
                        : 'Tambah kuota sebelum habis agar pekerjaan lapangan tidak tertunda.'}
                </p>
                <Link
                    href={route('permits-to-work.quota')}
                    className="mt-2 inline-block text-sm font-medium text-brand-700 hover:underline"
                >
                    Lihat kuota PTW
                </Link>
            </div>
        </div>
    );
}
