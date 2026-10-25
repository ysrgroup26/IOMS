import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import { FieldGrid, Field, DetailSection } from '@/Components/shared/DetailFields';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import AreaNav from '@/Components/hse-equipment/AreaNav';
import ComplianceBadge, { ResultBadge } from '@/Components/hse-equipment/ComplianceBadge';
import EquipmentFormDialog from '@/Components/hse-equipment/EquipmentFormDialog';
import InspectionDialog from '@/Components/hse-equipment/InspectionDialog';
import QrLabel from '@/Components/hse-equipment/QrLabel';
import { COMPLIANCE, CONDITIONS, LIFECYCLES, TONE_CLASSES, formatDate, relativeDue } from '@/Components/hse-equipment/compliance';
import { cn } from '@/lib/utils';
import { ArrowLeft, ClipboardCheck, Pencil, Trash2, HardHat, CalendarClock, History, QrCode, ClipboardList } from 'lucide-react';

/**
 * One unit's record (v2.133.0, ADR 074): who it is, where it stands on each
 * lifecycle its type tracks, every inspection ever recorded against it, and
 * the audit trail. The standing in the header is the server's derivation,
 * the same one the register shows.
 */
export default function EquipmentShow({ equipment, inspections, activities, equipmentTypes = [], assets = [], companies = [], can }) {
    const [editOpen, setEditOpen] = useState(false);
    const [inspectOpen, setInspectOpen] = useState(false);
    const standing = COMPLIANCE[equipment.compliance];
    const title = equipment.equipment_code ? `${equipment.equipment_code} · ${equipment.name}` : equipment.name;

    function destroy() {
        const label = equipment.equipment_code || equipment.name;
        if (confirm(`Remove ${label} from the register? Its inspection history is removed with it. This cannot be undone.`)) {
            router.delete(route('safety-equipment.destroy', equipment.id));
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title={title} />

            <div className="mb-3">
                <Link href={route('safety-equipment.index')} className="inline-flex items-center gap-1 text-xs font-semibold text-steel-700 hover:text-navy-900 dark:text-steel-300">
                    <ArrowLeft className="h-3.5 w-3.5" /> Equipment Register
                </Link>
            </div>

            {/* Record header: deep navy, the unit's ID set as an identifier. */}
            <header className="mb-4 overflow-hidden rounded-xl border border-navy-800 bg-gradient-to-br from-navy-950 via-navy-900 to-navy-700 px-4 py-4 text-white shadow-panel sm:px-5">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex min-w-0 items-start gap-3">
                        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/15">
                            <HardHat className="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div className="min-w-0">
                            <p className="font-mono text-xs font-semibold tracking-wide text-steel-200">{equipment.equipment_code || 'Equipment ID not assigned'}</p>
                            <h1 className="font-display text-xl font-extrabold leading-tight tracking-tight sm:text-2xl">{equipment.name}</h1>
                            <p className="mt-0.5 text-[13px] text-steel-100">
                                {equipment.type_name || 'Unclassified'}{equipment.location && ` · ${equipment.location}`}
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-col items-start gap-2 sm:items-end">
                        <ComplianceBadge state={equipment.compliance} className="border-white/20 bg-white/95" />
                        <p className="max-w-xs text-[11px] text-steel-100 sm:text-right">{standing?.hint}</p>
                    </div>
                </div>
                {can.manage && (
                    <div className="mt-4 flex flex-wrap gap-2 border-t border-white/10 pt-3">
                        {equipment.status === 'active' && (
                            <Button size="sm" onClick={() => setInspectOpen(true)} className="bg-white text-navy-900 hover:bg-steel-50"><ClipboardCheck className="h-3.5 w-3.5" /> Record Inspection</Button>
                        )}
                        <Button size="sm" variant="outline" onClick={() => setEditOpen(true)} className="border-white/25 bg-transparent text-white hover:bg-white/10 hover:text-white"><Pencil className="h-3.5 w-3.5" /> Edit</Button>
                        <Button size="sm" variant="ghost" onClick={destroy} className="text-red-200 hover:bg-red-500/15 hover:text-red-100"><Trash2 className="h-3.5 w-3.5" /> Remove</Button>
                    </div>
                )}
            </header>

            <AreaNav active="register" />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {/* Lifecycle: one tile per date the type tracks. */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2"><CalendarClock className="h-4 w-4 text-steel-500" aria-hidden="true" /> Lifecycle</CardTitle>
                            <CardDescription>Tanggal yang berlaku untuk jenis peralatan ini. Status unit mengikuti tanggal yang paling mendesak.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {equipment.lifecycles.length === 0 ? (
                                <p className="text-sm text-graphite-500">Jenis ini tidak melacak tanggal apa pun.</p>
                            ) : (
                                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    {equipment.lifecycles.map((l) => {
                                        const meta = COMPLIANCE[l.state];
                                        return (
                                            <div key={l.key} className={cn('rounded-lg border px-3 py-2.5', TONE_CLASSES[meta.tone])}>
                                                <p className="text-[11px] font-bold uppercase tracking-wider opacity-80">{LIFECYCLES[l.key].fieldLabel}</p>
                                                <p className="mt-0.5 text-base font-bold tabular-nums">{l.date ? formatDate(l.date) : 'Not scheduled'}</p>
                                                <p className="text-[11px] font-medium">{meta.label}{l.date && ` · ${relativeDue(l.date)}`}</p>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                            {equipment.interval_months && (
                                <p className="mt-2 text-[11px] text-graphite-500 dark:text-slate-400">Interval inspeksi jenis ini: {equipment.interval_months} bulan.</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Inspection history: every row, newest first, never edited. */}
                    <Card>
                        <CardHeader className="flex-row items-start justify-between gap-3 pb-2">
                            <div>
                                <CardTitle className="flex items-center gap-2"><ClipboardList className="h-4 w-4 text-steel-500" aria-hidden="true" /> Inspection History</CardTitle>
                                <CardDescription>{inspections.length} inspeksi tercatat untuk unit ini.</CardDescription>
                            </div>
                            {can.manage && equipment.status === 'active' && (
                                <Button size="sm" variant="outline" onClick={() => setInspectOpen(true)}><ClipboardCheck className="h-3.5 w-3.5" /> Record</Button>
                            )}
                        </CardHeader>
                        <CardContent>
                            {inspections.length === 0 ? (
                                <p className="rounded-lg border border-dashed border-steel-200 bg-steel-50/50 px-3 py-4 text-center text-xs text-graphite-500 dark:border-slate-700 dark:bg-slate-900/40">
                                    Belum ada inspeksi tercatat. Status unit dinilai dari tanggal jatuh temponya sampai inspeksi pertama dicatat.
                                </p>
                            ) : (
                                <ol className="relative space-y-3 border-l border-steel-200 pl-4 dark:border-slate-700">
                                    {inspections.map((i) => (
                                        <li key={i.id} className="relative">
                                            <span className="absolute -left-[21px] top-1.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-steel-400 dark:border-slate-900" aria-hidden="true" />
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-semibold text-navy-900 dark:text-slate-100">{formatDate(i.inspection_date)}</span>
                                                <ResultBadge result={i.result} />
                                                <span className="text-xs text-graphite-500 dark:text-slate-400">Condition: {CONDITIONS[i.condition] ?? i.condition}</span>
                                            </div>
                                            <p className="text-xs text-graphite-500 dark:text-slate-400">
                                                {i.inspector ? `By ${i.inspector}` : 'Inspector not recorded'}
                                                {i.next_inspection_due && ` · next due ${formatDate(i.next_inspection_due)}`}
                                            </p>
                                            {i.findings && <p className="mt-1 text-sm text-graphite-700 dark:text-slate-300">{i.findings}</p>}
                                            {i.notes && <p className="mt-0.5 text-xs text-graphite-500 dark:text-slate-400">{i.notes}</p>}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card>
                        <CardContent className="space-y-4 pt-5">
                            <DetailSection title="Identity">
                                <FieldGrid columns={2}>
                                    <Field label="Equipment ID" value={equipment.equipment_code} mono />
                                    <Field label="Type" value={equipment.type_name || 'Unclassified'} />
                                    <Field label="Brand" value={equipment.brand} />
                                    <Field label="Model" value={equipment.model} />
                                    <Field label="Serial Number" value={equipment.serial_number} mono />
                                    <Field label="Status" value={equipment.status === 'active' ? 'In Service' : 'Out of Service'} />
                                    <Field label="Location" value={equipment.location} span={2} />
                                    <Field label="Operating Unit" value={equipment.company?.name} span={2} />
                                    <Field label="Commissioned" value={formatDate(equipment.commissioned_at)} />
                                    <Field label="Last Inspection" value={formatDate(equipment.last_inspection_date)} />
                                    <Field label="Company Asset" value={equipment.asset ? `${equipment.asset.asset_code ? `${equipment.asset.asset_code} · ` : ''}${equipment.asset.name}` : null} span={2} />
                                </FieldGrid>
                            </DetailSection>
                            {equipment.notes && (
                                <DetailSection title="Notes">
                                    <p className="whitespace-pre-line text-sm text-graphite-700 dark:text-slate-300">{equipment.notes}</p>
                                </DetailSection>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2"><QrCode className="h-4 w-4 text-steel-500" aria-hidden="true" /> Equipment Label</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <QrLabel url={route('safety-equipment.show', equipment.id)} code={equipment.equipment_code} name={equipment.name} location={equipment.location} />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2"><History className="h-4 w-4 text-steel-500" aria-hidden="true" /> Activity</CardTitle>
                        </CardHeader>
                        <CardContent><ActivityTimeline activities={activities} /></CardContent>
                    </Card>
                </div>
            </div>

            {can.manage && (
                <>
                    <EquipmentFormDialog open={editOpen} onClose={() => setEditOpen(false)} unit={equipment} equipmentTypes={equipmentTypes} assets={assets} companies={companies} />
                    <InspectionDialog open={inspectOpen} onClose={() => setInspectOpen(false)} unit={equipment} intervalMonths={equipment.interval_months} />
                </>
            )}
        </AuthenticatedLayout>
    );
}
