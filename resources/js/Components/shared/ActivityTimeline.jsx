import { History } from 'lucide-react';

const ACTION_COLOR = {
    created: 'text-graphite-500', updated: 'text-graphite-500',
    submitted: 'text-amber-600', approved: 'text-emerald-600', rejected: 'text-red-600',
};

/**
 * Activity Timeline (v1.6.9). The recording mechanism (`ActivityLog`)
 * already existed and was already used 32+ times across controllers
 * before this version -- what genuinely didn't exist anywhere was a way
 * to actually view it. This component is that missing piece: any page
 * that already eager-loads its own list of `ActivityLog` rows for a
 * given subject can drop this in, the same way MaterialRequests/Show.jsx
 * does. Deliberately just a list, not a fetch-its-own-data component --
 * keeping the query on the backend page controller (where the subject is
 * already known) rather than adding a second round-trip here.
 */
export default function ActivityTimeline({ activities }) {
    if (!activities || activities.length === 0) {
        return (
            <div className="flex flex-col items-center gap-1.5 py-6 text-center">
                <History className="h-5 w-5 text-graphite-300" />
                <p className="text-xs text-graphite-400">No activity recorded yet.</p>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {activities.map((a) => (
                <div key={a.id} className="flex items-start gap-2.5 text-[13px]">
                    <div className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-graphite-300" />
                    <div className="min-w-0">
                        <p className={ACTION_COLOR[a.action] || 'text-graphite-600'}>
                            <span className="font-medium text-graphite-800">{a.user?.name || 'System'}</span> {a.description}
                        </p>
                        <p className="text-[11px] text-graphite-400">
                            {new Date(a.created_at).toLocaleString('en-US', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' })}
                        </p>
                    </div>
                </div>
            ))}
        </div>
    );
}
