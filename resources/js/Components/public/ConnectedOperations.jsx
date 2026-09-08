import { useState } from 'react';
import {
    ShieldCheck, Users, FolderKanban, PackageSearch, ShoppingCart,
    Wrench, BadgeCheck, LineChart, Layers3,
} from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * v2.61.0 -- CONNECTED OPERATIONS, MADE TO MOVE.
 *
 * The hero carried this concept already: eight domains on a circle around
 * a central IOMS hub. It was the right idea rendered as a still diagram --
 * eight boxes and eight hairlines, identical on first paint and on the
 * hundredth second. A visitor read it as a logo arrangement rather than as
 * a claim about how the software works.
 *
 * WHAT THE MOTION SAYS. Each spoke carries one short dashed segment that
 * travels from its department INTO the hub, staggered so the arrivals
 * never line up into a heartbeat. That is the product claim stated
 * literally: departments produce records, the records land in IOMS, and
 * management reads one set of data. When a segment lands, a ring leaves
 * the hub. Nothing pulses, glows, or loops fast enough to be noticed while
 * reading the copy above it.
 *
 * WHY NOT A LIBRARY. The whole effect is one CSS keyframe moving
 * `stroke-dashoffset` on an SVG line, plus a second keyframe on the hub
 * ring. Both live in tailwind.config.js beside the rest of the system's
 * motion. Nothing was added to the bundle.
 *
 * FOCUS AND HOVER. Every node is a real <button> in a tablist-shaped
 * group, so the diagram is reachable by keyboard and the visitor can ask
 * "what does that one do" and get an answer -- the panel underneath names
 * what that department actually records. Selecting is what makes the
 * visual informative rather than decorative; without it, the labels are
 * all a visitor gets.
 *
 * DEGRADATION. Motion is `motion-safe:` only -- under reduced motion the
 * diagram is the same static diagram it always was, and every node label
 * and detail panel is plain text either way.
 *
 * PRODUCT FIDELITY. Every node is a workspace in
 * resources/js/lib/workspaces.js, with the same label and the same lucide
 * icon that workspace uses in the application's own sidebar. The detail
 * lines name real menu items, not capabilities.
 */

// x/y are percentages on a 100x100 box: eight nodes, from the top,
// clockwise. Computed once, no trig at runtime.
const NODES = [
    {
        key: 'hse',
        label: 'Health, Safety & Environment',
        short: 'HSE',
        icon: ShieldCheck,
        x: 50,
        y: 8,
        detail: 'Permit To Work, incidents, observations, inspections, JSA, HIRADC, LOTO, gas test, PPE and CAPA.',
    },
    {
        key: 'hr',
        label: 'Human Resources',
        short: 'People',
        icon: Users,
        x: 85.7,
        y: 22.7,
        detail: 'Employee master data, competency and certificate expiry, shifts and rosters, leave, man-hour.',
    },
    {
        key: 'project-management',
        label: 'Project Management',
        short: 'Projects',
        icon: FolderKanban,
        x: 92,
        y: 50,
        detail: 'Projects, milestones, manpower assignment, daily reports and task follow-up.',
    },
    {
        key: 'logistics',
        label: 'Logistics / PPIC',
        short: 'Materials',
        icon: PackageSearch,
        x: 85.7,
        y: 77.3,
        detail: 'Material Request, item master, inventory, goods receipt, stock movement and transfers.',
    },
    {
        key: 'procurement',
        label: 'Procurement',
        short: 'Buying',
        icon: ShoppingCart,
        x: 50,
        y: 92,
        detail: 'Purchase requisition, RFQ and vendor comparison, purchase order, vendor performance, BAST.',
    },
    {
        key: 'maintenance',
        label: 'Maintenance',
        short: 'Assets',
        icon: Wrench,
        x: 14.3,
        y: 77.3,
        detail: 'Asset register, maintenance requests and work orders against the equipment that runs the site.',
    },
    {
        key: 'quality-control',
        label: 'Quality Control',
        short: 'Quality',
        icon: BadgeCheck,
        x: 8,
        y: 50,
        detail: 'Inspection requests and Non-Conformance Reports raised against the work being delivered.',
    },
    {
        key: 'reports',
        label: 'Reports & Analytics',
        short: 'Reporting',
        icon: LineChart,
        x: 14.3,
        y: 22.7,
        detail: 'Report Center, scheduled reports, KPI records and exports on your own letterhead.',
    },
];

export default function ConnectedOperations() {
    // Nothing is selected until the visitor asks: an auto-selected node
    // would make the diagram look like it is playing a demo at them.
    const [focused, setFocused] = useState(null);
    const active = NODES.find((n) => n.key === focused) ?? null;

    return (
        <div className="mx-auto w-full max-w-2xl">
            <div className="relative mx-auto hidden aspect-square w-full lg:block">
                <svg className="absolute inset-0 h-full w-full" viewBox="0 0 100 100" aria-hidden="true">
                    {/* Two faint orbit rings: the shared surface the
                        departments sit on, and the reason the eight nodes
                        read as one system rather than eight labels. */}
                    <circle cx="50" cy="50" r="42" fill="none" stroke="rgb(255 255 255 / 0.07)" strokeWidth="0.3" />
                    <circle cx="50" cy="50" r="27" fill="none" stroke="rgb(255 255 255 / 0.05)" strokeWidth="0.3" strokeDasharray="1 2" />

                    {NODES.map((n, i) => {
                        const isActive = focused === n.key;
                        const isDimmed = focused !== null && !isActive;

                        return (
                            <g key={n.key}>
                                {/* The static spoke. Always drawn, so the
                                    diagram is complete with no animation
                                    and no JavaScript state. */}
                                <line
                                    x1="50"
                                    y1="50"
                                    x2={n.x}
                                    y2={n.y}
                                    stroke={isActive ? 'rgb(92 160 234 / 0.85)' : 'rgb(255 255 255 / 0.16)'}
                                    strokeWidth={isActive ? 0.6 : 0.35}
                                    className="transition-all duration-300"
                                    opacity={isDimmed ? 0.4 : 1}
                                />
                                {/* The travelling record. Drawn from the
                                    node TOWARDS the hub so the direction of
                                    travel is inward -- data arriving, not
                                    instructions being pushed out. */}
                                <line
                                    x1={n.x}
                                    y1={n.y}
                                    x2="50"
                                    y2="50"
                                    stroke={isActive ? 'rgb(143 190 236)' : 'rgb(92 160 234 / 0.9)'}
                                    strokeWidth="0.9"
                                    strokeLinecap="round"
                                    pathLength="60"
                                    strokeDasharray="5 55"
                                    className="motion-safe:animate-dataflow"
                                    style={{ animationDelay: `${i * 0.42}s` }}
                                    opacity={isDimmed ? 0.25 : 1}
                                />
                            </g>
                        );
                    })}
                </svg>

                {/* The hub. One expanding ring marks arrivals; the disc
                    itself never moves, so the centre stays readable. */}
                <div className="absolute left-1/2 top-1/2 h-36 w-36 -translate-x-1/2 -translate-y-1/2">
                    <span
                        aria-hidden="true"
                        className="absolute inset-0 rounded-full border border-brand-400/40 motion-safe:animate-hub-ring"
                    />
                    <div className="relative flex h-full w-full flex-col items-center justify-center rounded-full border border-white/15 bg-navy-800/85 shadow-panel backdrop-blur-sm">
                        <div className="flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-brand-700 text-white ring-1 ring-inset ring-white/20">
                            <Layers3 className="h-6 w-6" />
                        </div>
                        <p className="mt-2 text-sm font-bold tracking-tight text-white">IOMS</p>
                        <p className="text-[10px] font-medium uppercase tracking-[0.14em] text-steel-300">One platform</p>
                    </div>
                </div>

                {/* Nodes. A group of buttons rather than decorative divs --
                    see the component note on why selecting matters. */}
                <div role="group" aria-label="Connected IOMS departments">
                    {NODES.map((n) => {
                        const isActive = focused === n.key;

                        return (
                            <button
                                key={n.key}
                                type="button"
                                onClick={() => setFocused(isActive ? null : n.key)}
                                onMouseEnter={() => setFocused(n.key)}
                                onMouseLeave={() => setFocused(null)}
                                onFocus={() => setFocused(n.key)}
                                onBlur={() => setFocused(null)}
                                aria-pressed={isActive}
                                className={cn(
                                    'absolute flex -translate-x-1/2 -translate-y-1/2 flex-col items-center gap-1.5 rounded-xl border px-3 py-2.5 backdrop-blur-sm',
                                    'transition-all duration-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:ring-offset-navy-900',
                                    isActive
                                        ? 'border-brand-400/60 bg-brand-500/20 shadow-lift'
                                        : 'border-white/10 bg-white/[0.07] hover:border-white/25 hover:bg-white/[0.12]'
                                )}
                                style={{ left: `${n.x}%`, top: `${n.y}%` }}
                            >
                                <n.icon className={cn('h-4 w-4 transition-colors', isActive ? 'text-white' : 'text-steel-300')} />
                                <span className="whitespace-nowrap text-[11px] font-medium text-steel-100">{n.label}</span>
                            </button>
                        );
                    })}
                </div>
            </div>

            {/* The answer panel. Fixed height so selecting a node never
                shifts the section below it -- a diagram that reflows the
                page on hover is the reason hover effects get disabled. */}
            <div className="mx-auto mt-6 hidden min-h-[68px] max-w-xl rounded-xl border border-white/10 bg-white/[0.05] px-5 py-3.5 text-center lg:block">
                {active ? (
                    <>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300">{active.label}</p>
                        <p className="mt-1 text-sm leading-relaxed text-steel-100">{active.detail}</p>
                    </>
                ) : (
                    <p className="pt-2 text-sm text-navy-300">
                        Every department records its own work. IOMS is where all of it lands.
                    </p>
                )}
            </div>

            {/* Mobile and tablet. Absolute nodes on a percentage circle
                cannot reflow to a narrow viewport, so small screens get the
                same eight workspaces as an honest grid rather than a
                shrunken, unreadable copy of the diagram. */}
            <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4 lg:hidden">
                {NODES.map((n) => (
                    <div
                        key={n.key}
                        className="flex flex-col items-center gap-1.5 rounded-xl border border-white/10 bg-white/[0.06] px-2.5 py-3 text-center"
                    >
                        <n.icon className="h-4 w-4 text-steel-300" />
                        <span className="text-[11px] font-medium leading-tight text-steel-100">{n.label}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}
