/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { useForm } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { cn } from '@/lib/utils';

/**
 * Milestone 3 (Company Settings completion, Task #62). Company Admin's
 * own surface for `App\Services\ApprovalEngine`'s multi-level/parallel/
 * escalation capability (ADR-010) -- create a named flow for a module,
 * then define its steps. A module with no flow here keeps using the
 * legacy single-step approval (config('workflow.approvers')), unchanged.
 */
/**
 * v2.127.0 -- WHO VERIFIES AND WHO APPROVES A PERMIT, per operating unit.
 *
 * A Permit to Work now passes two gates: HSE VERIFIES, then somebody
 * approves, and only then may the permit be activated. This tab is where a
 * company says who holds each one.
 *
 * TWO DIMENSIONS PER STAGE, because they answer different questions. A
 * ROLE is a standing rule that survives staff turnover; a NAMED PERSON is
 * who actually gets told. A company may use either or both, and the union
 * is both the authority and the notification list -- so the person who is
 * asked to act is always somebody who may act (ADR 061).
 *
 * A COMPANY THAT CONFIGURES NOTHING IS NOT LOCKED OUT, and the tab says
 * so on screen rather than leaving an empty form that reads as "nobody may
 * approve anything". The fallback is the behaviour PTW had before this
 * release.
 */
export default function PtwWorkflowTab({ ptwWorkflow, companies }) {
    const roles = ptwWorkflow?.roles ?? {};
    const users = ptwWorkflow?.users ?? [];
    const defaults = ptwWorkflow?.defaults ?? {};
    const stored = ptwWorkflow?.settings ?? {};

    const [companyId, setCompanyId] = useState(companies?.[0]?.id ? String(companies[0].id) : '');
    const current = stored?.[companyId] ?? stored?.[Number(companyId)] ?? null;

    const form = useForm({
        company_id: companyId,
        verify_roles: current?.verify_roles ?? [],
        verify_user_ids: (current?.verify_user_ids ?? []).map(String),
        verify_title: current?.verify_title ?? '',
        approve_roles: current?.approve_roles ?? [],
        approve_user_ids: (current?.approve_user_ids ?? []).map(String),
        approve_title: current?.approve_title ?? '',
    });

    useEffect(() => {
        const row = stored?.[companyId] ?? stored?.[Number(companyId)] ?? null;
        form.setData({
            company_id: companyId,
            verify_roles: row?.verify_roles ?? [],
            verify_user_ids: (row?.verify_user_ids ?? []).map(String),
            verify_title: row?.verify_title ?? '',
            approve_roles: row?.approve_roles ?? [],
            approve_user_ids: (row?.approve_user_ids ?? []).map(String),
            approve_title: row?.approve_title ?? '',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [companyId]);

    function toggle(field, value) {
        const list = form.data[field] ?? [];
        form.setData(field, list.includes(value) ? list.filter((v) => v !== value) : [...list, value]);
    }

    function submit(e) {
        e.preventDefault();
        form.post(route('settings.ptw-workflow'), { preserveScroll: true });
    }

    const stage = (label, roleField, userField, fallback, titleField, titlePlaceholder) => {
        const noneChosen = (form.data[roleField] ?? []).length === 0 && (form.data[userField] ?? []).length === 0;

        return (
            <div className="space-y-3 rounded-lg border border-graphite-200 p-3 dark:border-slate-700">
                <p className="text-xs font-semibold uppercase tracking-wide text-graphite-500">{label}</p>

                <div className="space-y-1.5">
                    <Label className="text-xs">Roles</Label>
                    <div className="flex flex-wrap gap-1.5">
                        {Object.entries(roles).map(([key, name]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => toggle(roleField, key)}
                                className={cn(
                                    'rounded-full border px-2.5 py-1 text-[11px] transition-colors',
                                    (form.data[roleField] ?? []).includes(key)
                                        ? 'border-brand-500 bg-brand-50 font-medium text-brand-700 dark:bg-brand-950/40 dark:text-brand-300'
                                        : 'border-graphite-200 text-graphite-600 hover:border-brand-300 dark:border-slate-700 dark:text-slate-300'
                                )}
                            >
                                {name}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="space-y-1.5">
                    <Label className="text-xs">Specific People</Label>
                    <div className="max-h-40 space-y-0.5 overflow-y-auto overscroll-contain rounded border border-graphite-100 p-1.5 dark:border-slate-800">
                        {users.length === 0 && <p className="px-1 py-2 text-[11px] text-graphite-400">Belum ada pengguna dashboard aktif pada akun ini.</p>}
                        {users.map((u) => (
                            <label key={u.id} className="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-xs hover:bg-graphite-50 dark:hover:bg-slate-800">
                                <input
                                    type="checkbox"
                                    className="rounded border-graphite-300"
                                    checked={(form.data[userField] ?? []).includes(String(u.id))}
                                    onChange={() => toggle(userField, String(u.id))}
                                />
                                <span className="min-w-0 truncate">{u.name}</span>
                                <span className="shrink-0 text-[10px] uppercase text-graphite-400">{u.role}</span>
                            </label>
                        ))}
                    </div>
                </div>

                {/*
                    v2.128.0 -- THE JOB TITLE THE SEAL IS FILED UNDER.

                    A role decides who may press the button; this is what
                    the stamp on the paper says. The two were conflated, so
                    a VERIFIED seal printed `HSE` -- an IOMS permission
                    standing in for a job title on a controlled document.
                    Free text, because no two yards word a job the same way.
                 */}
                <div className="space-y-1.5">
                    <Label className="text-xs">Job Title on the Stamp</Label>
                    <Input
                        className="h-9"
                        maxLength={60}
                        value={form.data[titleField] ?? ''}
                        onChange={(e) => form.setData(titleField, e.target.value)}
                        placeholder={titlePlaceholder}
                    />
                    <p className="text-[11px] leading-snug text-graphite-500">
                        Jabatan yang dicetak pada stempel tahap ini, dipakai bila penanda tangan belum memiliki
                        posisi di Employee Register. Dikunci saat tindakan dilakukan, sehingga perubahan di sini
                        tidak mengubah izin kerja yang sudah ditandatangani.
                    </p>
                    {form.errors[titleField] && <p className="text-[11px] text-red-600">{form.errors[titleField]}</p>}
                </div>

                {/* What happens if they save this empty -- stated, because
                    an empty stage silently falling back is otherwise
                    indistinguishable from an empty stage blocking the
                    workflow, and one of those is alarming. */}
                {noneChosen && (
                    <p className="text-[11px] leading-snug text-amber-700 dark:text-amber-400">
                        Belum ada yang dipilih. Tahap ini akan memakai bawaan: {(fallback ?? []).map((r) => roles[r] ?? r).join(', ') || 'tidak ada'}.
                    </p>
                )}
            </div>
        );
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Permit to Work Workflow</CardTitle>
                <CardDescription>
                    Tentukan siapa yang melakukan verifikasi HSE dan siapa yang memberikan persetujuan akhir untuk
                    setiap unit operasi. Pihak yang dipilih di sini juga yang menerima notifikasinya, dan izin kerja
                    baru menjadi aktif setelah kedua tahap selesai.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label className="text-xs">Operating Unit</Label>
                        <Select value={companyId} onValueChange={setCompanyId}>
                            <SelectTrigger className="h-9 max-w-sm"><SelectValue placeholder="Pilih unit operasi" /></SelectTrigger>
                            <SelectContent>
                                {(companies ?? []).map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-[11px] text-graphite-500">
                            Pengaturan disimpan per unit operasi, karena izin kerja dimiliki oleh unit operasi.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                        {stage('Stage 1 · HSE Verification', 'verify_roles', 'verify_user_ids', defaults.verify_roles, 'verify_title', 'e.g. HSE Officer')}
                        {stage('Stage 2 · Final Approval', 'approve_roles', 'approve_user_ids', defaults.approve_roles, 'approve_title', 'e.g. Manager Shipyard')}
                    </div>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing || !companyId}>Save Workflow</Button>
                        {form.recentlySuccessful && <span className="text-xs text-success">Saved.</span>}
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}
