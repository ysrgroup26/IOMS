import {
    ShieldCheck, Users, FolderKanban, PackageSearch, ShoppingCart,
    Wrench, BadgeCheck, BarChart3, ArrowUpRight,
} from 'lucide-react';
import Reveal from '@/Components/public/Reveal';
import { cn } from '@/lib/utils';

/**
 * v2.61.0 -- THE DEPARTMENTS, AND A TERMINOLOGY CORRECTION.
 *
 * What stood here was six white cards with the same pale blue icon square
 * on each. Visually they were one card printed six times; the only thing
 * distinguishing an operational domain from the one beside it was the
 * word at the top.
 *
 * THE MORE IMPORTANT PROBLEM WAS THE WORDING, not the styling.
 *
 *   "Operations" was not a workspace. It was a marketing bucket holding
 *   Work Center, Tasks, Man-Hour and the activity timeline -- which are
 *   real, but are the CROSS-CUTTING layer every department uses, not a
 *   department of their own. Presenting them as a seventh domain implied
 *   an "Operations" area a customer would then look for and not find.
 *   They are now stated as what they are, in the strip below the grid.
 *
 *   "Procurement & Warehouse" merged two things the product separates.
 *   `warehouse` is a shell workspace -- a Dashboard and an Overview. The
 *   real warehouse capability (Item Master, Inventory, Goods Receipt,
 *   Stock Movement) lives under Logistics / PPIC, which is exactly why
 *   the Business plan sells Logistics / PPIC and not "Warehouse". Split
 *   into the two workspaces that actually exist.
 *
 *   Maintenance, Asset Management and Quality Control shipped and were
 *   missing from this section entirely.
 *
 * Every card below is now a real entry in resources/js/lib/workspaces.js,
 * with that workspace's own label and its own lucide icon, and every
 * bullet is a real menu item under it.
 *
 * THE VISUAL TREATMENT. Each domain gets one accent, used in three quiet
 * places -- a filled gradient icon chip (the same chip the application
 * uses for its stat cards), a hairline top rule, and a tinted wash that
 * only appears on hover. The card lifts on hover and the arrow slides;
 * that is the whole interaction. Restraint is the point: eight cards each
 * shouting a different colour is the failure mode on the other side of
 * "six identical white boxes".
 */

const DEPARTMENTS = [
    {
        title: 'Health, Safety & Environment',
        icon: ShieldCheck,
        accent: 'from-danger to-red-600',
        rule: 'bg-danger/70',
        wash: 'group-hover:bg-danger/[0.035]',
        items: ['Permit To Work, LOTO & gas test', 'Incidents & safety observations', 'Inspections, JSA & HIRADC', 'PPE, waste & CAPA'],
    },
    {
        title: 'Human Resources',
        icon: Users,
        accent: 'from-brand-500 to-brand-700',
        rule: 'bg-brand-500/70',
        wash: 'group-hover:bg-brand-500/[0.035]',
        items: ['Employee master data', 'Competency & certificate expiry', 'Shifts, rosters & leave', 'Contractors & visitors'],
    },
    {
        title: 'Project Management',
        icon: FolderKanban,
        accent: 'from-violet-500 to-violet-700',
        rule: 'bg-violet-500/70',
        wash: 'group-hover:bg-violet-500/[0.035]',
        items: ['Projects & milestones', 'Manpower assignment', 'Daily reports', 'Tasks & progress records'],
    },
    {
        title: 'Logistics / PPIC',
        icon: PackageSearch,
        accent: 'from-steel-500 to-steel-700',
        rule: 'bg-steel-500/70',
        wash: 'group-hover:bg-steel-500/[0.035]',
        items: ['Material Request', 'Item master & inventory', 'Goods receipt', 'Stock movement & transfer'],
    },
    {
        title: 'Procurement',
        icon: ShoppingCart,
        accent: 'from-navy-700 to-navy-900',
        rule: 'bg-navy-700/70',
        wash: 'group-hover:bg-navy-800/[0.035]',
        items: ['Purchase Requisition (FPB)', 'RFQ & vendor comparison', 'Purchase Order', 'Vendor performance & BAST'],
    },
    {
        title: 'Maintenance & Assets',
        icon: Wrench,
        accent: 'from-warning to-amber-600',
        rule: 'bg-warning/70',
        wash: 'group-hover:bg-warning/[0.045]',
        items: ['Asset register', 'Maintenance requests', 'Work orders', 'Equipment master data'],
    },
    {
        title: 'Quality Control',
        icon: BadgeCheck,
        accent: 'from-success to-emerald-700',
        rule: 'bg-success/70',
        wash: 'group-hover:bg-success/[0.035]',
        items: ['Inspection requests', 'Non-Conformance Reports', 'Follow-up to closure'],
    },
    {
        title: 'Management & Reporting',
        icon: BarChart3,
        accent: 'from-graphite-600 to-graphite-800',
        rule: 'bg-graphite-500/70',
        wash: 'group-hover:bg-graphite-500/[0.035]',
        items: ['KPI records', 'Report Center', 'Scheduled reports', 'PDF & Excel on your letterhead'],
    },
];

export default function DepartmentGrid() {
    return (
        <>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {DEPARTMENTS.map((d, i) => (
                    <Reveal
                        key={d.title}
                        // Capped stagger: eight cards at a full step each
                        // would take a second to finish, which stops being
                        // polish and starts being a loading screen.
                        delay={Math.min(i, 3) * 60}
                        className="group relative overflow-hidden rounded-xl border border-graphite-200 bg-white shadow-card transition-all duration-300 hover:-translate-y-0.5 hover:border-graphite-300 hover:shadow-lift"
                    >
                        {/* Accent rule: the one place the domain's colour is
                            unconditional, so the grid reads as eight
                            distinct domains at a glance without eight
                            coloured cards. */}
                        <span aria-hidden="true" className={cn('absolute inset-x-0 top-0 h-[3px]', d.rule)} />
                        {/* Wash: appears only on hover, under the content. */}
                        <span aria-hidden="true" className={cn('absolute inset-0 transition-colors duration-300', d.wash)} />

                        <div className="relative p-5">
                            <div className="flex items-start justify-between gap-3">
                                <span
                                    className={cn(
                                        'flex h-11 w-11 shrink-0 items-center justify-center rounded-[13px] bg-gradient-to-br text-white shadow-card',
                                        d.accent
                                    )}
                                >
                                    <d.icon className="h-[22px] w-[22px]" />
                                </span>
                                <ArrowUpRight className="h-4 w-4 shrink-0 text-graphite-300 transition-all duration-300 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-graphite-500" />
                            </div>

                            <h3 className="mt-4 text-[15px] font-semibold leading-snug tracking-tight text-navy-900">
                                {d.title}
                            </h3>

                            <ul className="mt-3 space-y-1.5 border-t border-graphite-100 pt-3">
                                {d.items.map((item) => (
                                    <li key={item} className="flex items-start gap-2 text-[13px] leading-snug text-graphite-600">
                                        <span aria-hidden="true" className={cn('mt-[7px] h-1 w-1 shrink-0 rounded-full', d.rule)} />
                                        <span>{item}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </Reveal>
                ))}
            </div>

            {/* The cross-cutting layer, stated as a layer. This is where
                the retired "Operations" card's contents actually belong --
                they are not a ninth department, they are what every
                department shares. */}
            <Reveal delay={120} className="mt-6 rounded-xl border border-graphite-200 bg-graphite-50 p-5 sm:flex sm:items-center sm:gap-6">
                <p className="shrink-0 text-[11px] font-semibold uppercase tracking-[0.16em] text-graphite-400">
                    Shared by every department
                </p>
                <div className="mt-3 flex flex-wrap gap-2 sm:mt-0">
                    {['Work Center', 'Approval workflow', 'Tasks & follow-up', 'Man-Hour', 'Activity timeline on every record', 'Operating Units', 'Audit log'].map((f) => (
                        <span
                            key={f}
                            className="rounded-full border border-graphite-200 bg-white px-3 py-1 text-xs font-medium text-graphite-600"
                        >
                            {f}
                        </span>
                    ))}
                </div>
            </Reveal>
        </>
    );
}
