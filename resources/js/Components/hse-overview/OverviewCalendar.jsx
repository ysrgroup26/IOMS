import { useMemo, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { eventAccent } from './accents';
import { humanizeToken } from '@/lib/humanizeToken';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ChevronLeft, ChevronRight, CalendarDays, ArrowUpRight, Plus } from 'lucide-react';

/**
 * v2.109.0 -- THE CALENDAR, INSIDE THE OVERVIEW.
 *
 * The top bar used to carry a Calendar shortcut and this page carried a
 * list of the next six HSE events. Neither was a calendar: one was a link
 * and the other was an agenda. This is a real month grid with the events
 * on it, sized to sit in the dashboard rather than to be a page.
 *
 * WHAT IT DOES NOT DO, ON PURPOSE. It is not a second copy of
 * `Pages/Calendar/Index.jsx`: no editing, no dragging, no source filters,
 * and no second event store. Those live on the full Calendar, one click
 * away.
 *
 * v2.116.0 -- IT CAN NOW ADD A SCHEDULE, THROUGH THE EXISTING ROUTE. The
 * approved design puts a `+ Add Schedule` action inside the calendar, and
 * a button that opens the full Calendar instead of scheduling anything
 * would be a shortcut wearing an action's clothes. It POSTs to
 * `calendar.store` -- the same route, validation, authorization and
 * activity-log entry the full Calendar's own dialog uses -- with the
 * selected day and `department_key: 'hse'` filled in, which is the only
 * thing this context adds. No new model, no new table, no second write
 * path. It is hidden when the account has no operating unit to attach an
 * event to, because the route requires one.
 *
 * NAVIGATION IS BOUNDED BY THE DATA, NOT BY A GUESS. The server sends a
 * three-month window (previous, current, next) from the SAME aggregation
 * the old widget used. Previous/next move within that window and stop at
 * its edges, because paging past it would silently show empty months that
 * are not empty. Anything outside is what the full Calendar is for.
 *
 * TIMEZONE. Dates are bucketed by the calendar day the server already
 * resolved, taken from the ISO string's own date part rather than by
 * constructing a local `Date` -- `new Date('2026-01-15T00:00:00+07:00')`
 * in a browser running behind that offset lands on the 14th, which would
 * quietly move an event a day. CalendarService decides what day an event
 * is on; this only groups by it.
 */

const WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

/** The calendar day an ISO timestamp belongs to, as the server wrote it. */
function dayKey(iso) {
    return typeof iso === 'string' ? iso.slice(0, 10) : '';
}

function monthKey(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

/** Monday-first offset for the 1st of a month. */
function leadingBlanks(year, month) {
    const weekday = new Date(year, month, 1).getDay(); // 0 = Sunday
    return (weekday + 6) % 7;
}

function monthLabel(year, month) {
    return new Date(year, month, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
}

/**
 * The compact create form. Deliberately narrower than the full
 * Calendar's: title, day, time and type are what an HSE schedule needs
 * from this page, and everything else the route accepts keeps its own
 * server-side default. `preserveScroll` because the controller answers
 * `back()`, so the page re-renders with the new event already on the grid.
 */
function AddScheduleDialog({ companies, defaultDay, onClose }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        title: '',
        start_at: `${defaultDay}T09:00`,
        event_type: 'general',
        department_key: 'hse',
        all_day: false,
    });

    function submit(e) {
        e.preventDefault();
        post(route('calendar.store'), {
            preserveScroll: true,
            onSuccess: () => { reset(); onClose(); },
        });
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add Schedule</DialogTitle>
                    <DialogDescription>
                        Agenda ini tersimpan di Calendar yang sama dengan halaman Calendar penuh, bukan salinan terpisah.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-3">
                    <div className="space-y-1.5">
                        <Label htmlFor="schedule-title">Title</Label>
                        <Input
                            id="schedule-title"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder="Weekly Inspection - Workshop Zone B"
                        />
                        {errors.title && <p className="text-xs text-red-600">{errors.title}</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="schedule-start">Starts</Label>
                            <Input
                                id="schedule-start"
                                type="datetime-local"
                                value={data.start_at}
                                onChange={(e) => setData('start_at', e.target.value)}
                            />
                            {errors.start_at && <p className="text-xs text-red-600">{errors.start_at}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Type</Label>
                            <Select value={data.event_type} onValueChange={(v) => setData('event_type', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {['general', 'meeting', 'deadline', 'reminder'].map((t) => (
                                        <SelectItem key={t} value={t}>{humanizeToken(t)}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {companies.length > 1 && (
                        <div className="space-y-1.5">
                            <Label>Operating Unit</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {companies.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose} disabled={processing}>Cancel</Button>
                        <Button type="submit" disabled={processing}>{processing ? 'Saving...' : 'Add Schedule'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function OverviewCalendar({ events = [], window: dataWindow, today, companies = [] }) {
    const todayKey = today ?? dayKey(new Date().toISOString());

    const [cursor, setCursor] = useState(() => {
        const [y, m] = todayKey.split('-').map(Number);
        return { year: y, month: m - 1 };
    });

    const [selected, setSelected] = useState(todayKey);
    const [addOpen, setAddOpen] = useState(false);

    /** day key -> events on that day. */
    const byDay = useMemo(() => {
        const map = new Map();
        events.forEach((event) => {
            const key = dayKey(event.start);
            if (!key) return;
            if (!map.has(key)) map.set(key, []);
            map.get(key).push(event);
        });
        return map;
    }, [events]);

    const { year, month } = cursor;
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const blanks = leadingBlanks(year, month);

    // The window the server actually sent, so navigation cannot run past
    // the data and show a real month as empty.
    const windowStartMonth = dataWindow?.start?.slice(0, 7);
    const windowEndMonth = dataWindow?.end?.slice(0, 7);
    const current = monthKey(new Date(year, month, 1));
    const canGoBack = !windowStartMonth || current > windowStartMonth;
    const canGoForward = !windowEndMonth || current < windowEndMonth;

    function step(delta) {
        setCursor(({ year: y, month: m }) => {
            const next = new Date(y, m + delta, 1);
            return { year: next.getFullYear(), month: next.getMonth() };
        });
    }

    const selectedEvents = byDay.get(selected) ?? [];
    const monthHasEvents = Array.from(byDay.keys()).some((k) => k.startsWith(current));

    return (
        <section className="flex h-full flex-col overflow-hidden rounded-2xl border border-graphite-200/80 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.04)] dark:border-slate-800 dark:bg-slate-900">
            <header className="flex items-center justify-between gap-2 border-b border-graphite-100 px-4 py-3 dark:border-slate-800">
                <div className="min-w-0">
                    <h2 className="flex items-center gap-1.5 text-[13px] font-bold text-graphite-900 dark:text-slate-50">
                        <CalendarDays className="h-4 w-4 text-violet-500" /> HSE Calendar
                    </h2>
                    <p className="mt-0.5 truncate text-[11px] text-graphite-400 dark:text-slate-500">
                        Jadwal inspeksi, izin kerja, dan agenda HSE.
                    </p>
                </div>

                <div className="flex shrink-0 items-center gap-1">
                    <button
                        type="button"
                        onClick={() => step(-1)}
                        disabled={!canGoBack}
                        aria-label="Previous month"
                        className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-graphite-200 text-graphite-500 transition-colors hover:bg-graphite-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
                    >
                        <ChevronLeft className="h-3.5 w-3.5" />
                    </button>
                    <button
                        type="button"
                        onClick={() => step(1)}
                        disabled={!canGoForward}
                        aria-label="Next month"
                        className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-graphite-200 text-graphite-500 transition-colors hover:bg-graphite-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
                    >
                        <ChevronRight className="h-3.5 w-3.5" />
                    </button>
                </div>
            </header>

            <div className="px-4 pt-3">
                <p className="text-center text-[12px] font-semibold text-graphite-800 dark:text-slate-100">
                    {monthLabel(year, month)}
                </p>

                <div className="mt-2 grid grid-cols-7 gap-1 text-center">
                    {WEEKDAYS.map((d) => (
                        <span key={d} className="text-[10px] font-semibold uppercase tracking-wide text-graphite-400 dark:text-slate-600">{d}</span>
                    ))}

                    {Array.from({ length: blanks }).map((_, i) => <span key={`blank-${i}`} />)}

                    {Array.from({ length: daysInMonth }).map((_, i) => {
                        const day = i + 1;
                        const key = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                        const dayEvents = byDay.get(key) ?? [];
                        const isToday = key === todayKey;
                        const isSelected = key === selected;

                        return (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setSelected(key)}
                                aria-label={`${day} ${monthLabel(year, month)}, ${dayEvents.length} event(s)`}
                                aria-pressed={isSelected}
                                className={cn(
                                    'relative flex h-9 flex-col items-center justify-center rounded-lg text-[12px] transition-colors',
                                    'hover:bg-graphite-50 dark:hover:bg-slate-800',
                                    isToday && !isSelected && 'font-bold text-blue-600 dark:text-blue-300',
                                    !isToday && !isSelected && 'text-graphite-600 dark:text-slate-300',
                                    isSelected && 'bg-violet-600 font-semibold text-white hover:bg-violet-600 dark:bg-violet-500'
                                )}
                            >
                                {day}

                                {/* At most three dots: a day with nine events
                                    must not become a different-sized cell. */}
                                {dayEvents.length > 0 && (
                                    <span className="absolute bottom-1 flex items-center gap-0.5">
                                        {dayEvents.slice(0, 3).map((event, index) => (
                                            <span
                                                key={index}
                                                className={cn(
                                                    'h-1 w-1 rounded-full',
                                                    isSelected ? 'bg-white/90' : eventAccent(event).dot
                                                )}
                                            />
                                        ))}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>
            </div>

            {/* The selected day's agenda. Scrolls inside its own box so a
                busy day cannot stretch the dashboard grid. */}
            <div className="mt-3 flex min-h-0 flex-1 flex-col border-t border-graphite-100 px-4 py-3 dark:border-slate-800">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-graphite-400 dark:text-slate-500">
                    {selected === todayKey ? 'Today' : new Date(`${selected}T12:00:00`).toLocaleDateString('en-US', { day: 'numeric', month: 'long' })}
                </p>

                {selectedEvents.length === 0 ? (
                    <p className="mt-2 text-[11px] leading-snug text-graphite-400 dark:text-slate-500">
                        {monthHasEvents
                            ? 'Tidak ada agenda pada tanggal ini.'
                            : 'Belum ada agenda HSE pada bulan ini.'}
                    </p>
                ) : (
                    <ul className="mt-2 max-h-[132px] space-y-1.5 overflow-y-auto pr-1">
                        {selectedEvents.map((event, index) => {
                            const tone = eventAccent(event);
                            const inner = (
                                <>
                                    <span className={cn('mt-[5px] h-1.5 w-1.5 shrink-0 rounded-full', tone.dot)} />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[12px] font-medium text-graphite-800 dark:text-slate-100">{event.title}</span>
                                        <span className="mt-0.5 flex flex-wrap items-center gap-1.5">
                                            {/* humanizeToken rather than CSS capitalize --
                                                an event type is a stored token and
                                                `capitalize` would render an acronym
                                                wrongly (ProductTerminologyTest). */}
                                            <span className={cn('inline-flex rounded px-1.5 py-px text-[10px] font-medium ring-1', tone.chip)}>
                                                {humanizeToken(event.event_type ?? event.source)}
                                            </span>
                                            {event.responsible && (
                                                <span className="truncate text-[10px] text-graphite-400 dark:text-slate-500">{event.responsible}</span>
                                            )}
                                        </span>
                                    </span>
                                    {event.url && <ArrowUpRight className="mt-0.5 h-3 w-3 shrink-0 text-graphite-300" />}
                                </>
                            );

                            // Only events whose own module gave the server a
                            // destination are navigable. A manual event has
                            // no page of its own, so it is not a dead link.
                            return event.url ? (
                                <li key={index}>
                                    <Link href={event.url} className="flex items-start gap-2 rounded-lg px-1.5 py-1 transition-colors hover:bg-graphite-50 dark:hover:bg-slate-800">
                                        {inner}
                                    </Link>
                                </li>
                            ) : (
                                <li key={index} className="flex items-start gap-2 px-1.5 py-1">{inner}</li>
                            );
                        })}
                    </ul>
                )}

                <div className="mt-auto flex items-center justify-between gap-2 pt-3">
                    <Link
                        href={route('calendar.index')}
                        className="inline-flex items-center gap-1 text-[11px] font-semibold text-blue-600 hover:underline dark:text-blue-400"
                    >
                        Open full calendar <ArrowUpRight className="h-3 w-3" />
                    </Link>

                    {/* Hidden, not disabled, when there is no operating unit
                        to attach an event to: `calendar.store` requires one,
                        so the control could never succeed. */}
                    {companies.length > 0 && (
                        <button
                            type="button"
                            onClick={() => setAddOpen(true)}
                            className="inline-flex items-center gap-1 rounded-lg border border-graphite-200 px-2 py-1 text-[11px] font-semibold text-graphite-600 transition-colors hover:bg-graphite-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            <Plus className="h-3 w-3" /> Add Schedule
                        </button>
                    )}
                </div>
            </div>

            {addOpen && (
                <AddScheduleDialog
                    companies={companies}
                    defaultDay={selected}
                    onClose={() => setAddOpen(false)}
                />
            )}
        </section>
    );
}
