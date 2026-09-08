import { useState } from 'react';
import {
    LayoutDashboard, HardHat, Warehouse, ShoppingCart, Wrench,
    Users, FolderKanban, Building2, AlertTriangle, ClipboardCheck,
    Boxes, Box, FileCheck2, ArrowRight,
} from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * v2.59.0 -- THE PRODUCT SHOWCASE, AND WHY IT REPLACED WHAT WAS HERE.
 *
 * The landing page's "What working in IOMS looks like" section rendered
 * two panels. One was an HSE dashboard whose four figures were literally
 * the character "—" above a dashed empty rectangle; the other was a permit
 * with three grey bars where its content should be. A visitor evaluating
 * industrial software saw placeholder furniture and no product.
 *
 * Worse for positioning: those two panels were the entire product
 * visualisation, and BOTH were HSE/PTW. A page selling an Industrial
 * Operations Platform showed one department and called it the product.
 *
 * WHAT THIS DOES INSTEAD. Five real workspaces the platform actually
 * ships — Dashboard, Health Safety & Environment, Warehouse, Procurement,
 * Maintenance — inside one application frame, switched by tabs. PTW is now
 * one row inside one of five tabs, which is its true proportion. The
 * frame itself (navy rail, navy page header with an eyebrow, compact stat
 * chips, uppercase micro-labels) is the SAME visual language the
 * authenticated product uses, so a visitor who signs up recognises what
 * they were shown.
 *
 * EVERY LABEL IS REAL IOMS VOCABULARY. Operating Unit, Permit To Work,
 * Material Request, Purchase Order, Work Order, NCR, CAPA, Goods Receipt.
 * Nothing here is a capability IOMS does not have, and the numbers are
 * plainly illustrative sample data — the section says so in its own
 * subtitle rather than implying these are somebody's real figures.
 *
 * Interaction is a tab switch: instant, keyboard reachable, no autoplay,
 * nothing that moves while a visitor is reading.
 */

const MODULES = [
    {
        key: 'dashboard',
        label: 'Dashboard',
        icon: LayoutDashboard,
        eyebrow: 'Management',
        title: 'Dashboard',
        subtitle: 'Operational KPI summary across every department.',
        stats: [
            { icon: Users, value: '248', label: 'Active Workforce' },
            { icon: FolderKanban, value: '12', label: 'Running Projects', accent: 'green' },
            { icon: Building2, value: '2', label: 'Operating Units' },
        ],
        attention: [
            { icon: AlertTriangle, value: '3', label: 'Open Incidents', accent: 'red' },
            { icon: ClipboardCheck, value: '7', label: 'Open CAPA', accent: 'amber' },
            { icon: ShoppingCart, value: '5', label: 'Pending Procurement', accent: 'purple' },
            { icon: Boxes, value: '9', label: 'Stock Alerts', accent: 'amber' },
        ],
        rows: [
            ['Man-hours this month', '38,420'],
            ['Days since last LTI', '164'],
            ['Documents generated', '1,208'],
        ],
    },
    {
        key: 'hse',
        label: 'Health, Safety & Environment',
        icon: HardHat,
        eyebrow: 'Department',
        title: 'Health, Safety & Environment',
        subtitle: 'Permits, incidents, inspections and corrective actions.',
        stats: [
            { icon: FileCheck2, value: '6', label: 'Active Permits', accent: 'green' },
            { icon: AlertTriangle, value: '3', label: 'Open Incidents', accent: 'red' },
            { icon: ClipboardCheck, value: '7', label: 'Open CAPA', accent: 'amber' },
        ],
        table: {
            head: ['Number', 'Work', 'Status'],
            rows: [
                ['PTW-2026-00184', 'Hot work — shell plate, Dock 2', 'Active'],
                ['PTW-2026-00183', 'Confined space — ballast tank 3P', 'Approved'],
                ['INC-2026-00021', 'Near miss — dropped object', 'Investigation'],
                ['CAPA-2026-00044', 'Scaffold inspection frequency', 'In progress'],
            ],
        },
    },
    {
        key: 'warehouse',
        label: 'Warehouse',
        icon: Warehouse,
        eyebrow: 'Department',
        title: 'Warehouse',
        subtitle: 'Items, stock levels, goods receipt and stock movement.',
        stats: [
            { icon: Boxes, value: '1,340', label: 'Item Master' },
            { icon: Boxes, value: '9', label: 'Below Minimum', accent: 'amber' },
            { icon: Warehouse, value: '2', label: 'Stores' },
        ],
        table: {
            head: ['Item', 'On hand', 'Status'],
            rows: [
                ['Welding Electrode E7018 3.2mm', '18 box', 'Below minimum'],
                ['Steel Plate A36 10mm', '64 sheet', 'In stock'],
                ['Safety Harness Full Body', '11 pcs', 'Below minimum'],
                ['GRN-2026-00097 — received', '40 box', 'Posted'],
            ],
        },
    },
    {
        key: 'procurement',
        label: 'Procurement',
        icon: ShoppingCart,
        eyebrow: 'Department',
        title: 'Procurement',
        subtitle: 'Requisitions, vendor quotations and purchase orders.',
        stats: [
            { icon: ShoppingCart, value: '5', label: 'Pending Approval', accent: 'purple' },
            { icon: FileCheck2, value: '11', label: 'Open Orders' },
            { icon: Building2, value: '34', label: 'Qualified Vendors', accent: 'green' },
        ],
        table: {
            head: ['Document', 'Vendor', 'Status'],
            rows: [
                ['PR-2026-00051', 'Awaiting approval', 'Submitted'],
                ['PO-2026-00042', 'PT Baja Sentosa Nusantara', 'Issued'],
                ['PO-2026-00041', 'CV Anugerah Teknik Marine', 'Delivered'],
                ['MR-2026-00126', 'Raised by Production', 'Approved'],
            ],
        },
    },
    {
        key: 'maintenance',
        label: 'Maintenance',
        icon: Wrench,
        eyebrow: 'Department',
        title: 'Maintenance',
        subtitle: 'Asset register, maintenance requests and work orders.',
        stats: [
            { icon: Box, value: '86', label: 'Registered Assets' },
            { icon: Wrench, value: '4', label: 'Work Orders Open', accent: 'amber' },
            { icon: ClipboardCheck, value: '2', label: 'Due This Week', accent: 'amber' },
        ],
        table: {
            head: ['Work order', 'Asset', 'Status'],
            rows: [
                ['WO-2026-00031', 'Hydraulic Press 100T', 'In progress'],
                ['WO-2026-00030', 'Overhead Crane 20T', 'Open'],
                ['MRQ-2026-00019', 'Reported — pressure loss', 'Approved'],
                ['WO-2026-00028', 'Air Compressor 15 bar', 'Completed'],
            ],
        },
    },
];

const ACCENTS = {
    red: 'bg-gradient-to-br from-danger to-red-600',
    amber: 'bg-gradient-to-br from-warning to-amber-600',
    green: 'bg-gradient-to-br from-success to-emerald-700',
    purple: 'bg-gradient-to-br from-violet-500 to-violet-700',
    default: 'bg-gradient-to-br from-navy-800 to-brand-600',
};

export default function PlatformShowcase() {
    const [active, setActive] = useState(MODULES[0].key);
    const module = MODULES.find((m) => m.key === active) ?? MODULES[0];

    return (
        <div>
            {/* Workspace switcher. Mirrors the department selector pattern
                the application itself uses, so the control is recognisable
                before a visitor has ever signed in. */}
            <div
                className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-2 sm:mx-0 sm:flex-wrap sm:px-0"
                role="tablist"
                aria-label="IOMS workspaces"
            >
                {MODULES.map((m) => {
                    const isActive = m.key === active;

                    return (
                        <button
                            key={m.key}
                            type="button"
                            role="tab"
                            aria-selected={isActive}
                            onClick={() => setActive(m.key)}
                            className={cn(
                                'flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-xs font-medium transition-colors',
                                isActive
                                    ? 'border-navy-900 bg-navy-900 text-white'
                                    : 'border-graphite-200 bg-white text-graphite-600 hover:border-graphite-300 hover:text-graphite-900'
                            )}
                        >
                            <m.icon className="h-3.5 w-3.5" />
                            {m.label}
                        </button>
                    );
                })}
            </div>

            {/* The application frame. */}
            <div className="mt-4 overflow-hidden rounded-xl border border-graphite-200 bg-white shadow-card">
                <div className="flex">
                    {/* Navigation rail -- the product's own navy, collapsed to
                        icons because the tabs above already name the modules. */}
                    <div className="hidden w-14 shrink-0 flex-col items-center gap-1 bg-navy-900 py-4 sm:flex">
                        {MODULES.map((m) => (
                            <span
                                key={m.key}
                                className={cn(
                                    'flex h-9 w-9 items-center justify-center rounded-lg transition-colors',
                                    m.key === active ? 'bg-white/[0.14] text-white' : 'text-navy-300'
                                )}
                            >
                                <m.icon className="h-4 w-4" />
                            </span>
                        ))}
                    </div>

                    <div className="min-w-0 flex-1">
                        {/* Page header band -- the same navy header the real
                            Dashboard carries, eyebrow and all. */}
                        <div className="bg-gradient-to-r from-navy-900 to-navy-800 px-4 py-3.5 sm:px-5">
                            <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-steel-300">
                                IOMS · {module.eyebrow}
                            </p>
                            <p className="mt-0.5 text-sm font-semibold tracking-tight text-white">{module.title}</p>
                            <p className="mt-0.5 text-[11px] text-navy-300">{module.subtitle}</p>
                        </div>

                        {/* `key={active}` remounts on switch so the existing
                            `fade-in` token replays -- reusing the design
                            system's own keyframe rather than inventing a
                            second one. Wrapped in motion-safe, like every
                            other animation in this codebase. */}
                        <div key={active} className="space-y-3 p-4 motion-safe:animate-fade-in sm:p-5">
                            <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-3">
                                {module.stats.map((s) => <Chip key={s.label} {...s} />)}
                            </div>

                            {module.attention && (
                                <>
                                    <p className="pt-1 text-[10px] font-semibold uppercase tracking-wide text-graphite-400">
                                        Needs Attention
                                    </p>
                                    <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                                        {module.attention.map((s) => <Chip key={s.label} {...s} />)}
                                    </div>
                                </>
                            )}

                            {module.rows && (
                                <dl className="divide-y divide-graphite-100 rounded-lg border border-graphite-100">
                                    {module.rows.map(([label, value]) => (
                                        <div key={label} className="flex items-center justify-between px-3 py-2">
                                            <dt className="text-xs text-graphite-500">{label}</dt>
                                            <dd className="text-xs font-semibold text-navy-900">{value}</dd>
                                        </div>
                                    ))}
                                </dl>
                            )}

                            {module.table && (
                                <div className="overflow-hidden rounded-lg border border-graphite-100">
                                    <table className="w-full">
                                        <thead>
                                            <tr className="bg-graphite-50">
                                                {module.table.head.map((h) => (
                                                    <th key={h} className="px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wide text-graphite-400">
                                                        {h}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-graphite-100">
                                            {module.table.rows.map((row) => (
                                                <tr key={row[0]}>
                                                    <td className="whitespace-nowrap px-3 py-2 text-[11px] font-medium text-navy-900">{row[0]}</td>
                                                    <td className="px-3 py-2 text-[11px] text-graphite-600">{row[1]}</td>
                                                    <td className="whitespace-nowrap px-3 py-2 text-[11px] text-graphite-500">{row[2]}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <p className="mt-3 flex items-center gap-1.5 text-xs text-graphite-500">
                <ArrowRight className="h-3.5 w-3.5 shrink-0 text-graphite-400" />
                Every module above ships in IOMS. Which ones a customer sees depends on their plan.
            </p>
        </div>
    );
}

function Chip({ icon: Icon, value, label, accent }) {
    return (
        <div className="flex items-center gap-2.5 rounded-lg border border-graphite-100 bg-white p-2.5">
            <span className={cn('flex h-7 w-7 shrink-0 items-center justify-center rounded-[9px] text-white', ACCENTS[accent] ?? ACCENTS.default)}>
                <Icon className="h-[15px] w-[15px]" />
            </span>
            <span className="min-w-0">
                <span className="block text-base font-semibold leading-tight text-navy-900">{value}</span>
                <span className="block truncate text-[10px] font-medium uppercase tracking-wide text-graphite-400">{label}</span>
            </span>
        </div>
    );
}
