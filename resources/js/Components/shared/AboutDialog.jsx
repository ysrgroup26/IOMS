import { usePage } from '@inertiajs/react';
import { CheckCircle2, Calendar, Code2, ShieldCheck, Globe, LifeBuoy, BookOpen } from 'lucide-react';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription,
} from '@/Components/ui/dialog';
import BrandIcon from '@/Components/shared/BrandIcon';
import BrandWordmark from '@/Components/shared/BrandWordmark';

/**
 * The official product identity page. Rebuilt from scratch (v1.6.5) --
 * this issue was reported broken across four consecutive sessions
 * despite legitimate, verified fixes each time (a z-index conflict, then
 * dark-mode text colors, then a stateless custom animation), and none of
 * it held up under real browser QA. Rather than add a fifth theory on
 * top of the same file, this is a deliberate rewrite from a blank slate:
 * no decorative watermark inside the dialog, no nested z-index wrapper
 * divs, no custom positioning tricks of any kind -- just the plain
 * Radix Dialog primitive (open/close/overlay/content) with ordinary
 * static content on top. If this still doesn't render, the bug is
 * conclusively in the shared `dialog.jsx` primitive or the Radix/React
 * version itself, not in anything content-specific this component was
 * doing -- which narrows any future investigation considerably.
 *
 * All version/release data comes from the shared `version` Inertia prop
 * (see HandleInertiaRequests::share(), reading config/ioms.php) -- a
 * release bump is a one-file edit, nothing here is hardcoded.
 */
export default function AboutDialog({ open, onOpenChange }) {
    const { version } = usePage().props;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] max-w-md overflow-y-auto">
                <DialogHeader className="items-center text-center">
                    <BrandIcon className="h-14 w-14" />
                    <BrandWordmark className="mt-3 h-8 w-auto" />
                    <DialogTitle className="mt-3 text-sm font-medium text-graphite-600 dark:text-slate-300">
                        IOMS &mdash; Industrial Operations Platform
                    </DialogTitle>
                    <span className="mt-1 rounded-full bg-graphite-100 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-graphite-500 dark:bg-slate-800 dark:text-slate-400">
                        {version?.edition}
                    </span>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="flex items-center justify-center gap-4 rounded-xl border border-graphite-100 bg-graphite-50/60 py-3 dark:border-slate-700 dark:bg-slate-800/60">
                        <div className="text-center">
                            <p className="text-[11px] uppercase tracking-wide text-graphite-400 dark:text-slate-500">Version</p>
                            <p className="text-sm font-semibold text-graphite-800 dark:text-slate-100">
                                v{version?.number}{version?.stage && <span className="ml-1 text-xs font-medium text-brand-600 dark:text-brand-400">{version.stage}</span>}
                            </p>
                        </div>
                        <div className="h-8 w-px bg-graphite-200 dark:bg-slate-700" />
                        <div className="text-center">
                            <p className="text-[11px] uppercase tracking-wide text-graphite-400 dark:text-slate-500">Build</p>
                            <p className="text-sm font-semibold text-graphite-800 dark:text-slate-100">{version?.build}</p>
                        </div>
                        <div className="h-8 w-px bg-graphite-200 dark:bg-slate-700" />
                        <div className="text-center">
                            <p className="flex items-center justify-center gap-1 text-[11px] uppercase tracking-wide text-graphite-400 dark:text-slate-500">
                                <Calendar className="h-3 w-3" /> Released
                            </p>
                            <p className="text-sm font-medium text-graphite-700 dark:text-slate-300">
                                {version?.release_date && new Date(version.release_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}
                            </p>
                        </div>
                    </div>

                    <DialogDescription className="text-center">
                        A premium enterprise operations platform for HSE, Projects, PPE, and daily reporting
                        across Shipyard, Mining, Construction, Manufacturing, Oil &amp; Gas, and Energy.
                    </DialogDescription>

                    <dl className="divide-y divide-graphite-100 rounded-xl border border-graphite-100 dark:divide-slate-800 dark:border-slate-700">
                        <Row icon={Code2} label="Designed & Developed by" value={version?.company} />
                        <Row label="Backend" value="Laravel 12" />
                        <Row label="Frontend" value="React + Inertia.js" />
                        <Row label="Copyright" value={`© ${version?.copyright_year} ${version?.company}. All Rights Reserved.`} />
                    </dl>

                    <dl className="divide-y divide-graphite-100 rounded-xl border border-graphite-100 dark:divide-slate-800 dark:border-slate-700">
                        <Row icon={ShieldCheck} label="License" value={version?.license} />
                        <Row icon={Globe} label="Website" value={version?.website} />
                        <Row icon={LifeBuoy} label="Support" value={version?.support_email} />
                        <Row icon={BookOpen} label="Documentation" value={version?.documentation_url} />
                    </dl>

                    {version?.whats_new?.length > 0 && (
                        <div>
                            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-graphite-400 dark:text-slate-500">What's New</p>
                            <ul className="space-y-1.5 rounded-xl border border-graphite-100 p-3 dark:border-slate-700">
                                {version.whats_new.map((item, i) => (
                                    <li key={i} className="flex items-start gap-2 text-sm text-graphite-700 dark:text-slate-300">
                                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" />
                                        {item}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {version?.history?.length > 0 && (
                        <div>
                            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-graphite-400 dark:text-slate-500">Version History</p>
                            <ul className="divide-y divide-graphite-100 rounded-xl border border-graphite-100 dark:divide-slate-800 dark:border-slate-700">
                                {version.history.map((entry) => (
                                    <li key={entry.version} className="px-3 py-2.5 text-sm">
                                        <div className="flex items-center justify-between">
                                            <span className="font-semibold text-graphite-800 dark:text-slate-100">v{entry.version}</span>
                                            <span className="text-xs text-graphite-400 dark:text-slate-500">
                                                {new Date(entry.date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}
                                            </span>
                                        </div>
                                        <p className="mt-0.5 text-xs text-graphite-500 dark:text-slate-400">{entry.summary}</p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

function Row({ icon: Icon, label, value }) {
    return (
        <div className="flex items-center justify-between px-4 py-2.5 text-sm">
            <dt className="flex items-center gap-1.5 text-graphite-500 dark:text-slate-400">
                {Icon && <Icon className="h-3.5 w-3.5" />}
                {label}
            </dt>
            <dd className="font-medium text-graphite-800 dark:text-slate-200">{value}</dd>
        </div>
    );
}
