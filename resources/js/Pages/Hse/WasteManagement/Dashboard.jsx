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
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { cn } from '@/lib/utils';
import {
    Recycle, AlertTriangle, Truck, CheckCircle2, Warehouse, Settings, Boxes, Plus,
    FlaskConical, Leaf, Timer, FileWarning, PackageOpen, ArrowRight, ChevronLeft, ChevronRight, Loader2, X, MapPin, Lock,
} from 'lucide-react';

/**
 * WASTE MANAGEMENT WORKSPACE (v2.134.0, ADR 077).
 *
 * Every number on this page is a filter of the records listed at its foot:
 * a summary card, a lifecycle stage, a quick action -- each narrows the same
 * server-side list, so a count is always one click from the rows it counts.
 *
 * Two limits are kept apart, because they are different questions:
 *   HOW LONG   a waste type's storage_limit_days (the company's setting);
 *   HOW MUCH   a TPS's declared capacity, compared only where units agree.
 * Neither is a regulatory verdict, and nothing here says "compliant".
 */

const STATUS = {
    generated: { label: 'Generated', tone: 'neutral' },
    stored: { label: 'Stored', tone: 'info' },
    scheduled_pickup: { label: 'Awaiting Pickup', tone: 'warning' },
    in_transit: { label: 'In Transit', tone: 'info' },
    disposed: { label: 'Handed Over / Disposed', tone: 'success' },
    closed: { label: 'Closed', tone: 'neutral' },
};

// The lifecycle as this data model records it. A record is created when the
// waste is generated, so "generated" and "recorded" are one stage here.
const STAGES = [
    { status: 'generated', label: 'Generated & Recorded', hint: 'Tercatat, belum masuk TPS.' },
    { status: 'stored', label: 'Stored (TPS)', hint: 'Disimpan di lokasi penyimpanan.' },
    { status: 'scheduled_pickup', label: 'Awaiting Pickup', hint: 'Pengangkutan dijadwalkan.' },
    { status: 'in_transit', label: 'In Transit', hint: 'Sudah diambil transporter.' },
    { status: 'disposed', label: 'Handed Over / Disposed', hint: 'Diserahkan atau dimusnahkan.' },
    { status: 'closed', label: 'Closed', hint: 'Catatan ditutup.' },
];

const GAPS = {
    no_storage_location: 'No storage location (TPS)',
    no_waste_code: 'B3 type has no waste code',
    no_vendor: 'No transporter / vendor',
    no_manifest: 'B3 movement has no manifest number',
    no_handover_document: 'No handover document attached',
};

const PICKUP = { scheduled: 'Scheduled', picked_up: 'Picked Up', disposed: 'Disposed' };

const TONE = {
    danger: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300',
    warning: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-300',
    success: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-300',
    info: 'border-steel-200 bg-steel-50 text-steel-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
    neutral: 'border-graphite-200 bg-graphite-100 text-graphite-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400',
};

function Chip({ tone = 'neutral', children, className }) {
    return <span className={cn('inline-flex items-center whitespace-nowrap rounded-md border px-1.5 py-px text-[11px] font-semibold', TONE[tone], className)}>{children}</span>;
}

function CategoryChip({ category }) {
    if (!category) return <Chip>No type</Chip>;
    return category === 'b3' ? <Chip tone="danger">B3</Chip> : <Chip tone="info">Non-B3</Chip>;
}

function StatusChip({ status }) {
    const s = STATUS[status] ?? { label: status, tone: 'neutral' };
    return <Chip tone={s.tone}>{s.label}</Chip>;
}

function fmtDate(v) {
    if (!v) return null;
    const d = new Date(`${String(v).slice(0, 10)}T00:00:00`);
    return Number.isNaN(d.getTime()) ? null : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

function qty(q, unit) {
    if (q === null || q === undefined) return '-';
    return `${Number(q).toLocaleString('id-ID', { maximumFractionDigits: 2 })} ${unit ?? ''}`.trim();
}

export default function WasteDashboard({
    summary, lifecycle, directPickupCount, priorities, storage, recentMovements,
    records, filters, options, capacityWarningPercent, can,
}) {
    const [search, setSearch] = useState(filters.q ?? '');
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [mode, setMode] = useState(null); // the quick action whose rows are listed
    const firstRender = useRef(true);
    const hasFilters = ['q', 'view', 'status', 'category', 'waste_type_id', 'storage_location_id', 'pickup', 'from', 'to'].some((k) => filters[k]);

    function apply(overrides, keepMode = false) {
        const next = { ...filters, ...overrides };
        Object.keys(next).forEach((k) => { if (!next[k]) delete next[k]; });
        if (!keepMode) setMode(null);
        router.get(route('waste.dashboard'), next, {
            preserveState: true, preserveScroll: true, replace: true,
            only: ['records', 'filters'],
            onStart: () => { setLoading(true); setFailed(false); },
            onError: () => setFailed(true),
            onFinish: () => setLoading(false),
        });
    }

    function show(overrides, keepMode = false) {
        apply({ q: null, view: null, status: null, category: null, waste_type_id: null, storage_location_id: null, pickup: null, from: null, to: null, page: null, ...overrides }, keepMode);
        if (!overrides.q) setSearch('');
        document.getElementById('records')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    useEffect(() => {
        if (firstRender.current) { firstRender.current = false; return undefined; }
        const t = setTimeout(() => apply({ q: search, page: null }, true), 300);
        return () => clearTimeout(t);
         
    }, [search]);

    function startAction(key) {
        setMode(key);
        const view = { storage: 'onsite', pickup: 'ready_for_pickup', handover: 'pickup_transit' }[key];
        show({ view }, true);
    }

    const empty = summary.total === 0;

    return (
        <AuthenticatedLayout>
            <Head title="Waste Management" />
            <DashboardShell
                title="Waste Management"
                subtitle="Limbah B3 dan non-B3 dari timbulan, penyimpanan di TPS, pengangkutan, sampai serah terima dan pemusnahan."
                actions={
                    <div className="flex w-full flex-wrap gap-2 sm:w-auto">
                        <Link href={route('waste.master')} className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-steel-200 bg-white px-3 text-xs font-semibold text-navy-800 hover:bg-steel-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                            <Settings className="h-3.5 w-3.5" /> Master Data
                        </Link>
                        <Link href={route('waste-containers.index')} className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-steel-200 bg-white px-3 text-xs font-semibold text-navy-800 hover:bg-steel-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                            <Boxes className="h-3.5 w-3.5" /> Containers
                        </Link>
                        {can.manage && (
                            <Link href={route('waste-records.create')}>
                                <Button className="h-9"><Plus className="h-4 w-4" /> Record Generated Waste</Button>
                            </Link>
                        )}
                    </div>
                }
            >
                {empty ? (
                    <EmptyState
                        icon={Recycle}
                        title="No waste recorded yet"
                        description={can.manage
                            ? 'Catat timbulan limbah pertama. Siapkan jenis limbah (B3/non-B3, kode limbah, batas lama simpan) dan lokasi TPS di Master Data lebih dulu bila belum ada.'
                            : 'Belum ada catatan limbah. Tim HSE yang mencatat timbulan limbah.'}
                        action={can.manage && (
                            <div className="flex flex-wrap justify-center gap-2">
                                <Link href={route('waste-records.create')}><Button><Plus className="h-4 w-4" /> Record Generated Waste</Button></Link>
                                <Link href={route('waste.master')}><Button variant="outline">Open Master Data</Button></Link>
                            </div>
                        )}
                    />
                ) : (
                    <>
                        {/* 1. SUMMARY: each card filters the records below. */}
                        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                            <FilterCard icon={Recycle} value={summary.total} label="Total Records" onClick={() => show({})} active={!hasFilters} />
                            <FilterCard icon={FlaskConical} value={summary.b3_stored} label="B3 Stored" hint="masih di lokasi" accent={summary.b3_stored > 0 ? 'amber' : undefined} onClick={() => show({ view: 'b3_stored' })} active={filters.view === 'b3_stored'} />
                            <FilterCard icon={Leaf} value={summary.non_b3_stored} label="Non-B3 Stored" hint="masih di lokasi" onClick={() => show({ view: 'non_b3_stored' })} active={filters.view === 'non_b3_stored'} />
                            <FilterCard icon={Truck} value={summary.pickup_transit} label="Awaiting Pickup / In Transit" onClick={() => show({ view: 'pickup_transit' })} active={filters.view === 'pickup_transit'} />
                            <FilterCard icon={CheckCircle2} value={summary.done} label="Disposed / Closed" onClick={() => show({ view: 'done' })} active={filters.view === 'done'} />
                            <FilterCard icon={Timer} value={summary.approaching} label="Approaching Storage Limit" hint="7 hari terakhir batas lama simpan" accent={summary.approaching > 0 ? 'amber' : undefined} onClick={() => show({ view: 'approaching' })} active={filters.view === 'approaching'} />
                            <FilterCard icon={AlertTriangle} value={summary.overdue} label="Storage Limit Exceeded" hint="melewati batas lama simpan" accent={summary.overdue > 0 ? 'red' : undefined} onClick={() => show({ view: 'overdue' })} active={filters.view === 'overdue'} />
                            <FilterCard icon={FileWarning} value={summary.incomplete} label="Incomplete Records" hint="data serah terima belum lengkap" accent={summary.incomplete > 0 ? 'amber' : undefined} onClick={() => show({ view: 'incomplete' })} active={filters.view === 'incomplete'} />
                        </div>

                        {/* 2. LIFECYCLE: select a stage to list its records. */}
                        <section aria-labelledby="lifecycle-heading" className="rounded-xl border border-navy-800 bg-gradient-to-br from-navy-950 via-navy-900 to-navy-700 p-4 text-white shadow-panel">
                            <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                                <h2 id="lifecycle-heading" className="text-[11px] font-bold uppercase tracking-wider text-steel-200">Waste Lifecycle</h2>
                                {directPickupCount > 0 && (
                                    <p className="text-[11px] text-steel-100">{directPickupCount} catatan langsung diangkut tanpa masuk TPS.</p>
                                )}
                            </div>
                            <ol className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                                {STAGES.map((stage, i) => {
                                    const count = lifecycle.find((l) => l.status === stage.status)?.count ?? 0;
                                    const active = filters.status === stage.status;
                                    return (
                                        <li key={stage.status}>
                                            <button
                                                type="button"
                                                onClick={() => show(active ? {} : { status: stage.status })}
                                                aria-pressed={active}
                                                className={cn(
                                                    'group relative flex h-full w-full flex-col rounded-lg border px-3 py-2.5 text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70',
                                                    active ? 'border-white/60 bg-white/15' : 'border-white/10 bg-white/[0.04] hover:bg-white/10'
                                                )}
                                            >
                                                <span className="text-[10px] font-bold uppercase tracking-wider text-steel-200">Step {i + 1}</span>
                                                <span className="mt-0.5 text-sm font-semibold leading-tight">{stage.label}</span>
                                                <span className="mt-1 font-display text-2xl font-extrabold tabular-nums">{count}</span>
                                                <span className="text-[11px] text-steel-100">{stage.hint}</span>
                                                {i < STAGES.length - 1 && <ArrowRight className="absolute -right-2.5 top-1/2 hidden h-4 w-4 -translate-y-1/2 text-steel-300 lg:block" aria-hidden="true" />}
                                            </button>
                                        </li>
                                    );
                                })}
                            </ol>
                        </section>

                        {/* 3. PRIORITIES and QUICK ACTIONS */}
                        <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                            <Panel className="lg:col-span-2" title="Priority & Follow-up" description="Batas lama simpan, data yang belum lengkap, dan limbah yang menunggu serah terima.">
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <PriorityGroup title="Storage limit exceeded" tone="danger" count={priorities.counts.overdue} items={priorities.overdue} onAll={() => show({ view: 'overdue' })}
                                        detail={(r) => `${r.days_in_storage} hari disimpan, batas ${r.storage_limit_days} hari`} />
                                    <PriorityGroup title="Approaching storage limit" tone="warning" count={priorities.counts.approaching} items={priorities.approaching} onAll={() => show({ view: 'approaching' })}
                                        detail={(r) => `sisa ${r.days_left} hari (batas ${r.storage_limit_days} hari)`} />
                                    <PriorityGroup title="Incomplete records" tone="warning" count={priorities.counts.incomplete} items={priorities.incomplete} onAll={() => show({ view: 'incomplete' })}
                                        detail={(r) => r.gaps.map((g) => GAPS[g] ?? g).join(', ')}
                                        hrefFor={(r) => (r.gaps.includes('no_storage_location') && can.manage ? route('waste-records.show', { wasteRecord: r.id, action: 'storage' }) : route('waste-records.show', r.id))} />
                                    <PriorityGroup title="Awaiting handover" tone="info" count={priorities.counts.awaiting_handover} items={priorities.awaiting_handover} onAll={() => show({ view: 'pickup_transit', status: 'scheduled_pickup' })}
                                        detail={(r) => `${qty(r.quantity, r.unit)} · ${r.vendor || 'vendor belum dicatat'}`}
                                        hrefFor={(r) => (can.manage ? route('waste-records.show', { wasteRecord: r.id, action: 'movement' }) : route('waste-records.show', r.id))} />
                                </div>
                            </Panel>
                            <Panel title="Quick Actions" description="Pilih tindakan, lalu pilih catatannya dari daftar di bawah.">
                                {can.manage ? (
                                    <div className="grid gap-2">
                                        <Link href={route('waste-records.create')} className="flex items-center gap-2 rounded-lg border border-steel-200 px-3 py-2 text-sm font-semibold text-navy-900 hover:bg-steel-50 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-800">
                                            <Plus className="h-4 w-4 text-brand-600" /> Record Generated Waste
                                        </Link>
                                        <ActionButton active={mode === 'storage'} icon={Warehouse} label="Update Storage (TPS)" onClick={() => startAction('storage')} />
                                        <ActionButton active={mode === 'pickup'} icon={Truck} label="Schedule Pickup" onClick={() => startAction('pickup')} />
                                        <ActionButton active={mode === 'handover'} icon={CheckCircle2} label="Record Handover / Disposal" onClick={() => startAction('handover')} />
                                    </div>
                                ) : (
                                    <p className="flex items-start gap-2 rounded-lg border border-dashed border-steel-200 bg-steel-50/50 px-3 py-3 text-xs text-graphite-500 dark:border-slate-700 dark:bg-slate-900/40">
                                        <Lock className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        Pencatatan, penyimpanan, dan serah terima limbah hanya dapat dilakukan oleh HSE atau Super Admin. Anda tetap dapat melihat semua catatan.
                                    </p>
                                )}
                            </Panel>
                        </div>

                        {/* 4. STORAGE AND MOVEMENT */}
                        <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                            <Panel className="lg:col-span-2" title="Storage (TPS) Inventory"
                                description={`Isi setiap TPS saat ini. Kapasitas fisik dibandingkan hanya bila satuannya sama; ditandai mulai ${capacityWarningPercent}% (ambang tampilan, bukan ketentuan regulasi). Lama simpan mengikuti batas per jenis limbah.`}>
                                {storage.length === 0 ? (
                                    <NoData title="No storage location defined" description="Tambahkan lokasi TPS di Master Data." />
                                ) : (
                                    <ul className="grid grid-cols-1 gap-2 md:grid-cols-2">
                                        {storage.map((s) => <StorageCard key={s.id ?? 'none'} s={s} onOpen={() => show({ storage_location_id: s.id ? String(s.id) : 'none', view: 'onsite' })} />)}
                                    </ul>
                                )}
                            </Panel>
                            <Panel title="Recent Movements" description="Pengangkutan dan serah terima terakhir.">
                                {recentMovements.length === 0 ? (
                                    <NoData title="No movements yet" description="Gerakan dicatat dari halaman catatan limbah." />
                                ) : (
                                    <ul className="divide-y divide-steel-100 dark:divide-slate-800">
                                        {recentMovements.map((m) => (
                                            <li key={m.id}>
                                                <Link href={route('waste-records.show', m.waste_record_id)} className="block py-2 text-xs hover:bg-steel-50/60 dark:hover:bg-slate-800/40">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span className="font-mono font-semibold text-navy-900 dark:text-slate-100">{m.waste_record?.record_number}</span>
                                                        <Chip tone={m.status === 'disposed' ? 'success' : m.status === 'picked_up' ? 'info' : 'warning'}>{PICKUP[m.status] ?? m.status}</Chip>
                                                    </div>
                                                    <p className="mt-0.5 truncate text-graphite-500 dark:text-slate-400">
                                                        {m.waste_record?.waste_type?.name ?? '-'} · {m.vendor?.name ?? 'vendor belum dicatat'}
                                                        {m.manifest_number && ` · manifest ${m.manifest_number}`}
                                                    </p>
                                                    <p className="text-graphite-400">
                                                        {m.disposal_date ? `Diserahkan ${fmtDate(m.disposal_date)}` : m.pickup_date ? `Diambil ${fmtDate(m.pickup_date)}` : 'Dijadwalkan'}
                                                        {` · ${m.documents_count} dokumen`}
                                                    </p>
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Panel>
                        </div>

                        {/* 5. RECORDS */}
                        <section id="records" aria-labelledby="records-heading" className="scroll-mt-4">
                            <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                                <h2 id="records-heading" className="text-sm font-bold text-navy-900 dark:text-slate-100">Waste Records</h2>
                                <p className="text-xs text-graphite-500 dark:text-slate-400" aria-live="polite">
                                    {loading ? 'Memuat...' : `${records.total} catatan${hasFilters ? ' cocok dengan filter' : ''}`}
                                </p>
                            </div>
                            {mode && (
                                <p className="mb-2 rounded-lg border border-brand-200 bg-brand-50/60 px-3 py-2 text-xs text-navy-800 dark:border-brand-900 dark:bg-brand-950/30 dark:text-slate-200">
                                    {{ storage: 'Pilih catatan yang masih di lokasi untuk ditempatkan atau dipindah ke TPS.', pickup: 'Pilih catatan yang siap diangkut untuk menjadwalkan pickup.', handover: 'Pilih catatan yang menunggu pickup atau sedang diangkut untuk mencatat serah terima/pemusnahan.' }[mode]}
                                    <button type="button" className="ml-2 font-semibold underline" onClick={() => show({})}>Batal</button>
                                </p>
                            )}

                            <FilterBar>
                                <FilterBar.Search placeholder="Search record, type, waste code, TPS, vendor, manifest..." value={search} onChange={(e) => setSearch(e.target.value)} />
                                <PickSelect label="Category" value={filters.category} onChange={(v) => apply({ category: v, page: null })} options={[{ value: 'b3', label: 'B3' }, { value: 'non_b3', label: 'Non-B3' }]} />
                                <PickSelect label="Waste Type" value={filters.waste_type_id} onChange={(v) => apply({ waste_type_id: v, page: null })}
                                    options={options.wasteTypes.map((t) => ({ value: String(t.id), label: t.waste_code ? `${t.name} (${t.waste_code})` : t.name }))} />
                                <PickSelect label="Status" value={filters.status} onChange={(v) => apply({ status: v, page: null })}
                                    options={options.statuses.map((s) => ({ value: s, label: STATUS[s]?.label ?? s }))} />
                                <PickSelect label="Storage (TPS)" value={filters.storage_location_id} onChange={(v) => apply({ storage_location_id: v, page: null })}
                                    options={[...options.storageLocations.map((l) => ({ value: String(l.id), label: l.code ? `${l.code} · ${l.name}` : l.name })), { value: 'none', label: 'No storage location' }]} />
                                <PickSelect label="Pickup / Handover" value={filters.pickup} onChange={(v) => apply({ pickup: v, page: null })}
                                    options={[{ value: 'none', label: 'Not scheduled' }, ...Object.entries(PICKUP).map(([k, l]) => ({ value: k, label: l }))]} />
                                <label className="flex items-center gap-1 text-xs text-graphite-500">
                                    <span className="sr-only sm:not-sr-only">From</span>
                                    <Input type="date" aria-label="Generated from" value={filters.from || ''} onChange={(e) => apply({ from: e.target.value, page: null })} className="h-9 w-[9.5rem] border-steel-200 bg-white dark:bg-slate-900" />
                                </label>
                                <label className="flex items-center gap-1 text-xs text-graphite-500">
                                    <span className="sr-only sm:not-sr-only">To</span>
                                    <Input type="date" aria-label="Generated to" value={filters.to || ''} onChange={(e) => apply({ to: e.target.value, page: null })} className="h-9 w-[9.5rem] border-steel-200 bg-white dark:bg-slate-900" />
                                </label>
                                {hasFilters && <Button variant="ghost" size="sm" onClick={() => show({})}><X className="h-3.5 w-3.5" /> Clear</Button>}
                                {loading && <Loader2 className="h-4 w-4 animate-spin text-steel-500" aria-hidden="true" />}
                            </FilterBar>

                            {failed && (
                                <p role="alert" className="mb-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                                    Daftar tidak dapat dimuat. Periksa koneksi, lalu coba lagi.
                                    <button type="button" className="ml-2 font-semibold underline" onClick={() => apply({}, true)}>Coba lagi</button>
                                </p>
                            )}

                            <div className={cn('transition-opacity', loading && 'opacity-60')}>
                                {records.data.length === 0 ? (
                                    <EmptyState icon={PackageOpen} title="No records match" description="Tidak ada catatan yang cocok dengan pencarian atau filter ini."
                                        action={<Button variant="outline" size="sm" onClick={() => show({})}>Clear filters</Button>} />
                                ) : (
                                    <>
                                        <RecordsTable rows={records.data} mode={mode} can={can} />
                                        <RecordCards rows={records.data} mode={mode} can={can} />
                                    </>
                                )}
                            </div>

                            {records.last_page > 1 && (
                                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                                    <span>Page {records.current_page} of {records.last_page}</span>
                                    <div className="flex gap-2">
                                        <button type="button" aria-label="Previous page" disabled={!records.prev_page_url} onClick={() => apply({ page: records.current_page - 1 }, true)} className="rounded-md border border-steel-200 bg-white p-1.5 disabled:opacity-40 dark:border-slate-700 dark:bg-slate-900"><ChevronLeft className="h-4 w-4" /></button>
                                        <button type="button" aria-label="Next page" disabled={!records.next_page_url} onClick={() => apply({ page: records.current_page + 1 }, true)} className="rounded-md border border-steel-200 bg-white p-1.5 disabled:opacity-40 dark:border-slate-700 dark:bg-slate-900"><ChevronRight className="h-4 w-4" /></button>
                                    </div>
                                </div>
                            )}
                        </section>
                    </>
                )}
            </DashboardShell>
        </AuthenticatedLayout>
    );
}

function FilterCard({ onClick, active, ...props }) {
    return (
        <button type="button" onClick={onClick} aria-pressed={active}
            className={cn('rounded-xl text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500', active && 'ring-2 ring-brand-500/60')}>
            <StatCard {...props} />
        </button>
    );
}

function ActionButton({ active, icon: Icon, label, onClick }) {
    return (
        <button type="button" onClick={onClick} aria-pressed={active}
            className={cn('flex items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm font-semibold transition-colors',
                active ? 'border-brand-500 bg-brand-50 text-navy-900 dark:bg-brand-950/40 dark:text-slate-100' : 'border-steel-200 text-navy-900 hover:bg-steel-50 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-800')}>
            <Icon className="h-4 w-4 text-brand-600" aria-hidden="true" /> {label}
        </button>
    );
}

function PickSelect({ label, value, onChange, options }) {
    return (
        <Select value={value || '__all'} onValueChange={(v) => onChange(v === '__all' ? null : v)}>
            <SelectTrigger aria-label={label} className="h-9 w-full border-steel-200 bg-white sm:w-44 dark:bg-slate-900"><SelectValue placeholder={label} /></SelectTrigger>
            <SelectContent>
                <SelectItem value="__all">{`All: ${label}`}</SelectItem>
                {options.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
            </SelectContent>
        </Select>
    );
}

function PriorityGroup({ title, tone, count, items, detail, onAll, hrefFor }) {
    return (
        <div>
            <div className="mb-1.5 flex items-center justify-between gap-2">
                <h3 className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-navy-800 dark:text-slate-200">
                    {title} <Chip tone={count > 0 ? tone : 'neutral'}>{count}</Chip>
                </h3>
                {count > items.length && <button type="button" onClick={onAll} className="text-[11px] font-semibold text-brand-700 hover:underline">View all</button>}
            </div>
            {items.length === 0 ? (
                <p className="text-xs text-graphite-400">Tidak ada.</p>
            ) : (
                <ul className="divide-y divide-steel-100 dark:divide-slate-800">
                    {items.map((r) => (
                        <li key={r.id}>
                            <Link href={hrefFor ? hrefFor(r) : route('waste-records.show', r.id)} className="flex items-start gap-2 py-1.5 text-xs hover:bg-steel-50/60 dark:hover:bg-slate-800/40">
                                <CategoryChip category={r.category} />
                                <span className="min-w-0 flex-1">
                                    <span className="font-mono font-semibold text-navy-900 dark:text-slate-100">{r.record_number}</span>
                                    <span className="text-graphite-500"> · {r.waste_type ?? 'No type'}{r.storage_location ? ` · ${r.storage_location}` : ''}</span>
                                    <span className="block text-graphite-500 dark:text-slate-400">{detail(r)}</span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

const CAPACITY = {
    exceeded: { tone: 'danger', label: 'Over declared capacity' },
    filling: { tone: 'warning', label: 'Filling up' },
    ok: { tone: 'success', label: 'Within capacity' },
    mixed_units: { tone: 'neutral', label: 'Mixed units, not compared' },
    unit_mismatch: { tone: 'neutral', label: 'Unit differs from capacity' },
    no_capacity: { tone: 'neutral', label: 'No capacity declared' },
    unassigned: { tone: 'warning', label: 'Needs a TPS' },
};

function StorageCard({ s, onOpen }) {
    const cap = CAPACITY[s.capacity_state] ?? CAPACITY.no_capacity;
    const pct = s.utilization;
    return (
        <li>
            <button type="button" onClick={onOpen} className="block h-full w-full rounded-lg border border-steel-200 bg-white p-3 text-left hover:border-brand-300 hover:bg-steel-50/40 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800/50">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <p className="flex items-center gap-1 truncate text-sm font-semibold text-navy-900 dark:text-slate-100">
                            <MapPin className="h-3.5 w-3.5 shrink-0 text-steel-500" aria-hidden="true" />
                            {s.code && <span className="font-mono text-[11px] text-steel-600">{s.code}</span>} {s.name}
                        </p>
                        <p className="text-[11px] text-graphite-500">{s.records} catatan di lokasi{s.b3_records > 0 ? `, ${s.b3_records} B3` : ''}{s.container_type ? ` · ${s.container_type}` : ''}</p>
                    </div>
                    <Chip tone={cap.tone}>{cap.label}</Chip>
                </div>
                <p className="mt-2 text-xs text-graphite-700 dark:text-slate-300">
                    <span className="text-graphite-400">Isi saat ini: </span>
                    {s.quantities.length === 0 ? 'kosong' : s.quantities.map((q) => qty(q.quantity, q.unit)).join(' + ')}
                    {s.capacity !== null && <span className="text-graphite-400"> / kapasitas {qty(s.capacity, s.capacity_unit)}</span>}
                </p>
                {pct !== null && (
                    <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-steel-100 dark:bg-slate-800" role="img" aria-label={`${pct}% of declared capacity`}>
                        <span className={cn('block h-full', s.capacity_state === 'exceeded' ? 'bg-red-500' : s.capacity_state === 'filling' ? 'bg-amber-500' : 'bg-emerald-500')} style={{ width: `${Math.min(100, pct)}%` }} />
                    </div>
                )}
                {s.oldest && (
                    <p className="mt-2 text-[11px] text-graphite-500">
                        <span className="text-graphite-400">Paling lama: </span>
                        <span className="font-mono">{s.oldest.record_number}</span> · {s.oldest.days_in_storage ?? 0} hari
                        {s.oldest.storage_limit_days ? ` dari batas ${s.oldest.storage_limit_days} hari` : ' (jenis ini tanpa batas lama simpan)'}
                        {s.oldest.storage_state === 'overdue' && <Chip tone="danger" className="ml-1">Limit exceeded</Chip>}
                        {s.oldest.storage_state === 'approaching' && <Chip tone="warning" className="ml-1">Near limit</Chip>}
                    </p>
                )}
            </button>
        </li>
    );
}

function rowAction(r, mode, can) {
    if (!can.manage || !mode) return null;
    if (mode === 'storage' && ['generated', 'stored', 'scheduled_pickup'].includes(r.status)) {
        return { label: r.storage_location ? 'Move TPS' : 'Assign TPS', href: route('waste-records.show', { wasteRecord: r.id, action: 'storage' }) };
    }
    if (mode === 'pickup' && ['generated', 'stored'].includes(r.status)) {
        return { label: 'Schedule pickup', href: route('waste-records.show', { wasteRecord: r.id, action: 'movement' }) };
    }
    if (mode === 'handover' && ['scheduled_pickup', 'in_transit'].includes(r.status)) {
        return { label: 'Record handover', href: route('waste-records.show', { wasteRecord: r.id, action: 'movement' }) };
    }
    return null;
}

function StorageDuration({ r }) {
    if (r.days_in_storage === null || r.days_in_storage === undefined) return <span className="text-graphite-400">-</span>;
    return (
        <span>
            {r.days_in_storage} hari
            {r.storage_limit_days ? <span className="text-graphite-400"> / {r.storage_limit_days}</span> : null}
            {r.storage_state === 'overdue' && <Chip tone="danger" className="ml-1">Exceeded</Chip>}
            {r.storage_state === 'approaching' && <Chip tone="warning" className="ml-1">Near limit</Chip>}
        </span>
    );
}

function RecordsTable({ rows, mode, can }) {
    return (
        <Card className="hidden md:block">
            <CardContent className="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Record</TableHead>
                            <TableHead>Waste</TableHead>
                            <TableHead>Quantity</TableHead>
                            <TableHead>Storage (TPS)</TableHead>
                            <TableHead>In Storage</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Pickup / Handover</TableHead>
                            <TableHead className="text-right"><span className="sr-only">Actions</span></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((r) => {
                            const action = rowAction(r, mode, can);
                            return (
                                <TableRow key={r.id} className="cursor-pointer" onClick={() => router.visit(route('waste-records.show', r.id))}>
                                    <TableCell className="whitespace-nowrap">
                                        <Link href={route('waste-records.show', r.id)} onClick={(e) => e.stopPropagation()} className="font-mono text-xs font-semibold text-navy-900 hover:underline dark:text-slate-100">{r.record_number}</Link>
                                        <p className="text-[11px] text-graphite-400">{fmtDate(r.generated_date)}</p>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-1.5"><CategoryChip category={r.category} /><span className="text-sm">{r.waste_type ?? 'No type'}</span></div>
                                        <p className="text-[11px] text-graphite-400">{[r.waste_code, r.project, r.location].filter(Boolean).join(' · ') || '-'}</p>
                                        {r.gaps.length > 0 && <p className="mt-0.5 text-[11px] font-medium text-amber-700 dark:text-amber-400">{r.gaps.map((g) => GAPS[g] ?? g).join(' · ')}</p>}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-sm">{qty(r.quantity, r.unit)}{r.container && <p className="text-[11px] text-graphite-400">{r.container}</p>}</TableCell>
                                    <TableCell className="text-sm">{r.storage_location ?? <span className="text-graphite-400">Not assigned</span>}</TableCell>
                                    <TableCell className="whitespace-nowrap text-xs"><StorageDuration r={r} /></TableCell>
                                    <TableCell><StatusChip status={r.status} /></TableCell>
                                    <TableCell className="text-xs">
                                        {r.pickup_state ? (
                                            <>
                                                <span className="font-medium">{PICKUP[r.pickup_state]}</span>
                                                <p className="text-[11px] text-graphite-400">{[r.vendor, r.manifest_number && `manifest ${r.manifest_number}`, `${r.documents_count} dok.`].filter(Boolean).join(' · ')}</p>
                                            </>
                                        ) : <span className="text-graphite-400">Not scheduled</span>}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {action && (
                                            <Link href={action.href} onClick={(e) => e.stopPropagation()}>
                                                <Button size="sm" variant="outline">{action.label}</Button>
                                            </Link>
                                        )}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

function RecordCards({ rows, mode, can }) {
    return (
        <ul className="space-y-2 md:hidden">
            {rows.map((r) => {
                const action = rowAction(r, mode, can);
                return (
                    <li key={r.id} className="rounded-xl border border-steel-200 bg-white p-3 shadow-card dark:border-slate-800 dark:bg-slate-900">
                        <Link href={route('waste-records.show', r.id)} className="block">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="font-mono text-[11px] font-semibold text-steel-700 dark:text-steel-300">{r.record_number}</p>
                                    <p className="flex items-center gap-1.5 truncate text-sm font-semibold text-navy-900 dark:text-slate-100"><CategoryChip category={r.category} /> {r.waste_type ?? 'No type'}</p>
                                </div>
                                <StatusChip status={r.status} />
                            </div>
                            <div className="mt-2 grid grid-cols-2 gap-x-3 gap-y-0.5 text-xs">
                                <span className="text-graphite-400">Quantity</span><span>{qty(r.quantity, r.unit)}</span>
                                <span className="text-graphite-400">TPS</span><span>{r.storage_location ?? 'Not assigned'}</span>
                                <span className="text-graphite-400">In storage</span><span><StorageDuration r={r} /></span>
                                <span className="text-graphite-400">Pickup</span><span>{r.pickup_state ? PICKUP[r.pickup_state] : 'Not scheduled'}</span>
                            </div>
                            {r.gaps.length > 0 && <p className="mt-1.5 text-[11px] font-medium text-amber-700 dark:text-amber-400">{r.gaps.map((g) => GAPS[g] ?? g).join(' · ')}</p>}
                        </Link>
                        {action && <Link href={action.href} className="mt-2 block"><Button size="sm" variant="outline" className="w-full">{action.label}</Button></Link>}
                    </li>
                );
            })}
        </ul>
    );
}

