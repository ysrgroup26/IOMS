import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import StatCard from '@/Components/shared/StatCard';
import EmptyState from '@/Components/shared/EmptyState';
import FilterBar from '@/Components/shared/FilterBar';
import { Panel, NoData } from '@/Components/shared/ManagementPanels';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import AreaNav from '@/Components/hse-equipment/AreaNav';
import ComplianceBadge, { ResultBadge } from '@/Components/hse-equipment/ComplianceBadge';
import EquipmentFormDialog from '@/Components/hse-equipment/EquipmentFormDialog';
import InspectionDialog from '@/Components/hse-equipment/InspectionDialog';
import { COMPLIANCE, COMPLIANCE_ORDER, LIFECYCLES, TONE_DOT, formatDate, relativeDue } from '@/Components/hse-equipment/compliance';
import { cn } from '@/lib/utils';
import {
    HardHat, Plus, ShieldCheck, Wrench, AlertTriangle, CalendarClock, ClipboardCheck, PowerOff,
    ChevronLeft, ChevronRight, ChevronRight as Go, Loader2, X, MapPin,
} from 'lucide-react';

/**
 * SAFETY EQUIPMENT REGISTER (v2.133.0, ADR 074).
 *
 * POSITION -> ATTENTION -> REGISTER, top to bottom:
 *
 *   1. where the fleet stands, as one bar of derived standings;
 *   2. the figures somebody reads first, each one a filter of the register;
 *   3. what to act on now and what comes due next, by name;
 *   4. the register itself: search, filter, open a unit.
 *
 * Every number on this page is a count of units the server returned; a
 * unit with no due date is NOT SCHEDULED, never quietly counted as fine.
 */
export default function EquipmentIndex({ equipment, filters, stats, byType, attention, upcoming, locations, equipmentTypes, assets, companies, can }) {
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [inspecting, setInspecting] = useState(null);
    const [search, setSearch] = useState(filters.q ?? '');
    const [loading, setLoading] = useState(false);
    const firstRender = useRef(true);

    const hasFilters = Boolean(filters.q || filters.type || filters.state || filters.location);
    const empty = stats.total === 0;

    function apply(overrides) {
        const next = { ...filters, ...overrides };
        Object.keys(next).forEach((k) => { if (!next[k]) delete next[k]; });
        router.get(route('safety-equipment.index'), next, {
            preserveState: true, preserveScroll: true, replace: true,
            only: ['equipment', 'filters'],
            onStart: () => setLoading(true), onFinish: () => setLoading(false),
        });
    }

    // Debounced search; the first render must not fire a request.
    useEffect(() => {
        if (firstRender.current) { firstRender.current = false; return undefined; }
        const t = setTimeout(() => apply({ q: search, page: null }), 300);
        return () => clearTimeout(t);
         
    }, [search]);

    function filterState(state) {
        apply({ state, page: null });
        document.getElementById('register')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function openCreate() { setEditing(null); setFormOpen(true); }
    function openEdit(unit) { setEditing(unit); setFormOpen(true); }

    const inService = stats.operational;
    const upToDate = stats.compliant;

    return (
        <AuthenticatedLayout>
            <Head title="Safety Equipment & Compliance" />
            <DashboardShell
                title="Safety Equipment & Compliance"
                subtitle="Unit peralatan keselamatan yang dimiliki perusahaan, posisi inspeksinya, dan apa yang perlu ditindaklanjuti."
                actions={can.manage && (
                    <Button onClick={openCreate} className="w-full sm:w-auto"><Plus className="h-4 w-4" /> Add Equipment</Button>
                )}
            >
                <AreaNav active="register" />

                {empty ? (
                    <EmptyState
                        icon={HardHat}
                        title="No equipment registered yet"
                        description={can.manage
                            ? 'Daftarkan unit pertama: APAR, safety shower, gas detector, atau peralatan keselamatan lain. Setiap unit mendapat ID sendiri dan riwayat inspeksinya.'
                            : 'Belum ada unit yang didaftarkan. Tim HSE yang mendaftarkan peralatan keselamatan.'}
                        action={can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Equipment</Button>}
                    />
                ) : (
                    <>
                        {/* 1. POSITION. Deep navy so the one summary on the page
                            reads as the page's anchor, not as one more card. */}
                        <section aria-labelledby="position-heading" className="overflow-hidden rounded-xl border border-navy-800 bg-gradient-to-br from-navy-950 via-navy-900 to-navy-700 p-4 text-white shadow-panel sm:p-5">
                            <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                                <div>
                                    <p id="position-heading" className="text-[11px] font-bold uppercase tracking-wider text-steel-200">Compliance Position</p>
                                    <p className="mt-1 font-display text-3xl font-extrabold tabular-nums tracking-tight">
                                        {upToDate}<span className="text-lg font-bold text-steel-200"> / {inService}</span>
                                    </p>
                                    <p className="mt-0.5 text-[13px] text-steel-100">
                                        unit in service dengan semua tanggal dalam batas
                                        {stats.not_scheduled > 0 && <>; {stats.not_scheduled} belum punya jadwal</>}
                                    </p>
                                </div>
                                <dl className="grid grid-cols-3 gap-4 text-right text-xs sm:gap-6">
                                    <div><dt className="text-steel-200">Inspected, 30 days</dt><dd className="text-lg font-bold tabular-nums">{stats.inspections_30d}</dd></div>
                                    <div><dt className="text-steel-200">Types in Use</dt><dd className="text-lg font-bold tabular-nums">{byType.filter((t) => t.name !== 'Unclassified').length}</dd></div>
                                    <div><dt className="text-steel-200">Locations</dt><dd className="text-lg font-bold tabular-nums">{locations.length}</dd></div>
                                </dl>
                            </div>
                            <StandingBar stats={stats} onPick={filterState} />
                        </section>

                        {/* 2. THE FIGURES. Each one filters the register below. */}
                        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                            <FilterCard icon={HardHat} value={stats.total} label="Total Units" hint={`${byType.filter((t) => t.name !== 'Unclassified').length} jenis dipakai`} onClick={() => filterState('')} active={!filters.state} />
                            <FilterCard icon={ShieldCheck} value={stats.operational} label="In Service" hint={`${stats.compliant} up to date`} accent={stats.operational > 0 ? 'green' : undefined} onClick={() => filterState('compliant')} active={filters.state === 'compliant'} />
                            <FilterCard icon={PowerOff} value={stats.out_of_service} label="Out of Service" onClick={() => filterState('out_of_service')} active={filters.state === 'out_of_service'} />
                            <FilterCard icon={AlertTriangle} value={stats.overdue} label="Overdue" hint={stats.expired ? `${stats.expired} expired` : 'termasuk kedaluwarsa'} accent={stats.overdue > 0 ? 'red' : undefined} onClick={() => filterState('overdue')} active={filters.state === 'overdue'} />
                            <FilterCard icon={CalendarClock} value={stats.due_soon} label="Due in 30 Days" accent={stats.due_soon > 0 ? 'amber' : undefined} onClick={() => filterState('due_soon')} active={filters.state === 'due_soon'} />
                            <FilterCard icon={Wrench} value={stats.action_required} label="Action Required" hint="hasil inspeksi Fail atau Needs Action" accent={stats.action_required > 0 ? 'red' : undefined} onClick={() => filterState('action_required')} active={filters.state === 'action_required'} />
                        </div>

                        {/* 3. ATTENTION, BY NAME. */}
                        <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                            <Panel title="Needs Attention" description="Overdue, kedaluwarsa, atau menunggu tindakan perbaikan.">
                                {attention.length === 0
                                    ? <NoData title="Nothing outstanding" description="Tidak ada unit yang lewat jatuh tempo atau menunggu tindakan." />
                                    : <UnitList units={attention} />}
                            </Panel>
                            <Panel title="Coming Due" description={`Jatuh tempo dalam ${stats.due_soon_days} hari ke depan.`}>
                                {upcoming.length === 0
                                    ? <NoData title="Nothing due soon" description={`Tidak ada tanggal jatuh tempo dalam ${stats.due_soon_days} hari ke depan.`} />
                                    : <UnitList units={upcoming} />}
                            </Panel>
                            <Panel title="Register Quality" description="Data yang belum lengkap membuat status tidak bisa dinilai.">
                                <ul className="space-y-2 text-xs">
                                    <QualityRow count={stats.not_scheduled} label="units without a due date" onClick={() => filterState('not_scheduled')} />
                                    <QualityRow count={stats.unclassified} label="units without an equipment type" onClick={() => apply({ type: 'unclassified', state: null, page: null })} />
                                    <QualityRow count={stats.without_location} label="in-service units without a location" />
                                    <QualityRow count={stats.without_code} label="units without an Equipment ID" hint="Dibuat otomatis saat unit disimpan lagi." />
                                </ul>
                            </Panel>
                        </div>

                        {/* 4. THE REGISTER. */}
                        <section id="register" aria-labelledby="register-heading" className="scroll-mt-4">
                            <div className="mb-2 flex items-baseline justify-between gap-2">
                                <h2 id="register-heading" className="text-sm font-bold text-navy-900 dark:text-slate-100">Equipment Register</h2>
                                <p className="text-xs text-graphite-500 dark:text-slate-400" aria-live="polite">
                                    {loading ? 'Memuat...' : `${equipment.total} unit${hasFilters ? ' cocok dengan filter' : ''}`}
                                </p>
                            </div>

                            <FilterBar>
                                <FilterBar.Search placeholder="Search ID, name, serial, location..." value={search} onChange={(e) => setSearch(e.target.value)} />
                                <FilterSelect label="Type" value={filters.type} onChange={(v) => apply({ type: v, page: null })}
                                    options={[...equipmentTypes.map((t) => ({ value: String(t.id), label: t.name })), ...(stats.unclassified ? [{ value: 'unclassified', label: 'Unclassified' }] : [])]} />
                                <FilterSelect label="Status" value={filters.state} onChange={(v) => apply({ state: v, page: null })}
                                    options={[{ value: 'attention', label: 'Needs Attention' }, ...COMPLIANCE_ORDER.map((k) => ({ value: k, label: COMPLIANCE[k].label }))]} />
                                {locations.length > 0 && (
                                    <FilterSelect label="Location" value={filters.location} onChange={(v) => apply({ location: v, page: null })}
                                        options={locations.map((l) => ({ value: l, label: l }))} />
                                )}
                                {hasFilters && (
                                    <Button variant="ghost" size="sm" onClick={() => { setSearch(''); apply({ q: null, type: null, state: null, location: null, page: null }); }}>
                                        <X className="h-3.5 w-3.5" /> Clear
                                    </Button>
                                )}
                                {loading && <Loader2 className="h-4 w-4 animate-spin text-steel-500" aria-hidden="true" />}
                            </FilterBar>

                            <div className={cn('transition-opacity', loading && 'opacity-60')}>
                                {equipment.data.length === 0 ? (
                                    <EmptyState icon={HardHat} title="No units match" description="Tidak ada unit yang cocok dengan pencarian atau filter ini." action={
                                        <Button variant="outline" size="sm" onClick={() => { setSearch(''); apply({ q: null, type: null, state: null, location: null, page: null }); }}>Clear filters</Button>
                                    } />
                                ) : (
                                    <>
                                        <RegisterTable units={equipment.data} can={can} onInspect={setInspecting} onEdit={openEdit} />
                                        <RegisterCards units={equipment.data} />
                                    </>
                                )}
                            </div>

                            {equipment.last_page > 1 && (
                                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                                    <span>Page {equipment.current_page} of {equipment.last_page}</span>
                                    <div className="flex gap-2">
                                        <button type="button" aria-label="Previous page" disabled={!equipment.prev_page_url} onClick={() => apply({ page: equipment.current_page - 1 })} className="rounded-md border border-steel-200 bg-white p-1.5 disabled:opacity-40 dark:border-slate-700 dark:bg-slate-900">
                                            <ChevronLeft className="h-4 w-4" />
                                        </button>
                                        <button type="button" aria-label="Next page" disabled={!equipment.next_page_url} onClick={() => apply({ page: equipment.current_page + 1 })} className="rounded-md border border-steel-200 bg-white p-1.5 disabled:opacity-40 dark:border-slate-700 dark:bg-slate-900">
                                            <ChevronRight className="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                            )}
                        </section>
                    </>
                )}
            </DashboardShell>

            {can.manage && (
                <EquipmentFormDialog open={formOpen} onClose={() => setFormOpen(false)} unit={editing} equipmentTypes={equipmentTypes} assets={assets} companies={companies} />
            )}
            {can.manage && inspecting && (
                <InspectionDialog open onClose={() => setInspecting(null)} unit={inspecting}
                    intervalMonths={equipmentTypes.find((t) => t.id === inspecting.type_id)?.interval_months} />
            )}
        </AuthenticatedLayout>
    );
}

/** The fleet as one bar: each segment is a standing, sized by its count, and filters the register. */
function StandingBar({ stats, onPick }) {
    const segments = COMPLIANCE_ORDER
        .map((key) => ({ key, count: key === 'overdue' ? stats.overdue - stats.expired : stats[key] ?? 0 }))
        .filter((s) => s.count > 0);
    const total = segments.reduce((n, s) => n + s.count, 0);
    if (total === 0) return null;

    return (
        <div className="mt-4">
            <div className="flex h-2.5 overflow-hidden rounded-full bg-white/10" role="img" aria-label={segments.map((s) => `${COMPLIANCE[s.key].label}: ${s.count}`).join(', ')}>
                {segments.map((s) => (
                    <span key={s.key} className={cn('h-full', TONE_DOT[COMPLIANCE[s.key].tone])} style={{ width: `${(s.count / total) * 100}%` }} />
                ))}
            </div>
            <ul className="mt-2.5 flex flex-wrap gap-x-4 gap-y-1.5 text-xs">
                {segments.map((s) => (
                    <li key={s.key}>
                        <button type="button" onClick={() => onPick(s.key)} className="inline-flex items-center gap-1.5 rounded text-steel-100 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
                            <span className={cn('h-2 w-2 rounded-full', TONE_DOT[COMPLIANCE[s.key].tone])} aria-hidden="true" />
                            {COMPLIANCE[s.key].label} <span className="font-bold tabular-nums text-white">{s.count}</span>
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** A StatCard that filters the register instead of navigating away. */
function FilterCard({ onClick, active, ...props }) {
    return (
        <button type="button" onClick={onClick} aria-pressed={active}
            className={cn('rounded-xl text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500', active && 'ring-2 ring-brand-500/60')}>
            <StatCard {...props} />
        </button>
    );
}

function FilterSelect({ label, value, onChange, options }) {
    return (
        <Select value={value || '__all'} onValueChange={(v) => onChange(v === '__all' ? null : v)}>
            <SelectTrigger aria-label={label} className="h-9 w-full border-steel-200 bg-white sm:w-44 dark:bg-slate-900">
                <SelectValue placeholder={label} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="__all">All {label === 'Status' ? 'statuses' : `${label.toLowerCase()}s`}</SelectItem>
                {options.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
            </SelectContent>
        </Select>
    );
}

function QualityRow({ count, label, hint, onClick }) {
    const body = (
        <>
            <span className={cn('min-w-[2rem] rounded-md px-1.5 py-0.5 text-center font-bold tabular-nums', count > 0 ? 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300')}>{count}</span>
            <span className="text-graphite-600 dark:text-slate-300">{label}{hint && count > 0 && <span className="block text-[11px] text-graphite-400">{hint}</span>}</span>
        </>
    );

    return (
        <li>
            {onClick && count > 0
                ? <button type="button" onClick={onClick} className="flex w-full items-start gap-2 rounded text-left hover:underline">{body}</button>
                : <div className="flex items-start gap-2">{body}</div>}
        </li>
    );
}

function DueText({ due }) {
    if (!due) return <span className="text-graphite-400">No date set</span>;
    return (
        <span>
            <span className="text-graphite-500 dark:text-slate-400">{LIFECYCLES[due.key]?.label}: </span>
            <span className="font-medium text-graphite-800 dark:text-slate-200">{formatDate(due.date)}</span>
            <span className={cn('ml-1 text-[11px]', due.state === 'overdue' || due.state === 'expired' ? 'text-red-600 dark:text-red-400' : due.state === 'due_soon' ? 'text-amber-700 dark:text-amber-400' : 'text-graphite-400')}>
                ({relativeDue(due.date)})
            </span>
        </span>
    );
}

function UnitList({ units }) {
    return (
        <ul className="divide-y divide-steel-100 dark:divide-slate-800">
            {units.map((u) => (
                <li key={u.id}>
                    <Link href={route('safety-equipment.show', u.id)} className="group flex items-center gap-3 py-2 text-xs">
                        <div className="min-w-0 flex-1">
                            <p className="truncate font-semibold text-navy-900 group-hover:text-brand-700 dark:text-slate-100">
                                {u.equipment_code && <span className="mr-1.5 font-mono text-[11px] text-steel-600 dark:text-steel-300">{u.equipment_code}</span>}
                                {u.name}
                            </p>
                            <p className="truncate text-graphite-500 dark:text-slate-400"><DueText due={u.next_due} /></p>
                        </div>
                        <ComplianceBadge state={u.compliance} />
                    </Link>
                </li>
            ))}
        </ul>
    );
}

function RegisterTable({ units, can, onInspect, onEdit }) {
    return (
        <Card className="hidden md:block">
            <CardContent className="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Equipment ID</TableHead>
                            <TableHead>Equipment</TableHead>
                            <TableHead>Location</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Next Due</TableHead>
                            <TableHead>Last Inspection</TableHead>
                            <TableHead className="text-right"><span className="sr-only">Actions</span></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {units.map((u) => (
                            <TableRow key={u.id}>
                                <TableCell className="whitespace-nowrap font-mono text-xs font-semibold text-navy-800 dark:text-steel-200">
                                    {u.equipment_code || <span className="font-sans font-normal text-graphite-400">Not assigned</span>}
                                </TableCell>
                                <TableCell>
                                    <Link href={route('safety-equipment.show', u.id)} className="font-semibold text-navy-900 hover:text-brand-700 hover:underline dark:text-slate-100">{u.name}</Link>
                                    <p className="text-xs text-graphite-500 dark:text-slate-400">{u.type_name || 'Unclassified'}{u.brand && ` · ${u.brand}`}</p>
                                </TableCell>
                                <TableCell className="text-sm">{u.location || <span className="text-graphite-400">Not recorded</span>}</TableCell>
                                <TableCell><ComplianceBadge state={u.compliance} /></TableCell>
                                <TableCell className="text-xs"><DueText due={u.next_due} /></TableCell>
                                <TableCell className="text-xs">
                                    {u.last_inspection_date ? (
                                        <span className="flex items-center gap-1.5">{formatDate(u.last_inspection_date)} <ResultBadge result={u.last_result} /></span>
                                    ) : <span className="text-graphite-400">Not inspected</span>}
                                </TableCell>
                                <TableCell className="text-right">
                                    <div className="flex justify-end gap-1">
                                        {can.manage && u.status === 'active' && (
                                            <Button variant="outline" size="sm" onClick={() => onInspect(u)}><ClipboardCheck className="h-3.5 w-3.5" /> Inspect</Button>
                                        )}
                                        {can.manage && <Button variant="ghost" size="sm" onClick={() => onEdit(u)}>Edit</Button>}
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={route('safety-equipment.show', u.id)} aria-label={`Open ${u.equipment_code || u.name}`}><Go className="h-4 w-4" /></Link>
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

/** Below md the register is a list of cards: a seven-column table cannot be read on a phone. */
function RegisterCards({ units }) {
    return (
        <ul className="space-y-2 md:hidden">
            {units.map((u) => (
                <li key={u.id}>
                    <Link href={route('safety-equipment.show', u.id)} className="block rounded-xl border border-steel-200 bg-white p-3 shadow-card dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                                <p className="font-mono text-[11px] font-semibold text-steel-700 dark:text-steel-300">{u.equipment_code || 'No ID yet'}</p>
                                <p className="truncate text-sm font-semibold text-navy-900 dark:text-slate-100">{u.name}</p>
                                <p className="text-xs text-graphite-500 dark:text-slate-400">{u.type_name || 'Unclassified'}</p>
                            </div>
                            <ComplianceBadge state={u.compliance} />
                        </div>
                        <div className="mt-2 space-y-0.5 text-xs">
                            <p className="flex items-center gap-1 text-graphite-600 dark:text-slate-300"><MapPin className="h-3 w-3" aria-hidden="true" /> {u.location || 'Location not recorded'}</p>
                            <p><DueText due={u.next_due} /></p>
                        </div>
                    </Link>
                </li>
            ))}
        </ul>
    );
}
