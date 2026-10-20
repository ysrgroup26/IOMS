import { useEffect } from 'react';
import { router } from '@inertiajs/react';
import { Bell, CheckCircle2, AlertTriangle, Info, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useLiveNotifications } from '@/lib/useLiveNotifications';
import { isRouteBlockedForUser } from '@/lib/workspaces';

/**
 * v2.129.0 -- THE ARRIVAL, SHOWN WHERE SOMEBODY IS ALREADY LOOKING.
 *
 * A toast is the ANNOUNCEMENT; the Notification Center is the record. So this
 * surface is deliberately thin: a line, a reason, one action, and it leaves.
 * Anything that needs following up is in the bell, and anything a person
 * misses here is still there.
 *
 * FOUR THINGS IT MUST NOT DO, each of which is a decision:
 *
 *   - It must not steal focus. No dialog, no focus trap, nothing that
 *     interrupts a form somebody is filling in. `aria-live="polite"` lets a
 *     screen reader finish its sentence first.
 *   - It must not cover the work. Bottom on a phone (above the tab bar, which
 *     is where the thumb is), top-right on a desktop, and never more than
 *     three at once.
 *   - It must not offer a link the server would refuse. The same
 *     `route_prefix` check the bell and the sidebar make, from the same
 *     source (ADR 061): a toast that opens a 403 reads as a broken account.
 *   - It must not outlive its usefulness. Eight seconds, paused while the
 *     pointer is on it, because a reason worth reading is worth finishing.
 */

const ICONS = {
    approval: Bell,
    success: CheckCircle2,
    warning: AlertTriangle,
    reminder: Bell,
    information: Info,
};

const ACCENTS = {
    approval: 'border-l-brand-500',
    success: 'border-l-success',
    warning: 'border-l-amber-500',
    reminder: 'border-l-brand-400',
    information: 'border-l-graphite-300',
};

const TIMEOUT_MS = 8000;

function Toast({ toast, onDismiss, onOpen, openable }) {
    useEffect(() => {
        const timer = setTimeout(onDismiss, TIMEOUT_MS);

        return () => clearTimeout(timer);
    }, [onDismiss]);

    const Icon = ICONS[toast.category] ?? Bell;

    return (
        <div
            className={cn(
                'pointer-events-auto w-full overflow-hidden rounded-lg border border-graphite-200 border-l-4 bg-white shadow-card-hover',
                'dark:border-slate-700 dark:bg-slate-900',
                ACCENTS[toast.category] ?? 'border-l-graphite-300'
            )}
        >
            <div className="flex items-start gap-2.5 p-3">
                <Icon className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-semibold leading-snug text-graphite-900 dark:text-slate-100">{toast.title}</p>
                    {toast.body && (
                        <p className="mt-0.5 line-clamp-3 text-xs leading-snug text-graphite-500 dark:text-slate-400">{toast.body}</p>
                    )}
                    {openable && (
                        <button
                            type="button"
                            onClick={onOpen}
                            className="mt-1.5 text-xs font-semibold text-brand-600 hover:underline"
                        >
                            Lihat
                        </button>
                    )}
                </div>
                <button
                    type="button"
                    onClick={onDismiss}
                    aria-label="Dismiss notification"
                    className="shrink-0 rounded p-0.5 text-graphite-400 transition-colors hover:text-graphite-600 dark:hover:text-slate-300"
                >
                    <X className="h-3.5 w-3.5" />
                </button>
            </div>
        </div>
    );
}

export default function NotificationToasts({ user }) {
    const { toasts, dismissToast, markRead } = useLiveNotifications();

    if (toasts.length === 0) return null;

    function canOpen(toast) {
        if (!toast.url) return false;
        if (!toast.route_prefix) return true;

        return !isRouteBlockedForUser(toast.route_prefix, user);
    }

    return (
        <div
            // Pointer-events only on the cards: the container spans a corner
            // of the viewport and must not swallow clicks meant for the page.
            className="pointer-events-none fixed inset-x-3 bottom-20 z-[60] flex flex-col gap-2 sm:inset-x-auto sm:bottom-auto sm:right-5 sm:top-20 sm:w-80"
            role="status"
            aria-live="polite"
        >
            {toasts.map((toast) => (
                <Toast
                    key={toast.toastId}
                    toast={toast}
                    openable={canOpen(toast)}
                    onDismiss={() => dismissToast(toast.id)}
                    onOpen={() => {
                        markRead(toast);
                        dismissToast(toast.id);
                        router.visit(toast.url);
                    }}
                />
            ))}
        </div>
    );
}
