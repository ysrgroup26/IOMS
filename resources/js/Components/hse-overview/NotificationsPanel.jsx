import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Bell, BellOff, ArrowUpRight } from 'lucide-react';

/**
 * v2.116.0 -- THE NOTIFICATIONS THE ACCOUNT ALREADY HAS.
 *
 * NO SECOND NOTIFICATION SYSTEM WAS BUILT FOR THIS PANEL, and that is the
 * most important thing about it. IOMS already has per-user
 * `notifications` rows, written by the workflow and approval engines when
 * a real event happens, already shared into every Inertia page by
 * `HandleInertiaRequests`, already rendered by the bell in the top bar,
 * and already marked read through `notifications.read`. This panel reads
 * that same shared prop. It issues no query of its own, invents no
 * approval stage, and names no recipient the server did not already
 * decide to notify.
 *
 * WHAT APPEARS HERE IS THEREFORE WHAT GENUINELY FIRED: a permit or
 * material request moving through its workflow, an approval waiting on
 * this account, and anything else the engines record. If nothing has
 * fired, the panel says so in one line rather than filling itself with
 * plausible-looking rows.
 *
 * UNREAD IS A DOT, NOT A COLOUR WASH. Marking every unread item red would
 * spend the one colour this product reserves for danger on the ordinary
 * state of a new notification, and an HSE reader scanning for red needs
 * it to mean something. Category decides the accent; unread adds a dot
 * and a slightly stronger surface.
 *
 * NO "VIEW ALL" LINK. There is no notifications index route in IOMS --
 * the full list is the top bar's own panel -- and a link to a page that
 * does not exist is worse than no link.
 */
const CATEGORY_TONE = {
    approval: { dot: 'bg-violet-500', chip: 'bg-violet-50 text-violet-700 ring-violet-200/70 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/30' },
    reminder: { dot: 'bg-blue-500', chip: 'bg-blue-50 text-blue-700 ring-blue-200/70 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/30' },
    warning: { dot: 'bg-amber-500', chip: 'bg-amber-50 text-amber-700 ring-amber-200/70 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30' },
    success: { dot: 'bg-emerald-500', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200/70 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30' },
    information: { dot: 'bg-graphite-400', chip: 'bg-graphite-100 text-graphite-600 ring-graphite-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700' },
};

/** "5m", "3h", "2d" -- compact, and never a future tense for a past row. */
function sinceLabel(iso) {
    if (!iso) return '';

    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return '';

    const seconds = Math.max(0, Math.round((Date.now() - then) / 1000));

    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;

    return new Date(iso).toLocaleDateString('en-US', { day: 'numeric', month: 'short' });
}

export default function NotificationsPanel({ limit = 5 }) {
    const { notifications } = usePage().props;

    const items = (notifications?.items ?? []).slice(0, limit);
    const unread = notifications?.unread_count ?? 0;

    return (
        <section className="flex h-full flex-col overflow-hidden surface dark:border-slate-800 dark:bg-slate-900">
            <header className="flex items-center justify-between gap-2 border-b border-graphite-100 px-4 py-3 dark:border-slate-800">
                <h2 className="flex items-center gap-1.5 text-[13px] font-bold text-graphite-900 dark:text-slate-50">
                    <Bell className="h-4 w-4 text-blue-500" /> Notifications
                </h2>

                {unread > 0 && (
                    <span className="inline-flex min-w-[20px] items-center justify-center rounded-full bg-blue-600 px-1.5 py-0.5 text-[10px] font-bold text-white">
                        {unread}
                    </span>
                )}
            </header>

            {items.length === 0 ? (
                <div className="flex flex-1 flex-col items-center justify-center gap-1.5 px-4 py-8 text-center">
                    <BellOff className="h-5 w-5 text-graphite-300 dark:text-slate-700" />
                    <p className="text-[11.5px] text-graphite-400 dark:text-slate-500">
                        Belum ada notifikasi untuk akun ini.
                    </p>
                </div>
            ) : (
                <ul className="flex-1 divide-y divide-graphite-100 overflow-y-auto dark:divide-slate-800">
                    {items.map((item) => {
                        const tone = CATEGORY_TONE[item.category] ?? CATEGORY_TONE.information;
                        const isUnread = !item.read_at;

                        const inner = (
                            <div className={cn(
                                'flex items-start gap-2.5 px-4 py-2.5 transition-colors',
                                item.url && 'hover:bg-graphite-50 dark:hover:bg-slate-800/60',
                                isUnread && 'bg-blue-50/40 dark:bg-blue-500/[0.04]'
                            )}>
                                <span className={cn('mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full', isUnread ? tone.dot : 'bg-graphite-200 dark:bg-slate-700')} />

                                <div className="min-w-0 flex-1">
                                    <p className={cn(
                                        'truncate text-[12px] leading-snug',
                                        isUnread ? 'font-semibold text-graphite-800 dark:text-slate-100' : 'text-graphite-600 dark:text-slate-300'
                                    )}>
                                        {item.title}
                                    </p>

                                    {item.body && (
                                        <p className="mt-0.5 truncate text-[11px] text-graphite-400 dark:text-slate-500">{item.body}</p>
                                    )}

                                    <p className="mt-1 flex items-center gap-1.5">
                                        <span className={cn('inline-flex rounded px-1.5 py-px text-[9.5px] font-semibold capitalize ring-1', tone.chip)}>
                                            {item.category}
                                        </span>
                                        <span className="text-[10px] text-graphite-400 dark:text-slate-500">{sinceLabel(item.created_at)}</span>
                                    </p>
                                </div>

                                {item.url && <ArrowUpRight className="mt-0.5 h-3 w-3 shrink-0 text-graphite-300 dark:text-slate-700" />}
                            </div>
                        );

                        // Only a notification the server gave a destination
                        // is navigable; the rest are read in place.
                        return (
                            <li key={item.id}>
                                {item.url ? <Link href={item.url}>{inner}</Link> : inner}
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
