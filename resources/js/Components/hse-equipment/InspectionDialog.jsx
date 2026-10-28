import { useEffect, useMemo, useRef } from 'react';
import { useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/Components/ui/dialog';
import FormField from '@/Components/shared/form/FormField';
import { ResultBadge } from './ComplianceBadge';
import { CONDITIONS, RESULTS, RISK_LEVELS, TONE_CLASSES, judgeChecklist, judgeItem } from './compliance';
import { cn } from '@/lib/utils';
import { Camera, ImagePlus, Plus, ShieldAlert, Trash2, X } from 'lucide-react';

const today = () => new Date().toISOString().slice(0, 10);
const blankAction = () => ({ action: '', assigned_to: '', due_date: '', priority: '' });

/**
 * Record one dated inspection against one unit (v2.135.0).
 *
 * With a checklist for the unit's type, the inspector answers each item and
 * the outcome follows from the answers: a critical Fail is Fail, any other
 * Fail is Needs Action, an expired date or an out-of-range reading fails by
 * itself. The preview below uses the server's rule; the server decides.
 * Anything other than Pass asks for what was found, its risk, evidence and
 * who fixes it by when -- each follow-up becomes a corrective action that is
 * completed and VERIFIED in the CAPA register.
 *
 * Without a checklist the earlier form stands: condition, result, findings.
 */
export default function InspectionDialog({ open, onClose, unit, intervalMonths, template = null, assignees = [] }) {
    const items = useMemo(() => template?.items ?? [], [template]);
    const fileRef = useRef(null);
    const { data, setData, post, processing, errors, reset, clearErrors, transform } = useForm({
        inspection_date: today(), condition: 'good', result: 'pass', findings: '', risk_level: '',
        next_inspection_due: '', notes: '', checklist: items.map(() => ({ result: '', value: '', remark: '' })), photos: [], actions: [],
    });

    useEffect(() => {
        if (open) {
            clearErrors();
            reset();
            setData((d) => ({ ...d, inspection_date: today(), checklist: items.map(() => ({ result: '', value: '', remark: '' })) }));
        }
    }, [open]);

    // One object URL per chosen file, released when the files change, so
    // typing in the form does not mint a new URL for every photo on every
    // keystroke.
    const previews = useMemo(() => data.photos.map((file) => URL.createObjectURL(file)), [data.photos]);
    useEffect(() => () => previews.forEach((url) => URL.revokeObjectURL(url)), [previews]);

    const judged = template ? judgeChecklist(items, data.checklist, data.inspection_date, data.condition) : null;
    const result = template ? judged.result : data.result;
    const notPass = result !== 'pass';

    transform((form) => ({
        ...form,
        next_inspection_due: form.next_inspection_due || null,
        risk_level: notPass ? form.risk_level || null : null,
        result: template ? null : form.result,
        checklist: template ? form.checklist : undefined,
        actions: notPass ? form.actions.filter((a) => a.action.trim() !== '').map((a) => ({ ...a, assigned_to: a.assigned_to || null, due_date: a.due_date || null, priority: a.priority || null })) : [],
        photos: form.photos,
    }));

    function answer(i, patch) {
        setData('checklist', data.checklist.map((a, idx) => (idx === i ? { ...a, ...patch } : a)));
    }
    function addPhotos(files) {
        setData('photos', [...data.photos, ...Array.from(files)].slice(0, 6));
        if (fileRef.current) fileRef.current.value = '';
    }
    function submit(event) {
        event.preventDefault();
        post(route('safety-equipment.inspections.store', unit.id), { preserveScroll: true, forceFormData: true, onSuccess: () => onClose() });
    }

    const firstError = (prefix) => Object.entries(errors).find(([k]) => k.startsWith(prefix))?.[1];

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="flex max-h-[92vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl">
                <DialogHeader className="border-b border-graphite-100 px-5 pb-3 pt-5 dark:border-slate-800">
                    <DialogTitle>Record Inspection</DialogTitle>
                    <DialogDescription>
                        {unit.equipment_code ? `${unit.equipment_code} · ` : ''}{unit.name}. Hasil tersimpan sebagai riwayat dan tidak dapat diubah.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col" noValidate>
                    <div className="relative min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <FormField label="Inspection Date" name="inspection_date" required error={errors.inspection_date}>
                                {(p) => <Input {...p} type="date" max={today()} value={data.inspection_date} onChange={(e) => setData('inspection_date', e.target.value)} />}
                            </FormField>
                            <FormField label="Physical Condition" name="condition" required error={errors.condition}
                                hint={data.condition === 'damaged' ? 'Kondisi Damaged tidak pernah dinilai Pass.' : undefined}>
                                {(p) => (
                                    <Select value={data.condition} onValueChange={(v) => setData('condition', v)}>
                                        <SelectTrigger {...p}><SelectValue /></SelectTrigger>
                                        <SelectContent>{Object.entries(CONDITIONS).map(([k, label]) => <SelectItem key={k} value={k}>{label}</SelectItem>)}</SelectContent>
                                    </Select>
                                )}
                            </FormField>
                        </div>

                        {template ? (
                            <section aria-labelledby="checklist-title">
                                <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                                    <h3 id="checklist-title" className="text-sm font-semibold text-navy-900 dark:text-slate-100">Checklist · {template.name}</h3>
                                    <span className="text-xs text-graphite-500">
                                        {judged.unanswered > 0 ? `${judged.unanswered} poin belum diisi` : 'Semua poin terisi'}
                                    </span>
                                </div>
                                <ol className="divide-y divide-graphite-100 overflow-hidden rounded-xl border border-graphite-200/80 dark:divide-slate-800 dark:border-slate-700">
                                    {items.map((item, i) => (
                                        <ChecklistRow key={i} index={i} item={item} answer={data.checklist[i] ?? {}} inspectionDate={data.inspection_date}
                                            onChange={(patch) => answer(i, patch)} error={firstError(`checklist.${i}.`)} />
                                    ))}
                                </ol>
                                {errors.checklist && <p className="mt-1 text-xs text-red-600">{errors.checklist}</p>}
                            </section>
                        ) : (
                            <FormField label="Result" name="result" required error={errors.result}
                                hint="Jenis peralatan ini belum memiliki checklist. Atur di Inspection Templates.">
                                {(p) => (
                                    <Select value={data.result} onValueChange={(v) => setData('result', v)}>
                                        <SelectTrigger {...p}><SelectValue /></SelectTrigger>
                                        <SelectContent>{Object.entries(RESULTS).map(([k, r]) => <SelectItem key={k} value={k}>{r.label}</SelectItem>)}</SelectContent>
                                    </Select>
                                )}
                            </FormField>
                        )}

                        {/* The outcome, stated before saving -- not discovered afterwards. */}
                        <div className={cn('flex items-start gap-3 rounded-xl border px-3.5 py-3 transition-colors duration-base', TONE_CLASSES[RESULTS[result].tone])} aria-live="polite">
                            <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                            <div className="text-[13px]">
                                <p className="font-semibold">Result: {RESULTS[result].label}</p>
                                <p className="opacity-90">
                                    {result === 'pass' && 'Semua poin memenuhi kriteria.'}
                                    {result === 'needs_action' && 'Ada temuan yang perlu ditindaklanjuti. Unit akan berstatus Action Required sampai inspeksi berikutnya lulus.'}
                                    {result === 'fail' && 'Poin kritis tidak memenuhi kriteria. Unit tidak boleh diandalkan sampai diperbaiki atau diganti.'}
                                </p>
                            </div>
                        </div>

                        {/* Findings, risk, evidence and follow-up: only when there is something to follow up. */}
                        <div className={cn('grid transition-[grid-template-rows] duration-slow ease-out-soft', notPass ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]')}>
                            <div className="min-h-0 overflow-hidden">
                                <fieldset disabled={!notPass} className="space-y-4 rounded-xl border border-graphite-200/80 bg-graphite-50/50 p-3.5 dark:border-slate-700 dark:bg-slate-900/40">
                                    <legend className="sr-only">Findings and follow-up</legend>
                                    <FormField label="Findings" name="findings" required error={errors.findings}
                                        hint="Jelaskan apa yang ditemukan, misalnya: tabung berkarat di bagian bawah, manometer di area merah.">
                                        {(p) => <Textarea {...p} rows={3} value={data.findings} onChange={(e) => setData('findings', e.target.value)} />}
                                    </FormField>

                                    <div>
                                        <p id="risk-label" className="mb-1.5 text-[13px] font-medium text-graphite-800 dark:text-slate-200">Risk Level <span className="text-red-600" aria-hidden="true">*</span></p>
                                        <div role="radiogroup" aria-labelledby="risk-label" className="grid grid-cols-2 gap-1.5 sm:grid-cols-4">
                                            {Object.entries(RISK_LEVELS).map(([k, r]) => (
                                                <button key={k} type="button" role="radio" aria-checked={data.risk_level === k} onClick={() => setData('risk_level', k)}
                                                    className={cn('rounded-lg border px-2.5 py-2 text-[13px] font-medium outline-none transition-[background-color,border-color,color,transform] duration-fast ease-out-soft focus-visible:ring-2 focus-visible:ring-ring/60 motion-safe:active:scale-[0.97]',
                                                        data.risk_level === k ? TONE_CLASSES[r.tone] : 'border-graphite-200 bg-white text-graphite-700 hover:border-graphite-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300')}>
                                                    {r.label}
                                                </button>
                                            ))}
                                        </div>
                                        <p className={cn('mt-1 text-xs', errors.risk_level ? 'text-red-600' : 'text-graphite-500')}>{errors.risk_level || RISK_LEVELS[data.risk_level]?.hint || 'Pilih tingkat risiko temuan.'}</p>
                                    </div>

                                    <div>
                                        <div className="mb-1.5 flex items-center justify-between">
                                            <p className="text-[13px] font-medium text-graphite-800 dark:text-slate-200">Photo Evidence</p>
                                            <Button type="button" variant="outline" size="sm" onClick={() => fileRef.current?.click()} disabled={data.photos.length >= 6}>
                                                <ImagePlus className="h-3.5 w-3.5" /> Add Photo
                                            </Button>
                                        </div>
                                        <input ref={fileRef} type="file" accept="image/*" capture="environment" multiple className="sr-only" aria-label="Add photo evidence" onChange={(e) => addPhotos(e.target.files)} />
                                        {data.photos.length > 0 ? (
                                            <ul className="grid grid-cols-3 gap-2 sm:grid-cols-6">
                                                {data.photos.map((file, i) => (
                                                    <li key={`${file.name}-${i}`} className="group relative aspect-square overflow-hidden rounded-lg border border-graphite-200 motion-safe:animate-fade-in">
                                                        <img src={previews[i]} alt={`Photo ${i + 1}`} className="h-full w-full object-cover" />
                                                        <button type="button" onClick={() => setData('photos', data.photos.filter((_, idx) => idx !== i))} aria-label={`Remove photo ${i + 1}`}
                                                            className="absolute right-1 top-1 rounded-full bg-black/60 p-0.5 text-white outline-none transition-opacity focus-visible:ring-2 focus-visible:ring-white">
                                                            <X className="h-3.5 w-3.5" />
                                                        </button>
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : (
                                            <p className="flex items-center gap-2 rounded-lg border border-dashed border-graphite-300 px-3 py-2.5 text-xs text-graphite-500"><Camera className="h-4 w-4" aria-hidden="true" /> Maksimal 6 foto, masing-masing hingga 5 MB.</p>
                                        )}
                                        {(errors.photos || firstError('photos.')) && <p className="mt-1 text-xs text-red-600">{errors.photos || firstError('photos.')}</p>}
                                    </div>

                                    <div>
                                        <div className="mb-1.5 flex items-center justify-between">
                                            <p className="text-[13px] font-medium text-graphite-800 dark:text-slate-200">Corrective Actions</p>
                                            <Button type="button" variant="outline" size="sm" onClick={() => setData('actions', [...data.actions, blankAction()])} disabled={data.actions.length >= 10}>
                                                <Plus className="h-3.5 w-3.5" /> Add Action
                                            </Button>
                                        </div>
                                        {data.actions.length === 0 ? (
                                            <p className="text-xs text-graphite-500">Tambahkan tindakan perbaikan beserta penanggung jawab dan tenggatnya. Penyelesaian diverifikasi di register CAPA.</p>
                                        ) : (
                                            <ul className="space-y-2">
                                                {data.actions.map((a, i) => (
                                                    <li key={i} className="grid grid-cols-1 gap-2 rounded-lg border border-graphite-200 bg-white p-2.5 motion-safe:animate-fade-in sm:grid-cols-[1fr_10rem_9rem_auto] dark:border-slate-700 dark:bg-slate-900">
                                                        <Input aria-label={`Action ${i + 1}`} placeholder="Tindakan, misalnya: ganti APAR" value={a.action}
                                                            onChange={(e) => setData('actions', data.actions.map((x, idx) => (idx === i ? { ...x, action: e.target.value } : x)))} />
                                                        <Select value={a.assigned_to ? String(a.assigned_to) : ''} onValueChange={(v) => setData('actions', data.actions.map((x, idx) => (idx === i ? { ...x, assigned_to: v } : x)))}>
                                                            <SelectTrigger aria-label={`Person responsible for action ${i + 1}`}><SelectValue placeholder="Responsible" /></SelectTrigger>
                                                            <SelectContent>{assignees.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}</SelectContent>
                                                        </Select>
                                                        <Input type="date" aria-label={`Due date for action ${i + 1}`} min={data.inspection_date} value={a.due_date}
                                                            onChange={(e) => setData('actions', data.actions.map((x, idx) => (idx === i ? { ...x, due_date: e.target.value } : x)))} />
                                                        <Button type="button" variant="ghost" size="icon" aria-label={`Remove action ${i + 1}`} onClick={() => setData('actions', data.actions.filter((_, idx) => idx !== i))}>
                                                            <Trash2 className="h-4 w-4 text-red-500" />
                                                        </Button>
                                                        {firstError(`actions.${i}.`) && <p className="text-xs text-red-600 sm:col-span-4">{firstError(`actions.${i}.`)}</p>}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                </fieldset>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <FormField label="Next Inspection Due" name="next_inspection_due" error={errors.next_inspection_due}
                                hint={intervalMonths ? `Kosongkan untuk memakai interval jenis ini (${intervalMonths} bulan).` : 'Kosongkan jika jadwal berikutnya belum ditentukan.'}>
                                {(p) => <Input {...p} type="date" value={data.next_inspection_due} onChange={(e) => setData('next_inspection_due', e.target.value)} />}
                            </FormField>
                            <FormField label="Notes" name="notes" error={errors.notes}>
                                {(p) => <Textarea {...p} rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />}
                            </FormField>
                        </div>
                    </div>

                    <DialogFooter className="border-t border-graphite-100 px-5 py-3 dark:border-slate-800">
                        <span className="mr-auto hidden items-center gap-2 text-xs text-graphite-500 sm:flex">Akan disimpan sebagai <ResultBadge result={result} /></span>
                        <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                        <Button type="submit" loading={processing}>{processing ? 'Saving…' : 'Save Inspection'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** One checklist item: its question, the control its kind of check needs, and the outcome so far. */
function ChecklistRow({ index, item, answer, inspectionDate, onChange, error }) {
    const check = item.check || 'pass_fail';
    const outcome = judgeItem(item, answer, inspectionDate);
    const range = [item.min, item.max].some((v) => v !== undefined && v !== null && v !== '')
        ? `Batas ${item.min ?? '…'}–${item.max ?? '…'}${item.unit ? ` ${item.unit}` : ''}` : null;

    return (
        <li className={cn('px-3.5 py-3 transition-colors duration-base', outcome === 'fail' && 'bg-red-50/60 dark:bg-red-950/20')}>
            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <p className="text-[13px] leading-snug text-graphite-800 dark:text-slate-200">
                    <span className="mr-1.5 tabular-nums text-graphite-400">{index + 1}.</span>
                    {item.label}
                    {item.critical && <span className="ml-1.5 rounded bg-red-100 px-1 py-px text-[10.5px] font-semibold text-red-700 dark:bg-red-950/50 dark:text-red-300">Critical</span>}
                </p>

                {check === 'pass_fail' ? (
                    <div role="radiogroup" aria-label={item.label} className="inline-flex shrink-0 rounded-lg bg-graphite-100 p-0.5 dark:bg-slate-800">
                        {[['pass', 'Pass'], ['fail', 'Fail'], ['na', 'N/A']].map(([value, label]) => {
                            const selected = answer.result === value;
                            const disabled = value === 'na' && item.critical;
                            return (
                                <button key={value} type="button" role="radio" aria-checked={selected} disabled={disabled}
                                    title={disabled ? 'Poin kritis tidak boleh N/A' : undefined}
                                    onClick={() => onChange({ result: value })}
                                    className={cn('min-w-[3.25rem] rounded-md px-2.5 py-1 text-xs font-semibold outline-none transition-[background-color,color,box-shadow,transform] duration-fast ease-out-soft focus-visible:ring-2 focus-visible:ring-ring/60 motion-safe:active:scale-[0.96] disabled:cursor-not-allowed disabled:opacity-40',
                                        selected
                                            ? value === 'pass' ? 'bg-emerald-600 text-white shadow-sm' : value === 'fail' ? 'bg-red-600 text-white shadow-sm' : 'bg-white text-graphite-800 shadow-sm dark:bg-slate-700 dark:text-slate-100'
                                            : 'text-graphite-600 hover:text-graphite-900 dark:text-slate-400 dark:hover:text-slate-100')}>
                                    {label}
                                </button>
                            );
                        })}
                    </div>
                ) : (
                    <div className="flex shrink-0 items-center gap-2">
                        <Input type={check === 'date' ? 'date' : 'number'} step="any" inputMode={check === 'reading' ? 'decimal' : undefined} aria-label={item.label}
                            value={answer.value ?? ''} disabled={answer.result === 'na'} onChange={(e) => onChange({ value: e.target.value })}
                            className={cn('h-8', check === 'date' ? 'w-[9.5rem]' : 'w-28')} />
                        {item.unit && check === 'reading' && <span className="text-xs text-graphite-500">{item.unit}</span>}
                        {!item.critical && (
                            <label className="flex items-center gap-1 text-xs text-graphite-500">
                                <input type="checkbox" className="rounded border-graphite-300" checked={answer.result === 'na'} onChange={(e) => onChange({ result: e.target.checked ? 'na' : '' })} /> N/A
                            </label>
                        )}
                    </div>
                )}
            </div>

            {(range || check === 'date' || outcome) && (
                <p className="mt-1 flex flex-wrap items-center gap-x-2 text-[11.5px] text-graphite-500">
                    {range}
                    {check === 'date' && !answer.value && 'Isi tanggal yang tertera pada unit.'}
                    {outcome === 'fail' && check === 'date' && <span className="font-medium text-red-700">Sudah lewat pada tanggal inspeksi: Expired</span>}
                    {outcome === 'fail' && check === 'reading' && <span className="font-medium text-red-700">Di luar batas</span>}
                    {outcome === 'fail' && item.photo_on_fail && <span className="font-medium text-red-700">· foto wajib</span>}
                </p>
            )}

            {/* A remark appears only where it is needed: on a Fail. */}
            <div className={cn('grid transition-[grid-template-rows] duration-base ease-out-soft', outcome === 'fail' ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]')}>
                <div className="min-h-0 overflow-hidden">
                    <Input className="mt-2 h-8" aria-label={`Remark for item ${index + 1}`} placeholder="Keterangan temuan (opsional)" tabIndex={outcome === 'fail' ? 0 : -1}
                        value={answer.remark ?? ''} onChange={(e) => onChange({ remark: e.target.value })} />
                </div>
            </div>
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </li>
    );
}
