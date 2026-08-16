import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { ShieldAlert, FileQuestion, Clock, AlertTriangle, ServerCrash } from 'lucide-react';

/**
 * v1.11.3.2 (production UX fix, Part 3). Rendered by bootstrap/app.php's
 * exception handler for 401/403/404/419/429 in every environment and
 * 500/503 in production -- replacing Laravel's default plain-text error
 * response, which broke out of the Inertia SPA with no navigation and no
 * way back. This is purely presentational: it never weakens the check
 * that produced the status code (RestrictDepartmentAccess,
 * EnforceTenantEntitlement, and every other abort()/abort_unless() in
 * this codebase are completely untouched).
 *
 * Deliberately standalone, NOT wrapped in AuthenticatedLayout -- a 401/419
 * can legitimately happen for a guest or a just-expired session, where
 * AuthenticatedLayout's own assumptions (sidebar, workspace switcher)
 * don't apply. `auth.user` is always shared (nullable) by
 * HandleInertiaRequests, so this reads it directly rather than depending
 * on a layout that might not fit the situation that produced the error.
 */
const STATUS_META = {
    401: { icon: ShieldAlert, title: "You're not signed in", fallback: 'Please sign in to continue.' },
    403: { icon: ShieldAlert, title: 'Access denied', fallback: "You don't have access to this department or module." },
    404: { icon: FileQuestion, title: 'Page not found', fallback: "The page you're looking for doesn't exist or may have moved." },
    419: { icon: Clock, title: 'Session expired', fallback: 'Your session expired. Please refresh and try again.' },
    429: { icon: AlertTriangle, title: 'Too many requests', fallback: 'Please wait a moment and try again.' },
    500: { icon: ServerCrash, title: 'Something went wrong', fallback: 'An unexpected error occurred. Our team has been notified.' },
    503: { icon: ServerCrash, title: 'Temporarily unavailable', fallback: 'The system is temporarily unavailable. Please try again shortly.' },
};

export default function ErrorsShow({ status, message }) {
    const { auth } = usePage().props;
    const meta = STATUS_META[status] ?? STATUS_META[500];
    const Icon = meta.icon;

    // "This page belongs to a different department." (RestrictDepartmentAccess)
    // is technically accurate but reads as jargon out of context -- shown
    // as supporting detail under the clearer headline, not replacing it.
    const detail = message && message !== 'Server Error' ? message : meta.fallback;

    return (
        <div className="flex min-h-screen items-center justify-center bg-graphite-50 px-4 dark:bg-slate-950">
            <Head title={`${status} — ${meta.title}`} />
            <div className="w-full max-w-sm rounded-2xl border border-graphite-200 bg-white p-6 text-center shadow-card dark:border-slate-800 dark:bg-slate-900">
                <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-400">
                    <Icon className="h-6 w-6" />
                </div>
                <p className="mt-3 text-xs font-semibold uppercase tracking-wide text-graphite-400">Error {status}</p>
                <h1 className="mt-1 text-lg font-bold text-graphite-900 dark:text-slate-50">{meta.title}</h1>
                <p className="mt-2 text-sm text-graphite-500 dark:text-slate-400">{detail}</p>

                <div className="mt-6 flex flex-col gap-2">
                    {auth?.user ? (
                        <Link href={route('dashboard')}><Button className="w-full">Back to Dashboard</Button></Link>
                    ) : (
                        <Link href={route('login')}><Button className="w-full">Go to Login</Button></Link>
                    )}
                </div>
            </div>
        </div>
    );
}
