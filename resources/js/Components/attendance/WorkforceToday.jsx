import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/**
 * v2.146.0 -- Workforce Today. One block for the HRD dashboard and the
 * Attendance register: who is here against who was scheduled, who is
 * late or not in yet, leave, overtime, a fourteen-day trend, per site,
 * and what HRD has to act on. Every number comes from AttendanceService.
 */
const nf = new Intl.NumberFormat('id-ID');
const pct = (v) => (v === null || v === undefined ? '-' : `${nf.format(Math.round(v * 100))}%`);
const hours = (m) => nf.format(Math.round((m ?? 0) / 60));

function Tile({ label, value, sub, tone }) {
    return (
        <div className="surface min-w-0 rounded-xl px-3 py-2.5">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-graphite-400">{label}</p>
            <p className={cn('mt-0.5 text-2xl font-bold tabular-nums', tone === 'bad' ? 'text-danger' : tone === 'warn' ? 'text-amber-600' : 'text-navy-900 dark:text-slate-50')}>{value}</p>
            {sub && <p className="text-[11px] text-graphite-500">{sub}</p>}
        </div>
    );
}

export default function WorkforceToday({ summary, registerHref }) {
    if (!summary) return null;
    const s = summary;
    const max = Math.max(1, ...s.trend.map((t) => t.scheduled));
    const actions = [
        { label: 'Absen 3 hari berturut-turut', value: s.absent_streak, tone: 'bad' },
        { label: 'Lupa absen pulang kemarin', value: s.open_yesterday, tone: 'warn' },
        { label: 'Lembur menunggu persetujuan', value: `${hours(s.overtime_pending_minutes)} jam`, raw: s.overtime_pending_minutes, tone: 'warn' },
    ];

    return (
        <section aria-labelledby="workforce-today" className="space-y-3">
            <div className="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 id="workforce-today" className="text-base font-bold text-navy-900 dark:text-slate-50">Workforce Today</h2>
                    <p className="text-xs text-graphite-500">Kehadiran dibandingkan jadwal roster dan shift hari ini.</p>
                </div>
                {registerHref && <Link href={registerHref} className="text-sm font-semibold text-brand-600 hover:underline">Open Attendance</Link>}
            </div>

            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                <Tile label="Present" value={`${nf.format(s.present)} / ${nf.format(s.scheduled)}`} sub={`${pct(s.rate)} dari yang terjadwal`} />
                <Tile label="Late" value={nf.format(s.late)} sub="lebih dari 15 menit" tone={s.late > 0 ? 'warn' : null} />
                <Tile label="Not Checked In" value={nf.format(s.not_in)} sub="shift sudah mulai" tone={s.not_in > 0 ? 'bad' : null} />
                <Tile label="Leave / Sick / Permit" value={nf.format(s.leave + s.sick + s.permit)} sub={`${s.leave} cuti, ${s.sick} sakit, ${s.permit} izin`} />
                <Tile label="Overtime This Week" value={`${hours(s.overtime_week_minutes)} jam`} sub={`${hours(s.overtime_pending_minutes)} jam menunggu persetujuan`} />
            </div>

            <div className="grid gap-3 lg:grid-cols-3">
                <div className="surface min-w-0 rounded-xl p-3 lg:col-span-2">
                    <p className="mb-2 text-xs font-semibold text-graphite-600">Attendance, last 14 days</p>
                    <div className="flex h-28 items-end gap-1" role="img" aria-label="Persentase kehadiran 14 hari terakhir">
                        {s.trend.map((t) => (
                            <div key={t.date} className="flex min-w-0 flex-1 flex-col items-center justify-end gap-1" title={`${t.date}: ${t.present} dari ${t.scheduled} hadir`}>
                                <div className="relative w-full max-w-[22px] rounded-t-sm bg-graphite-100 dark:bg-slate-800" style={{ height: `${(t.scheduled / max) * 100}%` }}>
                                    <div className="absolute bottom-0 w-full rounded-t-sm bg-brand-500/80" style={{ height: t.scheduled ? `${(t.present / t.scheduled) * 100}%` : 0 }} />
                                </div>
                                <span className="text-[9px] tabular-nums text-graphite-400">{t.date.slice(8)}</span>
                            </div>
                        ))}
                    </div>
                    <p className="mt-1 text-[11px] text-graphite-400">Batang abu-abu = terjadwal, biru = hadir.</p>
                </div>
                <div className="surface min-w-0 rounded-xl p-3">
                    <p className="mb-2 text-xs font-semibold text-graphite-600">Needs HRD Action</p>
                    <ul className="space-y-1.5 text-sm">
                        {actions.map((a) => {
                            const n = a.raw ?? a.value;
                            return (
                                <li key={a.label} className="flex items-center justify-between gap-2 rounded-lg bg-graphite-50 px-2.5 py-1.5 dark:bg-slate-800/60">
                                    <span className="min-w-0">{a.label}</span>
                                    <span className={cn('shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums', !n ? 'bg-emerald-100 text-emerald-700' : a.tone === 'bad' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700')}>{a.value}</span>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            </div>

            {s.by_site.length > 0 && (
                <div className="surface overflow-x-auto rounded-xl">
                    <table className="w-full min-w-[560px] text-sm">
                        <thead>
                            <tr className="text-left text-[11px] uppercase tracking-wide text-graphite-400">
                                <th className="px-3 py-2 font-medium">Site</th>
                                <th className="px-3 py-2 text-right font-medium">Scheduled</th>
                                <th className="px-3 py-2 text-right font-medium">Present</th>
                                <th className="px-3 py-2 text-right font-medium">Late</th>
                                <th className="px-3 py-2 text-right font-medium">Not In</th>
                                <th className="px-3 py-2 text-right font-medium">Hours</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-graphite-100 dark:divide-slate-800">
                            {s.by_site.map((r) => (
                                <tr key={r.site}>
                                    <td className="px-3 py-2 font-medium">{r.site}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{nf.format(r.scheduled)}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{nf.format(r.present)}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{nf.format(r.late)}</td>
                                    <td className={cn('px-3 py-2 text-right tabular-nums', r.not_in > 0 && 'font-semibold text-danger')}>{nf.format(r.not_in)}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{nf.format(r.hours)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
