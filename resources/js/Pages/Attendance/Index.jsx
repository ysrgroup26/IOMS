import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import WorkforceToday from '@/Components/attendance/WorkforceToday';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/Components/ui/dialog';
import { Settings, Users, Pencil, Check, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { STATE_LABEL, STATE_TONE } from '@/Components/attendance/labels';

/**
 * v2.146.0 -- Attendance register (People / HRD).
 *
 * One row per person scheduled today or with a record. HRD corrects a day
 * with a mandatory reason (logged in Activity); supervisors decide pending
 * overtime, which reaches Man-Hour only once approved.
 */
const SOURCE_LABEL = { self: 'My Work', crew: 'Crew', hr: 'HRD' };
const nf = new Intl.NumberFormat('id-ID');
const hm = (m) => (m ? `${Math.floor(m / 60)}j ${m % 60}m` : '-');

function CorrectionDialog({ open, onClose, row, date, employees, statuses }) {
    const form = useForm({
        employee_id: row?.employee_id ?? '',
        work_date: date,
        status: row && !['not_in', 'upcoming'].includes(row.state) ? row.state : 'present',
        check_in: row?.check_in ?? '',
        check_out: row?.check_out ?? '',
        reason: '',
    });

    function submit(e) {
        e.preventDefault();
        form.post(route('attendance.correct'), { preserveScroll: true, onSuccess: () => { form.reset('reason'); onClose(); } });
    }

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Correct Attendance</DialogTitle>
                    <DialogDescription>Koreksi mengubah jam kerja di Man-Hour. Alasan wajib dan tercatat di riwayat aktivitas.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    {!row && (
                        <div>
                            <Label htmlFor="corr-employee">Employee</Label>
                            <select id="corr-employee" className="mt-1 w-full rounded-md border border-graphite-200 bg-white px-2.5 py-2 text-sm dark:border-slate-700 dark:bg-slate-900"
                                value={form.data.employee_id} onChange={(e) => form.setData('employee_id', e.target.value)}>
                                <option value="">Pilih karyawan</option>
                                {employees.map((e) => <option key={e.id} value={e.id}>{e.full_name} ({e.employee_id})</option>)}
                            </select>
                        </div>
                    )}
                    {row && <p className="text-sm font-semibold">{row.name} · {date}</p>}
                    <div className="grid grid-cols-3 gap-2">
                        <div>
                            <Label htmlFor="corr-status">Status</Label>
                            <select id="corr-status" className="mt-1 w-full rounded-md border border-graphite-200 bg-white px-2 py-2 text-sm dark:border-slate-700 dark:bg-slate-900"
                                value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                {statuses.map((s) => <option key={s} value={s}>{STATE_LABEL[s]}</option>)}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="corr-in">Check In</Label>
                            <Input id="corr-in" type="time" value={form.data.check_in} onChange={(e) => form.setData('check_in', e.target.value)} />
                        </div>
                        <div>
                            <Label htmlFor="corr-out">Check Out</Label>
                            <Input id="corr-out" type="time" value={form.data.check_out} onChange={(e) => form.setData('check_out', e.target.value)} />
                        </div>
                    </div>
                    <p className="text-[11px] text-graphite-400">Kosongkan jam bila hadir penuh sesuai shift. Jam pulang lebih awal dari jam masuk dibaca sebagai hari berikutnya (shift malam).</p>
                    <div>
                        <Label htmlFor="corr-reason">Reason</Label>
                        <Textarea id="corr-reason" rows={3} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)}
                            placeholder="Contoh: lupa absen pulang, dikonfirmasi foreman Regu Las B." />
                    </div>
                    {Object.values(form.errors).length > 0 && (
                        <p role="alert" className="rounded-md bg-danger/10 px-3 py-2 text-sm text-danger">{Object.values(form.errors)[0]}</p>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Save Correction</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function AttendanceIndex({ date, today, rows, summary, pendingOvertime, employees, companies, filters, statuses, can }) {
    const [editing, setEditing] = useState(null);
    const go = (patch) => router.get(route('attendance.index'), { ...filters, ...patch }, { preserveState: true, replace: true });
    const decide = (id, approve) => router.post(route('attendance.overtime', id), { approve }, { preserveScroll: true });

    return (
        <AuthenticatedLayout>
            <Head title="Attendance" />
            <PageHeader title="Attendance" subtitle="Absensi harian dari My Work, absen regu, dan koreksi HRD. Jam kerja otomatis mengisi Man-Hour.">
                <div className="flex flex-wrap gap-2">
                    {can.crew && <Button asChild variant="outline"><Link href={route('attendance.crew', { date })}><Users className="h-4 w-4" /> Crew Roll Call</Link></Button>}
                    {can.manage && <Button asChild variant="outline"><Link href={route('attendance.settings')}><Settings className="h-4 w-4" /> Settings</Link></Button>}
                    {can.manage && <Button onClick={() => setEditing({ row: null })}><Pencil className="h-4 w-4" /> Add Correction</Button>}
                </div>
            </PageHeader>

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <label className="sr-only" htmlFor="att-date">Date</label>
                <Input id="att-date" type="date" className="w-44" max={today} value={date} onChange={(e) => go({ date: e.target.value })} />
                {companies.length > 1 && (
                    <select aria-label="Operating unit" className="rounded-md border border-graphite-200 bg-white px-2.5 py-2 text-sm dark:border-slate-700 dark:bg-slate-900"
                        value={filters.company_id ?? ''} onChange={(e) => go({ company_id: e.target.value || undefined })}>
                        <option value="">Semua unit ({companies.length})</option>
                        {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                )}
            </div>

            <WorkforceToday summary={summary} />

            {can.approve_overtime && pendingOvertime.length > 0 && (
                <section className="surface mt-5 rounded-xl p-3">
                    <h2 className="mb-2 text-sm font-bold">Overtime Awaiting Approval</h2>
                    <p className="mb-2 text-xs text-graphite-500">Lembur baru masuk Man-Hour setelah disetujui. Lembur dihitung dari jam kerja di atas durasi shift (minimal 30 menit).</p>
                    <ul className="divide-y divide-graphite-100 dark:divide-slate-800">
                        {pendingOvertime.map((o) => (
                            <li key={o.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <span className="min-w-0"><b>{o.name}</b> · {o.date} · lembur {hm(o.overtime_minutes)} dari {hm(o.worked_minutes)} kerja</span>
                                <span className="flex gap-2">
                                    <Button size="sm" onClick={() => decide(o.id, true)}><Check className="h-3.5 w-3.5" /> Approve</Button>
                                    <Button size="sm" variant="outline" onClick={() => decide(o.id, false)}><X className="h-3.5 w-3.5" /> Reject</Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section className="surface mt-5 overflow-x-auto rounded-xl">
                {rows.length === 0 ? (
                    <p className="p-6 text-sm text-graphite-500">Belum ada jadwal maupun absensi pada tanggal ini. Atur shift atau roster di Shift &amp; Roster agar daftar orang yang terjadwal muncul di sini.</p>
                ) : (
                    <table className="w-full min-w-[760px] text-sm">
                        <thead>
                            <tr className="text-left text-[11px] uppercase tracking-wide text-graphite-400">
                                <th className="px-3 py-2 font-medium">Employee</th>
                                <th className="px-3 py-2 font-medium">Shift</th>
                                <th className="px-3 py-2 font-medium">Status</th>
                                <th className="px-3 py-2 font-medium">In</th>
                                <th className="px-3 py-2 font-medium">Out</th>
                                <th className="px-3 py-2 text-right font-medium">Worked</th>
                                <th className="px-3 py-2 font-medium">Source</th>
                                {can.manage && <th className="px-3 py-2"><span className="sr-only">Actions</span></th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-graphite-100 dark:divide-slate-800">
                            {rows.map((r) => (
                                <tr key={r.employee_id}>
                                    <td className="px-3 py-2">
                                        <div className="font-medium">{r.name}</div>
                                        <div className="text-[11px] text-graphite-400">{r.employee_number}{r.site_name ? ` · ${r.site_name}` : ''}</div>
                                    </td>
                                    <td className="px-3 py-2 text-xs">{r.shift ? `${r.shift.name} ${r.shift.start}-${r.shift.end}` : '-'}</td>
                                    <td className="px-3 py-2">
                                        <span className={cn('rounded-full px-2 py-0.5 text-xs font-semibold', STATE_TONE[r.state])}>{STATE_LABEL[r.state] ?? r.state}</span>
                                        {r.overtime_status === 'pending' && <span className="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-700">OT {hm(r.overtime_minutes)}</span>}
                                        {r.corrected && <span className="ml-1 text-[11px] text-graphite-400" title={r.correction_reason}>dikoreksi</span>}
                                    </td>
                                    <td className="px-3 py-2 tabular-nums">{r.check_in ?? '-'}</td>
                                    <td className="px-3 py-2 tabular-nums">{r.check_out ?? '-'}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{hm(r.worked_minutes)}</td>
                                    <td className="px-3 py-2 text-xs">{r.source ? SOURCE_LABEL[r.source] : '-'}{r.distance_m !== null && r.distance_m !== undefined ? ` · ${nf.format(r.distance_m)} m` : ''}</td>
                                    {can.manage && (
                                        <td className="px-3 py-2 text-right">
                                            <Button size="sm" variant="outline" onClick={() => setEditing({ row: r })}>Correct</Button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>

            {editing && (
                <CorrectionDialog open onClose={() => setEditing(null)} row={editing.row} date={date} employees={employees} statuses={statuses} />
            )}
        </AuthenticatedLayout>
    );
}
