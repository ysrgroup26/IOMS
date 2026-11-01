import { useState } from 'react';
import StatusBadge from '@/Components/shared/StatusBadge';
import { ArrowRight, History } from 'lucide-react';

/**
 * v2.141.0 -- A13: WHAT CHANGED ON A RECORD, BEFORE AND AFTER.
 *
 * Renders `RecordChange::historyFor()` -- the append-only audit trail --
 * beside the human-readable Activity timeline. It is read-only by design:
 * nothing in IOMS edits or deletes an audit row, so this component offers
 * no action at all. Newest first; the first five entries show, the rest on
 * request, so a long-lived permit does not push the page into a scroll.
 */
const EVENT_LABEL = { created: 'Created', updated: 'Updated', deleted: 'Deleted', restored: 'Restored' };

const fmt = (value) =>
    value ? new Date(value).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';

export default function RecordHistory({ entries = [] }) {
    const [expanded, setExpanded] = useState(false);

    if (entries.length === 0) {
        return <p className="text-sm text-graphite-500">Belum ada perubahan yang tercatat untuk dokumen ini.</p>;
    }

    const shown = expanded ? entries : entries.slice(0, 5);

    return (
        <div>
            <ol className="space-y-3">
                {shown.map((entry) => (
                    <li key={entry.id} className="rounded-lg border border-steel-100 p-3">
                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                            <History className="h-3.5 w-3.5 text-graphite-400" aria-hidden="true" />
                            <span className="font-bold text-navy-900">{EVENT_LABEL[entry.event] ?? entry.event}</span>
                            {entry.from_status && entry.to_status && (
                                <span className="flex items-center gap-1">
                                    <StatusBadge value={entry.from_status} />
                                    <ArrowRight className="h-3 w-3 text-graphite-400" aria-hidden="true" />
                                    <StatusBadge value={entry.to_status} />
                                </span>
                            )}
                            <span className="text-graphite-500">
                                {entry.actor}
                                {entry.actor_title ? ` · ${entry.actor_title}` : ''} · {fmt(entry.at)}
                            </span>
                        </div>

                        {entry.event === 'updated' && entry.fields.length > 0 && (
                            <dl className="mt-2 space-y-1.5">
                                {entry.fields.map((change) => (
                                    <div key={change.field} className="grid gap-0.5 text-xs sm:grid-cols-[10rem_1fr]">
                                        <dt className="font-semibold text-graphite-600">{change.field}</dt>
                                        <dd className="min-w-0 break-words text-graphite-700">
                                            <span className="text-graphite-400 line-through">{change.before ?? '-'}</span>
                                            <ArrowRight className="mx-1 inline h-3 w-3 text-graphite-400" aria-hidden="true" />
                                            <span className="font-medium text-navy-900">{change.after ?? '-'}</span>
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </li>
                ))}
            </ol>

            {entries.length > 5 && (
                <button
                    type="button"
                    onClick={() => setExpanded((value) => !value)}
                    className="mt-3 min-h-[44px] text-xs font-semibold text-brand-700 hover:underline"
                >
                    {expanded ? 'Show less' : `Show all ${entries.length}`}
                </button>
            )}
        </div>
    );
}
