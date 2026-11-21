import { router } from '@inertiajs/react';
import { FigureGroup, Figure, TrendBars, NoData, Panel } from '@/Components/shared/ManagementPanels';

/**
 * SAFETY EXECUTIVE DASHBOARD (v2.145.0).
 *
 * "How safe were our operations this month?" on one screen, for directors,
 * HSE managers and site managers. Every figure comes from
 * SafetyExecutiveService and is derived from records the team already keeps.
 * A rate whose denominator (man-hours) is missing renders as "-" with the
 * reason, never as 0.
 */

const nf = new Intl.NumberFormat('id-ID');
const df = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (v) => (v === null || v === undefined ? null : nf.format(v));
const rate = (v) => (v === null || v === undefined ? null : df.format(v));

function hours(v) {
    if (v === null || v === undefined) return null;
    if (v >= 1000000) return `${df.format(v / 1000000)} jt`;
    if (v >= 1000) return `${nf.format(Math.round(v / 1000))} rb`;
    return nf.format(Math.round(v));
}

function delta(now, before, lowerIsBetter = true) {
    if (now === null || before === null || now === undefined || before === undefined) return null;
    const d = Math.round((now - before) * 100) / 100;
    if (d === 0) return { text: 'sama dengan bulan lalu', tone: 'neutral' };
    const better = lowerIsBetter ? d < 0 : d > 0;
    return { text: `${d < 0 ? '↓' : '↑'} ${df.format(Math.abs(d))} dari bulan lalu`, tone: better ? 'good' : 'bad' };
}

const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const monthLabel = (ym) => MONTH_NAMES[Number(ym.slice(5, 7)) - 1] ?? ym;

function lastTwelveMonths() {
    const out = [];
    const d = new Date();
    d.setDate(1);
    for (let i = 0; i < 12; i += 1) {
        const ym = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
        out.push({ value: ym, label: `${MONTH_NAMES[d.getMonth()]} ${d.getFullYear()}` });
        d.setMonth(d.getMonth() - 1);
    }
    return out;
}

export function SafetyExecutiveFilters({ filters }) {
    const go = (patch) => router.get(route('management.hse'), {
        month: filters.month,
        company_id: filters.company_id || undefined,
        ...patch,
    }, { preserveScroll: true, preserveState: true, replace: true });

    const select = 'rounded-md border border-graphite-200 bg-white px-2.5 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900';

    return (
        <>
            <label className="sr-only" htmlFor="safety-month">Periode</label>
            <select id="safety-month" className={select} value={filters.month} onChange={(e) => go({ month: e.target.value })}>
                {lastTwelveMonths().map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </select>
            {filters.companies?.length > 1 && (
                <>
                    <label className="sr-only" htmlFor="safety-unit">Unit operasi</label>
                    <select
                        id="safety-unit"
                        className={select}
                        value={filters.company_id ?? ''}
                        onChange={(e) => go({ company_id: e.target.value || undefined })}
                    >
                        <option value="">All units ({filters.companies.length})</option>
                        {filters.companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                </>
            )}
        </>
    );
}

export default function SafetyExecutive({ data }) {
    if (!data) return null;
    const { lti, rates, previous_rates: prev, near_miss: nm, permits, capa, competency, inspections, units, period } = data;
    const trirDelta = delta(rates.trir, prev.trir);
    const ltifrDelta = delta(rates.ltifr, prev.ltifr);

    const trend = (data.trend?.labels ?? []).map((ym, i) => ({
        label: monthLabel(ym),
        incidents: data.trend.incidents[i],
        near_miss: data.trend.near_miss[i],
    }));
    const pyramidMax = Math.max(1, ...(data.pyramid ?? []).map((p) => p.value));

    return (
        <div className="space-y-3">
            <FigureGroup
                title={`Safety Overview · ${period.label}`}
                description="Angka keselamatan standar industri, dihitung otomatis dari Incident dan Man-Hour."
                columns={5}
            >
                <Figure
                    label="Days Without Lost Time"
                    value={lti.recorded ? num(lti.days) : null}
                    hint={lti.recorded ? `LTI terakhir ${lti.last_date}${lti.unit ? ` · ${lti.unit}` : ''}` : 'Belum ada LTI tercatat'}
                    tone="good"
                    emphasis
                    href={route('incidents.index')}
                />
                <Figure
                    label="TRIR"
                    value={rate(rates.trir)}
                    hint={rates.available ? (trirDelta?.text ?? 'per 200.000 jam kerja') : rates.unavailable_reason}
                    tone={trirDelta?.tone ?? 'neutral'}
                    emphasis
                />
                <Figure
                    label="LTIFR"
                    value={rate(rates.ltifr)}
                    hint={rates.available ? (ltifrDelta?.text ?? 'per 1.000.000 jam kerja') : 'Butuh data Man-Hour'}
                    tone={ltifrDelta?.tone ?? 'neutral'}
                    emphasis
                />
                <Figure
                    label="Safe Man-Hours"
                    value={lti.recorded ? hours(lti.safe_hours) : hours(rates.man_hours)}
                    hint={lti.recorded ? 'sejak LTI terakhir' : 'jam kerja bulan ini'}
                    emphasis
                    href={route('man-hour.index')}
                />
                <Figure
                    label="Near Misses Reported"
                    value={num(nm.count)}
                    hint={nm.ratio !== null ? `rasio 1 insiden : ${df.format(nm.ratio)} near miss` : `${nm.incidents} insiden bulan ini`}
                    tone={nm.count > 0 ? 'good' : 'neutral'}
                    emphasis
                />
            </FigureGroup>

            <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                <Panel
                    className="lg:col-span-2"
                    title="12-Month Trend: Incidents vs Near Misses"
                    description="Near miss yang naik sementara insiden turun berarti budaya lapor membaik (indikator memimpin)."
                >
                    <TrendBars
                        points={trend}
                        series={[
                            { key: 'incidents', label: 'Incidents', className: 'bg-red-500/85' },
                            { key: 'near_miss', label: 'Near Misses', className: 'bg-amber-400/90' },
                        ]}
                        empty={<NoData title="Belum ada catatan" description="Grafik terisi setelah tim mencatat insiden atau near miss." />}
                    />
                </Panel>
                <Panel title="Safety Pyramid" description="12 bulan terakhir, menurut klasifikasi pelapor.">
                    <div className="space-y-1.5">
                        {(data.pyramid ?? []).map((p, i) => (
                            <div key={p.key} className="flex items-center gap-2 text-sm">
                                <span className="w-28 shrink-0 text-graphite-500 dark:text-slate-400">{p.label}</span>
                                <div className="h-5 flex-1 rounded-sm bg-graphite-50 dark:bg-slate-800">
                                    <div
                                        className={i < 2 ? 'h-5 rounded-sm bg-red-500/80' : i < 4 ? 'h-5 rounded-sm bg-amber-400/80' : 'h-5 rounded-sm bg-brand-500/70'}
                                        style={{ width: `${p.value === 0 ? 0 : Math.max(4, (p.value / pyramidMax) * 100)}%` }}
                                    />
                                </div>
                                <span className="w-10 text-right font-semibold tabular-nums">{num(p.value)}</span>
                            </div>
                        ))}
                    </div>
                </Panel>
            </div>

            <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                <FigureGroup title="Permits to Work Today" columns={3}>
                    <Figure label="Active" value={num(permits.active)} href={route('permits-to-work.index')} />
                    <Figure label="Awaiting Approval" value={num(permits.awaiting)} tone={permits.awaiting > 0 ? 'warn' : 'neutral'} href={route('permits-to-work.index')} />
                    <Figure label="Past End Time" value={num(permits.overrun)} hint="belum ditutup" tone={permits.overrun > 0 ? 'bad' : 'good'} href={route('permits-to-work.index')} />
                </FigureGroup>
                <FigureGroup title="Corrective Actions (CAPA)" columns={3}>
                    <Figure label="Open" value={num(capa.open)} href={route('corrective-actions.index')} />
                    <Figure label="Overdue" value={num(capa.overdue)} tone={capa.overdue > 0 ? 'bad' : 'good'} href={route('corrective-actions.index')} />
                    <Figure label="Verified" value={num(capa.closed_in_period)} hint={period.label} tone="good" />
                </FigureGroup>
                <FigureGroup title="Competency & Inspections" columns={3}>
                    <Figure label="Expiring ≤30 Days" value={num(competency.expiring_soon)} tone={competency.expiring_soon > 0 ? 'warn' : 'neutral'} href={route('competency.expiring-soon')} />
                    <Figure label="Expired" value={num(competency.expired)} tone={competency.expired > 0 ? 'bad' : 'good'} href={route('competency.expiring-soon')} />
                    <Figure label="Inspections" value={num(inspections.count)} hint={inspections.failed > 0 ? `${inspections.failed} tidak lulus` : period.label} tone={inspections.failed > 0 ? 'warn' : 'neutral'} href={route('hse-inspections.index')} />
                </FigureGroup>
            </div>

            {units?.length > 0 && (
                <Panel title="Operating Unit Comparison" description={`Periode ${period.label}. TRIR per 200.000 jam kerja; "-" berarti jam kerja unit itu belum diisi.`}>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] text-sm">
                            <thead>
                                <tr className="text-left text-[11px] uppercase tracking-wide text-graphite-400">
                                    <th className="py-2 pr-3 font-medium">Unit</th>
                                    <th className="py-2 pr-3 text-right font-medium">Man-Hours</th>
                                    <th className="py-2 pr-3 text-right font-medium">Incidents</th>
                                    <th className="py-2 pr-3 text-right font-medium">LTI</th>
                                    <th className="py-2 pr-3 text-right font-medium">TRIR</th>
                                    <th className="py-2 pr-3 text-right font-medium">Near Misses</th>
                                    <th className="py-2 text-right font-medium">Overdue CAPA</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-graphite-100 dark:divide-slate-800">
                                {units.map((u) => (
                                    <tr key={u.id}>
                                        <td className="py-2 pr-3 font-medium">{u.name}</td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{num(u.man_hours === null ? null : Math.round(u.man_hours)) ?? '-'}</td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{num(u.incidents)}</td>
                                        <td className={`py-2 pr-3 text-right tabular-nums ${u.lost_time > 0 ? 'font-semibold text-red-600' : ''}`}>{num(u.lost_time)}</td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{rate(u.trir) ?? '-'}</td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{num(u.near_miss)}</td>
                                        <td className={`py-2 text-right tabular-nums ${u.capa_overdue > 0 ? 'font-semibold text-red-600' : ''}`}>{num(u.capa_overdue)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            )}
        </div>
    );
}
