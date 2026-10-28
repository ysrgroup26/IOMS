import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useRef } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import FormSection from '@/Components/shared/form/FormSection';
import FormField from '@/Components/shared/form/FormField';
import FormActions from '@/Components/shared/form/FormActions';
import ErrorSummary from '@/Components/shared/form/ErrorSummary';
import { useUnsavedChanges } from '@/lib/useUnsavedChanges';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { cn } from '@/lib/utils';
import { AlertTriangle, ArrowLeft, Info } from 'lucide-react';

const today = () => new Date().toISOString().slice(0, 10);

const LABELS = {
    company_id: 'Operating Unit', waste_type_id: 'Waste Type', location: 'Source / Work Area', project_id: 'Project',
    quantity: 'Quantity', unit: 'Unit', container: 'Container', generated_date: 'Generated Date',
    storage_location_id: 'Storage Location / TPS', received_date: 'Received / Stored Date', notes: 'Notes',
};

/**
 * New Waste Record (v1.11.4; redesigned v2.135.0).
 *
 * Three questions, in the order a person on site answers them: WHAT was
 * generated, HOW MUCH, and WHERE it is now. The unit follows the waste
 * type (used oil in liters, scrap in kg) and is chosen from units the system
 * can reason about -- a liter is never added to a kilogram, and a count is
 * whole. Nothing required for traceability was dropped: the record still
 * carries its operating unit, type, source, quantity and unit, generated
 * date, and -- once it is in a TPS -- the location and the received date.
 */
export default function WasteRecordForm({ wasteTypes, storageLocations, projects, companies, units = [], containerTypes = [] }) {
    const initial = useRef({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        waste_type_id: '', project_id: '', location: '', storage_location_id: '',
        quantity: '', unit: '', container: '', generated_date: today(), received_date: '', notes: '',
    });
    const { data, setData, post, processing, errors, transform } = useForm(initial.current);
    const { release } = useUnsavedChanges(data, initial.current, !processing);

    const companyTypes = wasteTypes.filter((t) => String(t.company_id) === data.company_id);
    const companyTps = storageLocations.filter((s) => String(s.company_id) === data.company_id);
    const type = wasteTypes.find((t) => String(t.id) === data.waste_type_id);
    const tps = storageLocations.find((s) => String(s.id) === data.storage_location_id);

    // Standard units, plus the type's own when its master data uses another.
    const unitOptions = useMemo(() => {
        const list = units.map((u) => ({ value: u.value, label: u.label, kind: u.kind }));
        if (type?.unit && !list.some((u) => u.value === type.unit.toLowerCase())) list.push({ value: type.unit, label: type.unit, kind: null });
        return list;
    }, [units, type]);
    const unit = unitOptions.find((u) => u.value === data.unit);
    const isCount = unit?.kind === 'count';
    const typeUnit = type?.unit?.toLowerCase();
    const unitDiffers = type && data.unit && typeUnit && data.unit.toLowerCase() !== typeUnit;
    const tpsUnitDiffers = tps?.capacity_unit && data.unit && tps.capacity_unit.toLowerCase() !== data.unit.toLowerCase();

    function chooseCompany(v) {
        setData((d) => ({ ...d, company_id: v, waste_type_id: '', storage_location_id: '' }));
    }
    function chooseType(v) {
        const t = wasteTypes.find((x) => String(x.id) === v);
        // The type proposes its unit; a unit the person already chose is kept.
        setData((d) => ({ ...d, waste_type_id: v, unit: d.unit || (t?.unit ? (units.some((u) => u.value === t.unit.toLowerCase()) ? t.unit.toLowerCase() : t.unit) : '') }));
    }

    transform((d) => ({
        ...d,
        project_id: d.project_id || null,
        storage_location_id: d.storage_location_id || null,
        received_date: d.storage_location_id ? d.received_date || null : null,
    }));

    // The unsaved-changes guard watches every Inertia visit, this form's
    // own POST included: release it first, or Save asks "leave this page?".
    function submit(e) {
        e.preventDefault();
        release();
        post(route('waste-records.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="New Waste Record" />

            <Link href={route('waste.dashboard')} className="mb-3 inline-flex items-center gap-1 text-[13px] font-medium text-graphite-500 transition-colors duration-fast hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Waste Management
            </Link>

            <PageHeader title="New Waste Record" subtitle="Catat limbah yang dihasilkan: jenis, jumlah, dan di mana limbah itu sekarang." />

            <form onSubmit={submit} noValidate className="mx-auto max-w-3xl space-y-4">
                <ErrorSummary errors={errors} labels={LABELS} />

                {/* One object, three plain sections: the order is how a person answers, not a procedure. */}
                <Card><CardContent className="space-y-6 p-5 lg:p-6">

                <FormSection title="Waste" description="Limbah apa yang dihasilkan, dan dari mana?">
                        {companies.length > 1 && (
                            <FormField label="Operating Unit" name="company_id" required error={errors.company_id} className="sm:col-span-2">
                                {(p) => (
                                    <Select value={data.company_id} onValueChange={chooseCompany}>
                                        <SelectTrigger {...p}><SelectValue /></SelectTrigger>
                                        <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                                    </Select>
                                )}
                            </FormField>
                        )}
                        <FormField label="Waste Type" name="waste_type_id" required error={errors.waste_type_id} className="sm:col-span-2"
                            hint={companyTypes.length === 0 ? 'Belum ada jenis limbah untuk unit operasi ini. Tambahkan di Master Data.' : undefined}>
                            {(p) => (
                                <Select value={data.waste_type_id} onValueChange={chooseType}>
                                    <SelectTrigger {...p}><SelectValue placeholder="Choose a waste type" /></SelectTrigger>
                                    <SelectContent>
                                        {companyTypes.map((t) => (
                                            <SelectItem key={t.id} value={String(t.id)}>
                                                {t.name} · {t.category === 'b3' ? 'B3' : 'Non-B3'}{t.waste_code ? ` · ${t.waste_code}` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                        </FormField>
                        {type && (
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-steel-200 bg-steel-50/70 px-3 py-2 text-xs text-steel-800 motion-safe:animate-fade-in sm:col-span-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
                                <Info className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                <span className={cn('rounded px-1.5 py-px font-semibold', type.category === 'b3' ? 'bg-red-100 text-red-700' : 'bg-steel-100 text-steel-700')}>{type.category === 'b3' ? 'B3' : 'Non-B3'}</span>
                                <span>Kode limbah: {type.waste_code || 'belum diisi'}</span>
                                <span>Satuan jenis ini: {type.unit || 'belum diisi'}</span>
                                <span>{type.storage_limit_days ? `Batas lama simpan: ${type.storage_limit_days} hari` : 'Batas lama simpan belum ditetapkan'}</span>
                            </div>
                        )}
                        <FormField label="Source / Work Area" name="location" error={errors.location}
                            hint="Area atau kegiatan yang menghasilkan limbah, misalnya Workshop B, pengecatan lambung kapal.">
                            {(p) => <Input {...p} value={data.location} onChange={(e) => setData('location', e.target.value)} placeholder="Workshop B" />}
                        </FormField>
                        <FormField label="Project" name="project_id" error={errors.project_id} hint="Opsional, bila limbah berasal dari proyek tertentu.">
                            {(p) => (
                                <Select value={data.project_id || '__none'} onValueChange={(v) => setData('project_id', v === '__none' ? '' : v)}>
                                    <SelectTrigger {...p}><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none">Not linked</SelectItem>
                                        {projects.map((pr) => <SelectItem key={pr.id} value={String(pr.id)}>{pr.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            )}
                        </FormField>
                </FormSection>

                <FormSection title="Quantity" description="Berapa banyak, dan dalam wadah apa?">
                        <FormField label="Quantity" name="quantity" required error={errors.quantity}
                            hint={isCount ? 'Jumlah dalam bilangan bulat.' : undefined}>
                            {(p) => (
                                <Input {...p} type="number" inputMode={isCount ? 'numeric' : 'decimal'} min={isCount ? 1 : 0.01} step={isCount ? 1 : 0.01}
                                    value={data.quantity} onChange={(e) => setData('quantity', e.target.value)} />
                            )}
                        </FormField>
                        <FormField label="Unit" name="unit" required error={errors.unit}>
                            {(p) => (
                                <Select value={data.unit} onValueChange={(v) => setData('unit', v)}>
                                    <SelectTrigger {...p}><SelectValue placeholder="Choose a unit" /></SelectTrigger>
                                    <SelectContent>{unitOptions.map((u) => <SelectItem key={u.value} value={u.value}>{u.label}</SelectItem>)}</SelectContent>
                                </Select>
                            )}
                        </FormField>
                        <FormField label="Container" name="container" error={errors.container} hint="Opsional: drum, IBC, jumbo bag." className="sm:col-span-2">
                            {(p) => (
                                <>
                                    <Input {...p} list="container-types" value={data.container} onChange={(e) => setData('container', e.target.value)} placeholder="Drum 200 L" />
                                    <datalist id="container-types">{containerTypes.map((c) => <option key={c} value={c} />)}</datalist>
                                </>
                            )}
                        </FormField>
                    {unitDiffers && (
                        <p role="note" className="flex sm:col-span-2 items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 motion-safe:animate-fade-in">
                            <AlertTriangle className="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            Satuan ini berbeda dari satuan jenis limbah ({type.unit}). Jumlahnya tidak akan dijumlahkan dengan catatan bersatuan {type.unit}, dan tidak dikonversi.
                        </p>
                    )}
                </FormSection>

                <FormSection title="Generation & Storage" description="Kapan limbah dihasilkan, dan di mana disimpan sekarang?">
                        <FormField label="Generated Date" name="generated_date" required error={errors.generated_date}>
                            {(p) => <Input {...p} type="date" max={today()} value={data.generated_date} onChange={(e) => setData('generated_date', e.target.value)} />}
                        </FormField>
                        <FormField label="Storage Location / TPS" name="storage_location_id" error={errors.storage_location_id}
                            hint={data.storage_location_id ? undefined : 'Kosongkan bila belum masuk TPS; dapat ditetapkan nanti dari catatan.'}>
                            {(p) => (
                                <Select value={data.storage_location_id || '__none'} onValueChange={(v) => setData('storage_location_id', v === '__none' ? '' : v)}>
                                    <SelectTrigger {...p}><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none">Not yet stored</SelectItem>
                                        {companyTps.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.code ? `${s.code} · ` : ''}{s.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            )}
                        </FormField>
                        {/* The received date only means something once the waste is in a TPS. */}
                        <div className={cn('grid transition-[grid-template-rows] duration-base ease-out-soft sm:col-span-2', data.storage_location_id ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]')}>
                            <div className="min-h-0 overflow-hidden">
                                <div className="grid grid-cols-1 gap-4 pt-0.5 sm:grid-cols-2">
                                    <FormField label="Received / Stored Date" name="received_date" error={errors.received_date}
                                        hint="Tanggal limbah diterima di TPS. Kosongkan bila sama dengan tanggal dihasilkan.">
                                        {(p) => <Input {...p} type="date" min={data.generated_date} max={today()} tabIndex={data.storage_location_id ? 0 : -1}
                                            value={data.received_date} onChange={(e) => setData('received_date', e.target.value)} />}
                                    </FormField>
                                    {tps && (
                                        <p className="self-end pb-2 text-xs text-graphite-500">
                                            {tps.capacity ? `Kapasitas TPS ini: ${Number(tps.capacity).toLocaleString('id-ID')} ${tps.capacity_unit ?? ''}.` : 'Kapasitas TPS ini belum diisi.'}
                                            {tpsUnitDiffers && ' Satuannya berbeda dengan satuan catatan ini, sehingga isinya tidak dibandingkan dengan kapasitas.'}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </div>
                        <FormField label="Notes" name="notes" error={errors.notes} className="sm:col-span-2">
                            {(p) => <Textarea {...p} rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />}
                        </FormField>
                </FormSection>

                </CardContent></Card>

                <FormActions submitLabel="Save Waste Record" processing={processing} onCancel={() => { release(); router.visit(route('waste.dashboard')); }} />
            </form>
        </AuthenticatedLayout>
    );
}
