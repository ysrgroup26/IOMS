import {
    FileSpreadsheet, MessageSquare, FileText, ClipboardList, PackageSearch,
    Printer, ArrowRight, Check, Layers3,
} from 'lucide-react';
import Reveal from '@/Components/public/Reveal';

/**
 * v2.61.0 -- FRAGMENTED OPERATIONS, AND WHAT REPLACES THEM.
 *
 * The section this replaces made the right argument in the wrong shape:
 * six identical grey boxes, a rotated arrow, and a bordered box reading
 * "One connected operation". It stated the conclusion instead of showing
 * the change, and the six boxes were interchangeable rectangles when the
 * whole point is that today's tools are six DIFFERENT places.
 *
 * THE SHAPE NOW. Two panels side by side, and the visitor reads left to
 * right the way the sentence goes.
 *
 *   LEFT   "Today" -- the six tools, each with its own icon, on a hatched
 *          surface, each carrying the department that owns it. They are
 *          deliberately not aligned to a tidy grid rhythm: the tools are
 *          separate, and the panel should feel that way.
 *   MIDDLE the crossing point -- on desktop a short converging bracket,
 *          on mobile a single downward arrow.
 *   RIGHT  "In IOMS" -- ONE record moving through the departments that
 *          touch it, with the real statuses IOMS stores. Not a slogan
 *          panel: the actual thing the left panel cannot do.
 *
 * WHY A REAL RECORD ON THE RIGHT. "One connected operation" is a claim.
 * A Material Request that is raised in Logistics, approved by the project,
 * ordered by Procurement and received against a goods receipt is the
 * claim demonstrated -- and every one of those steps is a route that
 * exists (material-requests, purchase-requisitions, purchase-orders,
 * goods-receipts). The numbers are illustrative; the chain is not.
 *
 * MOTION. Only the existing `Reveal` primitive, staggered down the two
 * panels, so the left panel is read before the right one resolves. No new
 * keyframe, no scroll-linked scrubbing, and under reduced motion both
 * panels are simply present.
 */

const TOOLS = [
    { label: 'Excel per department', owner: 'Everyone', icon: FileSpreadsheet },
    { label: 'WhatsApp approvals', owner: 'Approvers', icon: MessageSquare },
    { label: 'Paper permits', owner: 'HSE', icon: FileText },
    { label: 'Separate HSE records', owner: 'HSE', icon: ClipboardList },
    { label: 'Manual stock notes', owner: 'Warehouse', icon: PackageSearch },
    { label: 'Reports rebuilt by hand', owner: 'Management', icon: Printer },
];

// One record, the departments it passes through, and the status IOMS
// actually stores at each step (see StatusBadge's own status vocabulary).
const CHAIN = [
    { department: 'Logistics / PPIC', event: 'MR-2026-00126 raised', status: 'Submitted', tone: 'warning' },
    { department: 'Project Management', event: 'Charged to Project SY-114', status: 'Approved', tone: 'success' },
    { department: 'Procurement', event: 'PO-2026-00042 issued', status: 'Issued', tone: 'brand' },
    { department: 'Logistics / PPIC', event: 'GRN-2026-00097 received', status: 'Posted', tone: 'success' },
    { department: 'Management', event: 'Visible in Report Center', status: 'Reported', tone: 'neutral' },
];

const TONES = {
    warning: 'bg-warning/10 text-amber-700',
    success: 'bg-success/10 text-success',
    brand: 'bg-brand-50 text-brand-700',
    neutral: 'bg-graphite-100 text-graphite-600',
};

export default function FragmentedToConnected() {
    return (
        <div className="grid grid-cols-1 items-stretch gap-6 lg:grid-cols-[1fr_auto_1fr] lg:gap-4">
            {/* ---------------------------------------------- TODAY */}
            <Reveal className="flex flex-col rounded-2xl border border-graphite-200 bg-white p-5 shadow-card sm:p-6">
                <div className="flex items-center gap-2">
                    <span className="text-[11px] font-semibold uppercase tracking-[0.16em] text-graphite-400">Today</span>
                    <span className="h-px flex-1 bg-graphite-200" />
                </div>
                <p className="mt-3 text-sm font-semibold text-graphite-900">Six places, none of which agree</p>

                {/* The hatched ground is what makes this panel read as the
                    "before": a technical surface with nothing built on it. */}
                <div
                    className="mt-4 flex-1 rounded-xl border border-dashed border-graphite-300 p-3"
                    style={{
                        backgroundImage:
                            'repeating-linear-gradient(45deg, rgba(148,163,184,0.10) 0 6px, transparent 6px 12px)',
                    }}
                >
                    <ul className="space-y-2">
                        {TOOLS.map((t) => (
                            <li
                                key={t.label}
                                className="flex items-center gap-2.5 rounded-lg border border-graphite-200 bg-white px-3 py-2 shadow-card"
                            >
                                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-graphite-100 text-graphite-400">
                                    <t.icon className="h-[15px] w-[15px]" />
                                </span>
                                <span className="min-w-0 flex-1 text-[13px] leading-snug text-graphite-600">{t.label}</span>
                                <span className="shrink-0 text-[10px] font-medium uppercase tracking-wide text-graphite-400">
                                    {t.owner}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>

                <p className="mt-4 text-xs leading-relaxed text-graphite-500">
                    Nothing here is wrong on its own. It just cannot be added up without somebody retyping it.
                </p>
            </Reveal>

            {/* ------------------------------------------- CROSSING */}
            {/* Desktop: a converging bracket that physically narrows six
                lines into one. Mobile: a single arrow, because a bracket
                rotated 90 degrees reads as a decoration. */}
            <div className="flex items-center justify-center lg:w-16">
                <svg
                    className="hidden h-40 w-16 lg:block"
                    viewBox="0 0 64 160"
                    fill="none"
                    aria-hidden="true"
                    preserveAspectRatio="none"
                >
                    {[16, 40, 64, 96, 120, 144].map((y) => (
                        <path
                            key={y}
                            d={`M0 ${y} C 26 ${y}, 30 80, 56 80`}
                            stroke="rgb(203 213 225)"
                            strokeWidth="1.5"
                            fill="none"
                        />
                    ))}
                    <circle cx="57" cy="80" r="4" fill="rgb(33 102 196)" />
                </svg>
                <span className="flex h-9 w-9 items-center justify-center rounded-full border border-graphite-200 bg-white text-brand-600 shadow-card lg:hidden">
                    <ArrowRight className="h-4 w-4 rotate-90" />
                </span>
            </div>

            {/* ------------------------------------------- IN IOMS */}
            <Reveal
                delay={120}
                className="flex flex-col rounded-2xl border-2 border-navy-900 bg-gradient-to-b from-brand-50/50 to-white p-5 shadow-card-hover sm:p-6"
            >
                <div className="flex items-center gap-2">
                    <span className="flex h-6 w-6 items-center justify-center rounded-md bg-gradient-to-br from-navy-800 to-brand-600 text-white">
                        <Layers3 className="h-3.5 w-3.5" />
                    </span>
                    <span className="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-700">In IOMS</span>
                    <span className="h-px flex-1 bg-brand-200" />
                </div>
                <p className="mt-3 text-sm font-semibold text-navy-900">One record, every department that touches it</p>

                <ol className="mt-4 flex-1 space-y-0">
                    {CHAIN.map((step, i) => (
                        <li key={step.event} className="relative flex gap-3 pb-3 last:pb-0">
                            {/* The spine. Drawn behind the dots, stopped
                                before the last one so the chain ends rather
                                than trailing off. */}
                            {i < CHAIN.length - 1 && (
                                <span aria-hidden="true" className="absolute left-[7px] top-4 h-full w-px bg-brand-200" />
                            )}
                            <span className="relative mt-1 flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded-full border-2 border-brand-500 bg-white">
                                <Check className="h-2 w-2 text-brand-600" strokeWidth={4} />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span className="text-[13px] font-semibold text-navy-900">{step.event}</span>
                                    <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${TONES[step.tone]}`}>
                                        {step.status}
                                    </span>
                                </span>
                                <span className="mt-0.5 block text-[11px] font-medium uppercase tracking-wide text-graphite-400">
                                    {step.department}
                                </span>
                            </span>
                        </li>
                    ))}
                </ol>

                <p className="mt-4 border-t border-brand-100 pt-3 text-xs leading-relaxed text-graphite-600">
                    Nobody rekeyed anything. The report at the end is the same data the yard entered at the start.
                </p>
            </Reveal>
        </div>
    );
}
