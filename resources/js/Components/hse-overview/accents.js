/**
 * v2.109.0 -- THE HSE OVERVIEW'S COLOUR SYSTEM, IN ONE FILE.
 *
 * Five accents with fixed meanings, applied the same way by every tile,
 * panel and calendar chip on the Overview:
 *
 *   red     a critical condition -- something is wrong now
 *   amber   a warning -- something needs attention soon
 *   green   a positive or completed state
 *   violet  a selected or categorical distinction
 *   blue    the primary, neutral-operational accent
 *   slate   deliberately NOT a status: unknown, unavailable, or not yet
 *           measured. It is the colour of "we do not know", and it is why
 *           a missing value never arrives dressed as a good one.
 *
 * SCOPED TO THIS PAGE ON PURPOSE. These classes are not exported into the
 * shared design system and nothing outside `Components/hse-overview/`
 * imports them, so the Overview can establish the standard without
 * changing how any other module renders today. When the rest of the
 * product adopts it, this file is what gets promoted.
 *
 * Written as whole class strings rather than interpolated fragments
 * because Tailwind scans source text: `bg-${tone}-50` compiles to nothing.
 */
export const ACCENTS = {
    blue: {
        icon: 'icon-tile icon-tile-blue',
        value: 'text-blue-700 dark:text-blue-300',
        chip: 'bg-blue-50 text-blue-700 ring-blue-200/70 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/30',
        dot: 'bg-blue-500',
        bar: 'bg-blue-500',
        surface: 'from-blue-50/70',
    },
    green: {
        icon: 'icon-tile icon-tile-green',
        value: 'text-emerald-700 dark:text-emerald-300',
        chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200/70 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30',
        dot: 'bg-emerald-500',
        bar: 'bg-emerald-500',
        surface: 'from-emerald-50/70',
    },
    amber: {
        icon: 'icon-tile icon-tile-amber',
        value: 'text-amber-700 dark:text-amber-300',
        chip: 'bg-amber-50 text-amber-700 ring-amber-200/70 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
        dot: 'bg-amber-500',
        bar: 'bg-amber-500',
        surface: 'from-amber-50/70',
    },
    red: {
        icon: 'icon-tile icon-tile-rose',
        value: 'text-red-700 dark:text-red-300',
        chip: 'bg-red-50 text-red-700 ring-red-200/70 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30',
        dot: 'bg-red-500',
        bar: 'bg-red-500',
        surface: 'from-red-50/70',
    },
    violet: {
        icon: 'icon-tile icon-tile-violet',
        value: 'text-violet-700 dark:text-violet-300',
        chip: 'bg-violet-50 text-violet-700 ring-violet-200/70 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/30',
        dot: 'bg-violet-500',
        bar: 'bg-violet-500',
        surface: 'from-violet-50/70',
    },
    slate: {
        icon: 'icon-tile icon-tile-slate',
        value: 'text-graphite-500 dark:text-slate-400',
        chip: 'bg-graphite-100 text-graphite-600 ring-graphite-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
        dot: 'bg-graphite-400',
        bar: 'bg-graphite-400',
        surface: 'from-graphite-50',
    },
};

/** Chart.js stroke/fill values for the trend series, same five hues. */
export const SERIES_COLORS = {
    incidents: '#ef4444',
    observations: '#f59e0b',
    capa: '#8b5cf6',
    permits: '#2563eb',
};

/**
 * Calendar category colours.
 *
 * Keyed by the `event_type` CalendarService already returns, with a
 * `source` fallback for the virtual events computed from other modules
 * (PTW, leave, milestones) that carry no author-chosen type. Anything
 * unrecognised reads slate rather than being assigned a meaning it does
 * not have.
 */
export const EVENT_ACCENTS = {
    meeting: 'violet',
    deadline: 'red',
    reminder: 'amber',
    leave: 'blue',
    general: 'blue',
    inspection: 'green',
    ptw: 'amber',
    milestone: 'violet',
};

export function accentFor(name) {
    return ACCENTS[name] ?? ACCENTS.slate;
}

export function eventAccent(event) {
    return accentFor(EVENT_ACCENTS[event?.event_type] ?? EVENT_ACCENTS[event?.source] ?? 'slate');
}
