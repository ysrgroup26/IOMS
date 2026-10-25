import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/Components/ui/dialog';
import FormField from '@/Components/shared/form/FormField';
import { CONDITIONS, RESULTS } from './compliance';

const today = () => new Date().toISOString().slice(0, 10);

/**
 * Record one dated inspection against one unit. Each inspection is its own
 * history row and is never edited afterwards; the unit's own "last" and
 * "next" dates follow the newest one (SafetyEquipmentController).
 */
export default function InspectionDialog({ open, onClose, unit, intervalMonths }) {
    const { data, setData, post, processing, errors, reset, clearErrors, transform } = useForm({
        inspection_date: today(), condition: 'good', result: 'pass', findings: '', next_inspection_due: '', notes: '',
    });

    useEffect(() => {
        if (open) { clearErrors(); reset(); setData('inspection_date', today()); }
         
    }, [open]);

    transform((form) => ({ ...form, next_inspection_due: form.next_inspection_due || null }));

    const needsFindings = data.result === 'fail' || data.result === 'needs_action';

    function submit(event) {
        event.preventDefault();
        post(route('safety-equipment.inspections.store', unit.id), { preserveScroll: true, onSuccess: () => onClose() });
    }

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Record Inspection</DialogTitle>
                    <DialogDescription>
                        {unit.equipment_code ? `${unit.equipment_code} · ` : ''}{unit.name}. Hasil ini tersimpan sebagai riwayat dan tidak dapat diubah.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-2" noValidate>
                    <FormField label="Inspection Date" name="inspection_date" required error={errors.inspection_date}>
                        {(p) => <Input {...p} type="date" max={today()} value={data.inspection_date} onChange={(e) => setData('inspection_date', e.target.value)} />}
                    </FormField>
                    <FormField label="Condition" name="condition" required error={errors.condition}>
                        {(p) => (
                            <Select value={data.condition} onValueChange={(v) => setData('condition', v)}>
                                <SelectTrigger {...p}><SelectValue /></SelectTrigger>
                                <SelectContent>{Object.entries(CONDITIONS).map(([k, label]) => <SelectItem key={k} value={k}>{label}</SelectItem>)}</SelectContent>
                            </Select>
                        )}
                    </FormField>
                    <FormField label="Result" name="result" required error={errors.result}>
                        {(p) => (
                            <Select value={data.result} onValueChange={(v) => setData('result', v)}>
                                <SelectTrigger {...p}><SelectValue /></SelectTrigger>
                                <SelectContent>{Object.entries(RESULTS).map(([k, r]) => <SelectItem key={k} value={k}>{r.label}</SelectItem>)}</SelectContent>
                            </Select>
                        )}
                    </FormField>
                    <FormField label="Next Inspection Due" name="next_inspection_due" error={errors.next_inspection_due}
                        hint={intervalMonths ? `Kosongkan untuk memakai interval jenis ini (${intervalMonths} bulan).` : 'Kosongkan jika jadwal berikutnya belum ditentukan.'}>
                        {(p) => <Input {...p} type="date" value={data.next_inspection_due} onChange={(e) => setData('next_inspection_due', e.target.value)} />}
                    </FormField>
                    <FormField label="Findings" name="findings" required={needsFindings} error={errors.findings} className="sm:col-span-2"
                        hint={needsFindings ? 'Wajib diisi untuk hasil Fail atau Needs Action.' : undefined}>
                        {(p) => <Textarea {...p} rows={3} value={data.findings} onChange={(e) => setData('findings', e.target.value)} />}
                    </FormField>
                    <FormField label="Notes" name="notes" error={errors.notes} className="sm:col-span-2">
                        {(p) => <Textarea {...p} rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />}
                    </FormField>
                    <DialogFooter className="sm:col-span-2">
                        <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={processing}>{processing ? 'Saving...' : 'Save Inspection'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
