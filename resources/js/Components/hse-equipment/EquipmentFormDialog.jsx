import { useEffect, useMemo } from 'react';
import { useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/Components/ui/dialog';
import FormField from '@/Components/shared/form/FormField';
import { LIFECYCLES } from './compliance';

const EMPTY = {
    company_id: '', equipment_type_id: '', name: '', equipment_code: '', brand: '', model: '',
    serial_number: '', location: '', commissioned_at: '', status: 'active',
    last_inspection_date: '', next_inspection_due: '', next_calibration_due: '', next_service_due: '', expiry_date: '',
    asset_id: '', notes: '',
};

function fromUnit(unit) {
    const out = { ...EMPTY };
    Object.keys(EMPTY).forEach((key) => {
        const value = key === 'equipment_type_id' ? unit.type_id : unit[key];
        out[key] = value === null || value === undefined ? '' : String(value).slice(0, key.endsWith('_at') || key.endsWith('_date') || key.endsWith('_due') ? 10 : undefined);
    });
    return out;
}

/**
 * Add or edit one unit of the Safety Equipment Register.
 *
 * The date fields shown are the ones the chosen TYPE tracks (v2.53.0): a
 * gas detector is asked for a calibration date, a fire extinguisher for an
 * expiry date, and neither is asked for a date it does not have. A unit
 * whose dates are already filed keeps them visible even if its type no
 * longer tracks that lifecycle, so nothing is hidden from the person
 * editing it.
 */
export default function EquipmentFormDialog({ open, onClose, unit = null, equipmentTypes, assets = [], companies }) {
    const editing = Boolean(unit);
    const { data, setData, post, put, processing, errors, reset, clearErrors, transform } = useForm(EMPTY);

    useEffect(() => {
        if (!open) return;
        clearErrors();
        setData(unit ? fromUnit(unit) : { ...EMPTY, company_id: companies[0]?.id ? String(companies[0].id) : '' });
         
    }, [open, unit?.id]);

    // Empty strings become null so an optional date or link is cleared,
    // not sent as "" (which `date` and Rule::in validation would refuse).
    transform((form) => Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v])));

    const typeChoices = useMemo(
        () => equipmentTypes.filter((t) => String(t.company_id) === String(data.company_id) && (t.is_active || String(t.id) === String(unit?.type_id))),
        [equipmentTypes, data.company_id, unit?.type_id]
    );
    const type = equipmentTypes.find((t) => String(t.id) === String(data.equipment_type_id));
    const tracked = type ? type.tracked : ['inspection'];
    const lifecycleKeys = Object.keys(LIFECYCLES).filter((key) => tracked.includes(key) || (unit && unit[LIFECYCLES[key].field]));
    const prefix = type?.code_prefix || 'EQ';

    function submit(event) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); onClose(); } };
        if (editing) put(route('safety-equipment.update', unit.id), options);
        else post(route('safety-equipment.store'), options);
    }

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{editing ? `Edit ${unit.equipment_code || unit.name}` : 'Add Equipment'}</DialogTitle>
                    <DialogDescription>
                        Satu baris untuk satu unit fisik. Jenis menentukan tanggal mana yang perlu diisi.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-5" noValidate>
                    <fieldset className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <legend className="mb-2 text-[11px] font-bold uppercase tracking-wider text-steel-600 dark:text-slate-400">Identity</legend>
                        {companies.length > 1 && (
                            <FormField label="Operating Unit" name="company_id" required error={errors.company_id} className="sm:col-span-2">
                                {(p) => (
                                    <Select value={data.company_id} onValueChange={(v) => { setData((d) => ({ ...d, company_id: v, equipment_type_id: '' })); }}>
                                        <SelectTrigger {...p}><SelectValue /></SelectTrigger>
                                        <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                                    </Select>
                                )}
                            </FormField>
                        )}
                        <FormField label="Equipment Type" name="equipment_type_id" required error={errors.equipment_type_id || errors.type}
                            hint={typeChoices.length === 0 ? 'Belum ada jenis aktif. Tambahkan di Reference Data, Equipment Types.' : undefined}>
                            {(p) => (
                                <Select value={data.equipment_type_id} onValueChange={(v) => setData('equipment_type_id', v)}>
                                    <SelectTrigger {...p}><SelectValue placeholder="Choose a type" /></SelectTrigger>
                                    <SelectContent>{typeChoices.map((t) => <SelectItem key={t.id} value={String(t.id)}>{t.name}{t.is_active ? '' : ' (inactive)'}</SelectItem>)}</SelectContent>
                                </Select>
                            )}
                        </FormField>
                        <FormField label="Equipment ID" name="equipment_code" error={errors.equipment_code}
                            hint={editing && unit.equipment_code ? 'ID yang sudah ada tidak diberi nomor ulang.' : `Kosongkan agar dibuat otomatis saat disimpan, misalnya ${prefix}-00001.`}>
                            {(p) => <Input {...p} value={data.equipment_code} onChange={(e) => setData('equipment_code', e.target.value.toUpperCase())} placeholder={`${prefix}-00001`} className="font-mono" autoComplete="off" />}
                        </FormField>
                        <FormField label="Name" name="name" required error={errors.name} className="sm:col-span-2">
                            {(p) => <Input {...p} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="APAR CO2 5 kg, Workshop A" />}
                        </FormField>
                        <FormField label="Brand" name="brand" error={errors.brand}>
                            {(p) => <Input {...p} value={data.brand} onChange={(e) => setData('brand', e.target.value)} />}
                        </FormField>
                        <FormField label="Model" name="model" error={errors.model}>
                            {(p) => <Input {...p} value={data.model} onChange={(e) => setData('model', e.target.value)} />}
                        </FormField>
                        <FormField label="Serial Number" name="serial_number" error={errors.serial_number}>
                            {(p) => <Input {...p} value={data.serial_number} onChange={(e) => setData('serial_number', e.target.value)} className="font-mono" />}
                        </FormField>
                        <FormField label="Location" name="location" error={errors.location} hint="Tempat unit dapat ditemukan saat inspeksi.">
                            {(p) => <Input {...p} value={data.location} onChange={(e) => setData('location', e.target.value)} placeholder="Gudang B, dinding timur" />}
                        </FormField>
                    </fieldset>

                    <fieldset className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <legend className="mb-2 text-[11px] font-bold uppercase tracking-wider text-steel-600 dark:text-slate-400">Status and Dates</legend>
                        <FormField label="Status" name="status" required error={errors.status}>
                            {(p) => (
                                <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                    <SelectTrigger {...p}><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="active">In Service</SelectItem>
                                        <SelectItem value="out_of_service">Out of Service</SelectItem>
                                    </SelectContent>
                                </Select>
                            )}
                        </FormField>
                        <FormField label="Commissioned" name="commissioned_at" error={errors.commissioned_at}>
                            {(p) => <Input {...p} type="date" value={data.commissioned_at} onChange={(e) => setData('commissioned_at', e.target.value)} />}
                        </FormField>
                        {tracked.includes('inspection') && (
                            <FormField label="Last Inspection" name="last_inspection_date" error={errors.last_inspection_date}
                                hint={editing ? 'Biasanya terisi sendiri saat inspeksi dicatat.' : undefined}>
                                {(p) => <Input {...p} type="date" value={data.last_inspection_date} onChange={(e) => setData('last_inspection_date', e.target.value)} />}
                            </FormField>
                        )}
                        {lifecycleKeys.map((key) => (
                            <FormField key={key} label={LIFECYCLES[key].fieldLabel} name={LIFECYCLES[key].field} error={errors[LIFECYCLES[key].field]}>
                                {(p) => <Input {...p} type="date" value={data[LIFECYCLES[key].field]} onChange={(e) => setData(LIFECYCLES[key].field, e.target.value)} />}
                            </FormField>
                        ))}
                    </fieldset>

                    <fieldset className="grid grid-cols-1 gap-4">
                        <legend className="mb-2 text-[11px] font-bold uppercase tracking-wider text-steel-600 dark:text-slate-400">Links and Notes</legend>
                        <FormField label="Company Asset" name="asset_id" error={errors.asset_id}
                            hint="Isi hanya jika unit ini juga tercatat sebagai aset perusahaan. Pencatatan HSE tetap berjalan tanpa ini.">
                            {(p) => (
                                <Select value={data.asset_id || '__none'} onValueChange={(v) => setData('asset_id', v === '__none' ? '' : v)}>
                                    <SelectTrigger {...p}><SelectValue placeholder="Not linked" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none">Not linked</SelectItem>
                                        {assets.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.asset_code ? `${a.asset_code} · ${a.name}` : a.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            )}
                        </FormField>
                        <FormField label="Notes" name="notes" error={errors.notes}>
                            {(p) => <Textarea {...p} rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />}
                        </FormField>
                    </fieldset>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={processing}>{processing ? 'Saving...' : editing ? 'Save Changes' : 'Add Equipment'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
