import { ShieldCheck, CheckCircle2 } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * v2.125.0 -- THE STAMPS A CONTROLLED DOCUMENT CARRIES.
 *
 * Built from the owner's stamp design reference as COMPONENTS, not as the
 * reference picture. A pasted image cannot say who signed, cannot change
 * when the permit closes, cannot be read by a screen reader and cannot be
 * printed at a different size without going soft -- so the reference is
 * treated as what it is, a specification of style and composition, and
 * everything it specifies is rebuilt in CSS and type.
 *
 * TWO FAMILIES, BECAUSE THEY ANSWER TWO DIFFERENT QUESTIONS.
 *
 *   A STATE stamp (`active`, `closed`) answers "what is this document's
 *   standing right now". It is slanted, double-ruled and distressed,
 *   because that is what a rubber stamp applied by hand looks like, and
 *   it carries NO date and no name -- the owner's brief is explicit about
 *   that, and it is right: a date inside a standing stamp reads as the
 *   date the standing was granted, which is a different fact that lives
 *   in the document body. The two differ ONLY in colour and word.
 *
 *   An ACTION stamp (`verified`, `approved`) answers "who did what, and
 *   when". It is upright and rectangular, carries an icon for the act,
 *   and names the person, their job title and the time -- because an
 *   action without an actor is not evidence of anything.
 *
 * WHAT KEEPS THEM HONEST. An action stamp takes the server's own action
 * record and nothing else. There is no `approved` boolean to pass, so
 * "show the stamp" is not a decision a page is able to make; a missing
 * record renders nothing. @see ApprovalStamp, which is the PTW-specific
 * door onto this and holds that contract.
 *
 * A state stamp does take a status, because a status IS the document's
 * own column -- but it renders only for the two states that are a
 * standing. Every other status is a status, shown as a badge, and
 * deliberately gets no stamp: a stamp that can appear in six colours is a
 * status badge wearing a costume, and somebody reading an amber seal at a
 * glance on site as authorisation is exactly the failure worth designing
 * out.
 *
 * MOBILE GETS A BADGE, NOT A SHRUNKEN STAMP. A slanted, distressed,
 * double-ruled block at 60% size is illegible and eats a phone's whole
 * first screen. `compact` renders the same fact as a pill. Desktop and
 * PDF keep the stamp. Same meaning, different medium, which is the rule
 * for everything in this product.
 */

const STATE_STAMPS = {
    active: { word: 'ACTIVE', tone: 'emerald' },
    closed: { word: 'CLOSED', tone: 'red' },
};

const STATE_TONE = {
    emerald: {
        stamp: 'border-emerald-600/70 text-emerald-700 dark:border-emerald-400/60 dark:text-emerald-300',
        rule: 'border-emerald-600/40 dark:border-emerald-400/40',
        badge: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
        dot: 'bg-emerald-500',
    },
    red: {
        stamp: 'border-red-600/70 text-red-700 dark:border-red-400/60 dark:text-red-300',
        rule: 'border-red-600/40 dark:border-red-400/40',
        badge: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300',
        dot: 'bg-red-500',
    },
};

/**
 * The distressed ink effect.
 *
 * A real stamp never transfers evenly: the ink breaks up across the
 * grain of the paper. That is reproduced with two layered repeating
 * gradients at low opacity, in `currentColor` so one rule serves both
 * colours and both themes, and masked to the element so it cannot bleed.
 * It is decoration only -- it carries no information, which is why it is
 * `aria-hidden` and why the stamp is perfectly legible without it (it
 * does not survive a Dompdf render, and the PDF's own stamp rules say so
 * in `resources/views/pdf/permit-to-work.blade.php`).
 */
function Distress() {
    return (
        <span
            aria-hidden="true"
            className="pointer-events-none absolute inset-0 opacity-[0.22] mix-blend-multiply dark:mix-blend-screen"
            style={{
                backgroundImage:
                    'repeating-linear-gradient(118deg, transparent 0 2px, rgba(255,255,255,0.9) 2px 3px),'
                    + 'repeating-linear-gradient(14deg, transparent 0 3px, rgba(255,255,255,0.75) 3px 4px)',
            }}
        />
    );
}

/**
 * The document's STANDING. `status` is the record's own column; anything
 * that is not a standing renders nothing, and the caller shows its badge.
 */
export function DocumentStateStamp({ status, compact = false, className }) {
    const spec = STATE_STAMPS[status];

    if (!spec) {
        return null;
    }

    const tone = STATE_TONE[spec.tone];

    if (compact) {
        return (
            <span
                className={cn(
                    'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide',
                    tone.badge,
                    className
                )}
            >
                <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', tone.dot)} aria-hidden="true" />
                {spec.word}
            </span>
        );
    }

    return (
        <span
            role="img"
            aria-label={spec.word}
            className={cn(
                // The slant is on the stamp, never on its container, so a
                // rotated block cannot push the layout around it: the
                // wrapper keeps the upright box and the ink leans inside.
                'relative inline-block select-none overflow-hidden rounded-[3px] border-[2.5px] bg-transparent px-3.5 py-1.5 -rotate-[7deg]',
                tone.stamp,
                className
            )}
        >
            {/* The inset second rule -- what makes it an impression
                rather than an outlined chip. */}
            <span className={cn('pointer-events-none absolute inset-[2px] rounded-[2px] border', tone.rule)} aria-hidden="true" />
            <span className="relative block px-1 text-[15px] font-black uppercase leading-none tracking-[0.2em]">
                {spec.word}
            </span>
            <Distress />
        </span>
    );
}

const ACTION_STAMPS = {
    verified: {
        word: 'VERIFIED',
        Icon: CheckCircle2,
        ring: 'border-emerald-600/70 text-emerald-700 dark:border-emerald-400/60 dark:text-emerald-300',
        rule: 'border-emerald-600/35 dark:border-emerald-400/35',
        body: 'text-emerald-900 dark:text-emerald-200',
    },
    approved: {
        word: 'APPROVED',
        Icon: ShieldCheck,
        ring: 'border-blue-700/70 text-blue-800 dark:border-blue-400/60 dark:text-blue-300',
        rule: 'border-blue-700/35 dark:border-blue-400/35',
        body: 'text-blue-950 dark:text-blue-200',
    },
};

/**
 * An ACTION that was performed, by somebody, at a time.
 *
 * `by` / `title` / `at` are rendered exactly as given and nothing is
 * substituted: a missing title means no title line, not the system role
 * and not a placeholder. `by` is required, because an action stamp
 * without an actor is the one thing this component must never draw --
 * that is what would let a reader take an unsigned document for a signed
 * one. The caller gets the values from a stored action record; see
 * `PermitToWorkController::authorizationFor()`.
 *
 * `kindLabel` is the FUNCTION of the act as the workflow defines it
 * ("HSE", "MANAGER", or whatever a company's own approver stage is
 * called). It is a label, never a grant: changing it cannot change who
 * may approve anything.
 */
export function DocumentActionStamp({ variant = 'approved', by, title, at, kindLabel, compact = false, className }) {
    const spec = ACTION_STAMPS[variant];

    if (!spec || !by) {
        return null;
    }

    const { Icon } = spec;
    const spoken = [spec.word.toLowerCase(), kindLabel ? `by ${kindLabel}` : null, `- ${by}`, title, at]
        .filter(Boolean)
        .join(', ');

    /*
     * COMPACT STILL CARRIES THE TITLE.
     *
     * The first cut of this dropped it, which was caught in the browser:
     * the permit's detail page renders the seal compactly inside a field
     * grid, so "APPROVED, Sri Handayani" was all a reader got and the
     * authority -- the entire reason the title is read from the company's
     * own configuration rather than from our role column -- was the one
     * fact the most-visited surface left out.
     *
     * So compact means SMALLER, not LESS TRUE. The icon, the act, the
     * person and their title all survive; what goes is the double rule,
     * the distress and the timestamp, which are composition rather than
     * evidence (the time is in the activity history on the same page).
     */
    if (compact) {
        return (
            <span
                role="img"
                aria-label={spoken}
                className={cn('inline-flex max-w-full items-center gap-1.5 rounded-md border px-2.5 py-1', spec.ring, className)}
            >
                <Icon className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span className="min-w-0 leading-tight" aria-hidden="true">
                    <span className="flex items-baseline gap-1.5">
                        <span className="text-[11px] font-semibold uppercase tracking-wide">{spec.word}</span>
                        <span className={cn('truncate text-[11px] font-normal', spec.body)}>{by}</span>
                    </span>
                    {title && (
                        <span className={cn('block truncate text-[10px] uppercase tracking-wide opacity-70', spec.body)}>{title}</span>
                    )}
                </span>
            </span>
        );
    }

    return (
        <span
            role="img"
            aria-label={spoken}
            className={cn('relative inline-flex max-w-full flex-col items-center gap-1 overflow-hidden rounded-[3px] border-2 px-4 py-2', spec.ring, className)}
        >
            <span className={cn('pointer-events-none absolute inset-[2px] rounded-[2px] border', spec.rule)} aria-hidden="true" />

            <span className="relative flex items-center gap-1.5" aria-hidden="true">
                <Icon className="h-4 w-4 shrink-0" />
                <span className="text-[13px] font-black uppercase leading-none tracking-[0.18em]">{spec.word}</span>
            </span>

            {kindLabel && (
                <span className="relative text-[9px] font-semibold uppercase leading-none tracking-[0.22em] opacity-80" aria-hidden="true">
                    {kindLabel}
                </span>
            )}

            <span className={cn('relative w-full border-t', spec.rule)} aria-hidden="true" />

            <span className={cn('relative text-center leading-tight', spec.body)} aria-hidden="true">
                <span className="block text-xs font-semibold">{by}</span>
                {title && <span className="block text-[10px] uppercase tracking-wide opacity-70">{title}</span>}
                {at && <span className="block text-[10px] tabular-nums opacity-70">{at}</span>}
            </span>

            <Distress />
        </span>
    );
}

/**
 * The standing mark as a document surface should carry it: the stamp from
 * `sm` up, the badge below it.
 *
 * Done in CSS rather than with `useMediaQuery` deliberately. This renders
 * inside a document header that is also printed, and a JS media query
 * resolves to the viewport, not the page -- so a stamp chosen in the
 * browser at phone width would have followed the document into print at
 * phone width too. Two elements and one breakpoint cost one extra node
 * and are correct in both media.
 *
 * Renders nothing for a status that is not a standing, so the caller's
 * own `<StatusBadge>` stays responsible for every other state. See
 * `DocumentStateStamp` for why that line is drawn where it is.
 */
export function DocumentStandingMark({ status, className }) {
    if (!STATE_STAMPS[status]) {
        return null;
    }

    return (
        <span className={cn('inline-block', className)}>
            <DocumentStateStamp status={status} className="hidden sm:inline-block" />
            <DocumentStateStamp status={status} compact className="sm:hidden" />
        </span>
    );
}

/** Whether this status is one the stamp family represents. */
export function hasStandingStamp(status) {
    return Boolean(STATE_STAMPS[status]);
}
