import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { accentFor } from './accents';
import { AlertOctagon, ShieldCheck, ArrowUpRight } from 'lucide-react';

/**
 * v2.109.0 -- WHAT IS ALREADY LATE.
 *
 * The controller's `actionRequired` is a merged, date-sorted list of
 * genuinely overdue work: safety equipment past its inspection date, P3K
 * boxes past theirs, and CAPA past its due date. Every row already
 * carries a destination its own module serves.
 *
 * EVERY ITEM HERE IS OVERDUE, SO URGENCY IS HOW LATE. The controller only
 * selects records whose date is already in the past, so there is no
 * "due soon" tier to show -- inventing one would need a horizon nobody
 * configured. Instead the row states how many days late it is, which is
 * a fact the date already carries, and the severity colour follows it:
 * a week is amber, a month is red.
 *
 * THE EMPTY STATE IS SMALL ON PURPOSE. A clear list is good news and
 * should take one line, not a panel of whitespace the height of the
 * populated version.
 */
function daysLate(dateish) {
    const due = new Date(`${String(dateish).slice(0, 10)}T12:00:00`);
    if (Number.isNaN(due.getTime())) return null;

    const today = new Date();
    today.setHours(12, 0, 0, 0);

    return Math.max(0, Math.round((today - due) / 86400000));
}

function severityFor(late) {
    if (late === null) return 'slate';
    if (late >= 30) return 'red';
    if (late >= 7) return 'amber';
    return 'violet';
}

export default function PriorityActions({ items = [] }) {
    return (
        <section className="flex h-full flex-col surface dark:border-slate-800 dark:bg-slate-900">
            <header className="flex items-center justify-between gap-2 border-b border-graphite-100 px-4 py-3 dark:border-slate-800">
                <div className="min-w-0">
                    <h2 className="flex items-center gap-1.5 text-[13px] font-bold text-graphite-900 dark:text-slate-50">
                        <AlertOctagon className="h-4 w-4 text-red-500" /> Priority Actions
                    </h2>
                    <p className="mt-0.5 truncate text-[11px] text-graphite-400 dark:text-slate-500">
                        Pekerjaan yang sudah melewati batas waktu, terlama dahulu.
                    </p>
                </div>

                {items.length > 0 && (
                    <span className="shrink-0 rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-bold text-red-600 ring-1 ring-red-100 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/20">
                        {items.length}
                    </span>
                )}
            </header>

            {items.length === 0 ? (
                <div className="flex items-center gap-2.5 px-4 py-3.5">
                    <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20">
                        <ShieldCheck className="h-4 w-4" />
                    </span>
                    <p className="text-[12px] leading-snug text-graphite-600 dark:text-slate-300">
                        Tidak ada pekerjaan yang terlambat saat ini.
                    </p>
                </div>
            ) : (
                <ul className="divide-y divide-graphite-100 dark:divide-slate-800">
                    {items.map((item, index) => {
                        const late = daysLate(item.date);
                        const tone = accentFor(severityFor(late));

                        return (
                            <li key={index}>
                                <Link
                                    href={item.href}
                                    className="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-graphite-50/70 dark:hover:bg-slate-800/60"
                                >
                                    <span className={cn('h-8 w-1 shrink-0 rounded-full', tone.bar)} />

                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[12.5px] font-medium text-graphite-800 dark:text-slate-100">
                                            {item.label}
                                        </span>
                                        <span className="mt-0.5 flex flex-wrap items-center gap-1.5">
                                            <span className={cn('inline-flex rounded px-1.5 py-px text-[10px] font-semibold ring-1', tone.chip)}>
                                                {item.type}
                                            </span>
                                            <span className="text-[10.5px] text-graphite-400 dark:text-slate-500">
                                                {new Date(`${String(item.date).slice(0, 10)}T12:00:00`).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}
                                            </span>
                                        </span>
                                    </span>

                                    <span className="shrink-0 text-right">
                                        {late !== null && (
                                            <span className={cn('block text-[12px] font-bold leading-none', tone.value)}>
                                                {late}d
                                            </span>
                                        )}
                                        <span className="mt-0.5 block text-[10px] text-graphite-400 dark:text-slate-500">late</span>
                                    </span>

                                    <ArrowUpRight className="h-3.5 w-3.5 shrink-0 text-graphite-300 dark:text-slate-600" />
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
