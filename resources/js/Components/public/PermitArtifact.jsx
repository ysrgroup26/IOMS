import { ShieldCheck, Flame, Clock3 } from 'lucide-react';

/**
 * v2.87.0 -- THE HERO HAS AN OBJECT NOW, AND THE OBJECT IS A PERMIT.
 *
 * WHY A DOCUMENT AND NOT A DASHBOARD. The brief rules out a dashboard-only
 * hero, and it is right to: every B2B SaaS hero is a screenshot of an
 * analytics screen, and none of them mean anything to a yard superintendent.
 * A Permit To Work does. It is the artifact this industry already organises
 * its day around, it is the thing IOMS most distinctively produces, and it
 * is recognisable to the buyer before they have read a word of copy.
 *
 * WHY THIS IS NOT A FAKE SCREENSHOT. It is not a picture of an interface. It
 * is the DOCUMENT FORMAT IOMS actually generates, rendered in the same
 * design language as `Pages/PermitsToWork/Document.jsx`: the same mono
 * reference number, the same field grid, the same approval seal with a named
 * approver and a real role. Nothing here claims a screen exists that does
 * not. The reference numbers and names are illustrative, in the same way the
 * product showcase's already are, and they are shaped like real records
 * rather than like `PTW-2026-XXXXX`.
 *
 * WHY IT IS CROPPED AND TILTED. A document squared to the viewport reads as
 * an image of a document. One that runs off the edge at a slight angle reads
 * as a physical sheet on a desk, which is the atmosphere the page was
 * missing. The tilt is 2 degrees, not 8: this is a control room, not a
 * scrapbook.
 *
 * MOTION. One settle on arrival, and the approval seal lands a beat after
 * the sheet, because that is the order it happens in real life: the permit
 * is raised, then it is approved. It plays once and stops. Everything is
 * `motion-safe:` and every element is fully visible without it.
 */
export default function PermitArtifact() {
    return (
        <div className="relative select-none" aria-hidden="true">
            {/* The light. A single soft source behind the sheet, so the paper
                has something to lift off rather than sitting flat on navy.
                One radial wash, not a mesh gradient. */}
            <div
                className="pointer-events-none absolute -inset-x-16 -inset-y-10 -z-10 opacity-70"
                style={{
                    background: 'radial-gradient(60% 55% at 42% 34%, rgba(92,160,234,0.22), transparent 70%)',
                }}
            />

            <div className="motion-safe:animate-sheet-settle relative rotate-[2deg] rounded-[14px] border border-white/15 bg-[#FBFCFD] shadow-[0_28px_70px_-18px_rgba(3,12,26,0.75)]">
                {/* Letterhead. The customer's own identity sits here in the
                    real document, which is the point of the line below it. */}
                <div className="flex items-start justify-between gap-4 border-b border-graphite-200 px-6 py-4">
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.18em] text-graphite-400">
                            Permit To Work
                        </p>
                        <p className="mt-1 font-display text-[15px] font-semibold tracking-tight text-navy-900">
                            Hot work, shell plate
                        </p>
                    </div>
                    <span className="inline-flex items-center gap-1.5 rounded-md bg-success-light px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-success">
                        <ShieldCheck className="h-3 w-3" /> Active
                    </span>
                </div>

                {/* The field grid, in the real document's own shape: a label
                    above a value, mono for anything that is a reference. */}
                <div className="grid grid-cols-2 gap-x-6 gap-y-4 px-6 py-5">
                    <Field label="Reference" value="PTW-2026-00184" mono />
                    <Field label="Operating unit" value="Batam Yard" />
                    <Field label="Location" value="Dock 2, frame 41" />
                    <Field label="Valid until" value="18:00, 1 Oct" />
                </div>

                <div className="border-t border-graphite-200 px-6 py-4">
                    <p className="font-mono text-[10px] uppercase tracking-[0.16em] text-graphite-400">
                        Controls verified
                    </p>
                    <ul className="mt-2.5 space-y-1.5">
                        {[
                            [Flame, 'Gas test clear, 0% LEL'],
                            [ShieldCheck, 'Fire watch assigned'],
                            [Clock3, 'Isolation certificate attached'],
                        ].map(([Icon, label]) => (
                            <li key={label} className="flex items-center gap-2 text-[12.5px] text-graphite-700">
                                <Icon className="h-3.5 w-3.5 shrink-0 text-brand-600" />
                                {label}
                            </li>
                        ))}
                    </ul>
                </div>

                {/* The seal. In the product this renders only when a real
                    server-side authorization record exists (see
                    ApprovalStamp's own note); here it is the visual payoff of
                    the whole sheet, so it arrives last. */}
                <div className="flex items-end justify-between gap-4 border-t border-graphite-200 px-6 py-4">
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.16em] text-graphite-400">
                            Approved by
                        </p>
                        <p className="mt-1 text-[13px] font-semibold text-navy-900">Rudi Hartanto</p>
                        <p className="text-[11px] text-graphite-500">HSE Supervisor</p>
                    </div>

                    <div className="motion-safe:animate-stamp-land rotate-[-6deg] rounded-md border-2 border-success/70 px-3 py-1.5">
                        <p className="font-display text-[11px] font-bold uppercase tracking-[0.14em] text-success">
                            Approved
                        </p>
                        <p className="text-center font-mono text-[9px] text-success/80">01 Okt 07:42</p>
                    </div>
                </div>
            </div>
        </div>
    );
}

function Field({ label, value, mono = false }) {
    return (
        <div>
            <p className="font-mono text-[10px] uppercase tracking-[0.16em] text-graphite-400">{label}</p>
            <p className={`mt-1 text-[13px] text-navy-900 ${mono ? 'font-mono' : 'font-medium'}`}>{value}</p>
        </div>
    );
}
