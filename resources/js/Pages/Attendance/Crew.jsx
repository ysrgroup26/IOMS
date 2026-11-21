import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';
import { STATE_LABEL } from '@/Components/attendance/labels';

/**
 * v2.146.0 -- Crew Roll Call. The foreman marks the whole crew from the
 * day's roster in one go. Approved leave is pre-filled; a worker who
 * already checked in on My Work is shown and never overwritten.
 */
const CHOICES = [
    { value: 'present', short: 'H', label: 'Present' },
    { value: 'late', short: 'T', label: 'Late' },
    { value: 'sick', short: 'S', label: 'Sick' },
    { value: 'permit', short: 'I', label: 'Permit' },
    { value: 'absent', short: 'A', label: 'Absent' },
];
const ON = { present: 'bg-emerald-600 text-white border-emerald-600', late: 'bg-amber-500 text-white border-amber-500', sick: 'bg-sky-600 text-white border-sky-600', permit: 'bg-sky-600 text-white border-sky-600', absent: 'bg-red-600 text-white border-red-600' };

export default function AttendanceCrew({ date, today, rows, sites, filters, companies }) {
    const initial = {};
    rows.forEach((r) => {
        if (r.source === 'self' && r.check_in) return;
        if (['present', 'late', 'sick', 'permit', 'absent', 'leave'].includes(r.state)) initial[r.employee_id] = r.state;
    });
    const [marks, setMarks] = useState(initial);
    const [saving, setSaving] = useState(false);
    const go = (patch) => router.get(route('attendance.crew'), { ...filters, ...patch }, { preserveState: false, replace: true });
    const editable = rows.filter((r) => !(r.source === 'self' && r.check_in));
    const markAll = () => setMarks((m) => { const n = { ...m }; editable.forEach((r) => { if (!n[r.employee_id]) n[r.employee_id] = 'present'; }); return n; });
    const count = Object.keys(marks).length;

    function submit() {
        setSaving(true);
        router.post(route('attendance.crew.store'), { work_date: date, entries: marks, company_id: filters.company_id }, {
            preserveScroll: true, onFinish: () => setSaving(false),
        });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Crew Roll Call" />
            <PageHeader title="Crew Roll Call" subtitle="Tandai kehadiran satu regu sekaligus dari roster hari itu. Absen mandiri pekerja di My Work tidak ditimpa.">
                <Button asChild variant="outline"><Link href={route('attendance.index', { date })}>Back to Attendance</Link></Button>
            </PageHeader>

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Input aria-label="Date" type="date" className="w-44" max={today} value={date} onChange={(e) => go({ date: e.target.value })} />
                {sites.length > 0 && (
                    <select aria-label="Site" className="rounded-md border border-graphite-200 bg-white px-2.5 py-2 text-sm dark:border-slate-700 dark:bg-slate-900"
                        value={filters.site ?? ''} onChange={(e) => go({ site: e.target.value || undefined })}>
                        <option value="">Semua site</option>
                        {sites.map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                )}
                {companies.length > 1 && (
                    <select aria-label="Operating unit" className="rounded-md border border-graphite-200 bg-white px-2.5 py-2 text-sm dark:border-slate-700 dark:bg-slate-900"
                        value={filters.company_id ?? ''} onChange={(e) => go({ company_id: e.target.value || undefined })}>
                        <option value="">Semua unit</option>
                        {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                )}
                <Button variant="outline" onClick={markAll} disabled={editable.length === 0}>Mark Rest Present</Button>
            </div>

            {rows.length === 0 ? (
                <p className="surface rounded-xl p-6 text-sm text-graphite-500">Tidak ada orang yang terjadwal pada tanggal ini. Jadwal diambil dari Shift &amp; Roster.</p>
            ) : (
                <ul className="surface divide-y divide-graphite-100 rounded-xl dark:divide-slate-800">
                    {rows.map((r) => {
                        const self = r.source === 'self' && r.check_in;
                        return (
                            <li key={r.employee_id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5">
                                <div className="min-w-0">
                                    <p className="font-medium">{r.name}</p>
                                    <p className="text-[11px] text-graphite-400">
                                        {r.shift ? `${r.shift.name} ${r.shift.start}-${r.shift.end}` : 'Tanpa shift'}{r.site_name ? ` · ${r.site_name}` : ''}
                                    </p>
                                </div>
                                {self ? (
                                    <span className="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">Checked in {r.check_in} via My Work</span>
                                ) : marks[r.employee_id] === 'leave' ? (
                                    <span className="rounded-full bg-violet-100 px-2.5 py-1 text-xs font-semibold text-violet-700">{STATE_LABEL.leave}</span>
                                ) : (
                                    <div className="flex gap-1" role="radiogroup" aria-label={`Kehadiran ${r.name}`}>
                                        {CHOICES.map((c) => (
                                            <button key={c.value} type="button" role="radio" aria-checked={marks[r.employee_id] === c.value} title={c.label}
                                                onClick={() => setMarks((m) => ({ ...m, [r.employee_id]: c.value }))}
                                                className={cn('h-9 w-9 rounded-lg border text-sm font-bold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                                                    marks[r.employee_id] === c.value ? ON[c.value] : 'border-graphite-200 text-graphite-500 dark:border-slate-700')}>
                                                {c.short}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}

            <div className="sticky bottom-3 mt-4 flex justify-end">
                <Button size="lg" onClick={submit} disabled={saving || count === 0}>Save Roll Call ({count})</Button>
            </div>
            <p className="mt-2 text-xs text-graphite-500">H hadir, T terlambat, S sakit, I izin, A tanpa keterangan. Hadir dihitung sesuai jam shift dan langsung mengisi Man-Hour.</p>
        </AuthenticatedLayout>
    );
}
