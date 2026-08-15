import { Head, router, useForm, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Badge } from '@/Components/ui/badge';
import { Checkbox } from '@/Components/ui/checkbox';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import EmptyState from '@/Components/shared/EmptyState';
import {
    startOfMonth, endOfMonth, startOfWeek, endOfWeek, eachDayOfInterval,
    format, addMonths, subMonths, isSameMonth, isSameDay, isToday,
} from 'date-fns';
import { CalendarDays, ChevronLeft, ChevronRight, Plus, List, Grid3x3 } from 'lucide-react';

const TYPE_BADGE = { general: 'secondary', meeting: 'default', deadline: 'destructive', reminder: 'default', leave: 'secondary' };

/**
 * v1.11.0 (SaaS Finalization Pass, Part 4/5). ONE global calendar --
 * month grid + agenda list (date-fns, already in package.json; no new
 * dependency added -- audited first, per the explicit instruction not to
 * introduce an unnecessarily heavy calendar library). Events come from
 * CalendarController::index() -- a mix of real manual events (editable)
 * and read-only "virtual" events computed from other modules' own due
 * dates (Leave/PTW/TBM/Milestone/Work Order) -- `event.editable`
 * distinguishes the two; only manual events show Edit/Delete.
 */
export default function CalendarIndex({ events, range, eventTypes, companies, can }) {
    const [view, setView] = useState('month');
    const [cursor, setCursor] = useState(new Date(range.start));
    const [selectedDay, setSelectedDay] = useState(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [detailEvent, setDetailEvent] = useState(null);

    const days = useMemo(() => {
        const start = startOfWeek(startOfMonth(cursor));
        const end = endOfWeek(endOfMonth(cursor));
        return eachDayOfInterval({ start, end });
    }, [cursor]);

    const eventsByDay = useMemo(() => {
        const map = {};
        for (const e of events) {
            if (!e.start) continue;
            const key = format(new Date(e.start), 'yyyy-MM-dd');
            (map[key] ||= []).push(e);
        }
        return map;
    }, [events]);

    function navigate(direction) {
        const next = direction === 'next' ? addMonths(cursor, 1) : subMonths(cursor, 1);
        setCursor(next);
        router.get(route('calendar.index'), {
            start: startOfMonth(next).toISOString().slice(0, 10),
            end: endOfMonth(next).toISOString().slice(0, 10),
        }, { preserveState: true, replace: true });
    }

    function goToday() {
        setCursor(new Date());
        router.get(route('calendar.index'), {}, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Calendar" />
            <PageHeader title="Calendar" subtitle="Company-wide schedule -- manual events plus deadlines pulled from Leave, PTW, TBM, Milestones, and Work Orders.">
                <div className="flex items-center gap-2">
                    <Button variant="outline" size="sm" onClick={() => setView(view === 'month' ? 'agenda' : 'month')}>
                        {view === 'month' ? <List className="h-4 w-4" /> : <Grid3x3 className="h-4 w-4" />} {view === 'month' ? 'Agenda' : 'Month'}
                    </Button>
                    <Button size="sm" onClick={() => setCreateOpen(true)}><Plus className="h-4 w-4" /> New Event</Button>
                </div>
            </PageHeader>

            <div className="mb-4 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Button variant="outline" size="icon" onClick={() => navigate('prev')}><ChevronLeft className="h-4 w-4" /></Button>
                    <Button variant="outline" size="sm" onClick={goToday}>Today</Button>
                    <Button variant="outline" size="icon" onClick={() => navigate('next')}><ChevronRight className="h-4 w-4" /></Button>
                    <span className="ml-2 text-sm font-semibold text-graphite-800 dark:text-slate-100">{format(cursor, 'MMMM yyyy')}</span>
                </div>
            </div>

            {view === 'month' ? (
                <Card>
                    <CardContent className="p-3">
                        <div className="grid grid-cols-7 gap-1 text-center text-xs font-semibold uppercase text-graphite-400">
                            {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((d) => <div key={d} className="py-1">{d}</div>)}
                        </div>
                        <div className="grid grid-cols-7 gap-1">
                            {days.map((day) => {
                                const key = format(day, 'yyyy-MM-dd');
                                const dayEvents = eventsByDay[key] || [];
                                return (
                                    <button
                                        key={key}
                                        type="button"
                                        onClick={() => setSelectedDay(day)}
                                        className={`min-h-[86px] rounded-md border p-1.5 text-left align-top text-xs transition-colors ${
                                            isSameMonth(day, cursor) ? 'border-graphite-100 bg-white dark:bg-slate-900 dark:border-slate-800' : 'border-graphite-50 bg-graphite-50/50 text-graphite-300 dark:bg-slate-950/40'
                                        } ${isToday(day) ? 'ring-2 ring-brand-400' : ''} ${selectedDay && isSameDay(selectedDay, day) ? 'bg-brand-50 dark:bg-brand-900/20' : ''} hover:bg-graphite-50 dark:hover:bg-slate-800`}
                                    >
                                        <span className={`font-medium ${isToday(day) ? 'text-brand-700' : ''}`}>{format(day, 'd')}</span>
                                        <div className="mt-1 space-y-0.5">
                                            {dayEvents.slice(0, 3).map((e) => (
                                                <div key={e.id} onClick={(ev) => { ev.stopPropagation(); setDetailEvent(e); }} className="truncate rounded bg-graphite-100 px-1 py-0.5 text-[10px] text-graphite-700 hover:bg-graphite-200 dark:bg-slate-800 dark:text-slate-200">
                                                    {e.title}
                                                </div>
                                            ))}
                                            {dayEvents.length > 3 && <div className="text-[10px] text-graphite-400">+{dayEvents.length - 3} more</div>}
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>
            ) : (
                <Card>
                    <CardContent className="p-0">
                        {events.length === 0 ? (
                            <EmptyState icon={CalendarDays} title="No events in this range" />
                        ) : (
                            <ul className="divide-y divide-graphite-100 dark:divide-slate-800">
                                {events.map((e) => (
                                    <li key={e.id} className="flex cursor-pointer items-center justify-between gap-3 px-4 py-2.5 text-sm hover:bg-graphite-50 dark:hover:bg-slate-800" onClick={() => setDetailEvent(e)}>
                                        <div className="min-w-0">
                                            <p className="truncate font-medium text-graphite-800 dark:text-slate-100">{e.title}</p>
                                            <p className="text-xs text-graphite-400">{e.start && format(new Date(e.start), 'EEE, d MMM yyyy HH:mm')}{e.responsible && ` · ${e.responsible}`}</p>
                                        </div>
                                        <Badge variant={TYPE_BADGE[e.event_type] ?? 'secondary'} className="capitalize shrink-0">{e.event_type}</Badge>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            )}

            {selectedDay && (
                <Card className="mt-4">
                    <CardContent className="p-4">
                        <p className="mb-2 text-sm font-semibold text-graphite-700 dark:text-slate-200">{format(selectedDay, 'EEEE, d MMMM yyyy')}</p>
                        {(eventsByDay[format(selectedDay, 'yyyy-MM-dd')] || []).length === 0 ? (
                            <p className="text-sm text-graphite-400">No events.</p>
                        ) : (
                            <ul className="space-y-1.5">
                                {(eventsByDay[format(selectedDay, 'yyyy-MM-dd')] || []).map((e) => (
                                    <li key={e.id} className="flex cursor-pointer items-center justify-between rounded-md border border-graphite-100 px-2.5 py-1.5 text-sm hover:bg-graphite-50 dark:border-slate-800 dark:hover:bg-slate-800" onClick={() => setDetailEvent(e)}>
                                        <span>{e.title}</span>
                                        <Badge variant={TYPE_BADGE[e.event_type] ?? 'secondary'} className="capitalize">{e.event_type}</Badge>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            )}

            {createOpen && <EventDialog companies={companies} eventTypes={eventTypes} defaultDate={selectedDay} can={can} onClose={() => setCreateOpen(false)} />}
            {detailEvent && <EventDetailDialog event={detailEvent} eventTypes={eventTypes} onClose={() => setDetailEvent(null)} />}
        </AuthenticatedLayout>
    );
}

function EventDialog({ companies, eventTypes, defaultDate, can, onClose }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        title: '',
        description: '',
        start_at: defaultDate ? `${format(defaultDate, 'yyyy-MM-dd')}T09:00` : new Date().toISOString().slice(0, 16),
        end_at: '',
        all_day: false,
        event_type: 'general',
        is_management_event: false,
    });

    function submit(e) {
        e.preventDefault();
        post(route('calendar.store'), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    }

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader><DialogTitle>New Calendar Event</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="space-y-1.5"><Label>Title</Label><Input value={data.title} onChange={(e) => setData('title', e.target.value)} />{errors.title && <p className="text-xs text-red-600">{errors.title}</p>}</div>
                    <div className="space-y-1.5"><Label>Description</Label><Textarea rows={2} value={data.description} onChange={(e) => setData('description', e.target.value)} /></div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5"><Label>Start</Label><Input type="datetime-local" value={data.start_at} onChange={(e) => setData('start_at', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>End (optional)</Label><Input type="datetime-local" value={data.end_at} onChange={(e) => setData('end_at', e.target.value)} /></div>
                        <div className="space-y-1.5">
                            <Label>Type</Label>
                            <Select value={data.event_type} onValueChange={(v) => setData('event_type', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{eventTypes.map((t) => <SelectItem key={t} value={t} className="capitalize">{t}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                    </div>
                    {can?.markManagement && (
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.is_management_event} onCheckedChange={(v) => setData('is_management_event', !!v)} />
                            Show on Management Calendar
                        </label>
                    )}
                    <DialogFooter><Button type="button" variant="outline" onClick={onClose}>Cancel</Button><Button type="submit" disabled={processing}>Create</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EventDetailDialog({ event, onClose }) {
    function destroy() {
        if (!confirm(`Remove "${event.title}"?`)) return;
        router.delete(route('calendar.destroy', event.source_id), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader><DialogTitle>{event.title}</DialogTitle></DialogHeader>
                <div className="space-y-2 text-sm">
                    <p><span className="text-graphite-400">When: </span>{event.start && format(new Date(event.start), 'EEE, d MMM yyyy HH:mm')}{event.end && ` – ${format(new Date(event.end), 'HH:mm')}`}</p>
                    {event.description && <p><span className="text-graphite-400">Notes: </span>{event.description}</p>}
                    {event.responsible && <p><span className="text-graphite-400">Responsible: </span>{event.responsible}</p>}
                    <p><span className="text-graphite-400">Type: </span><Badge variant={TYPE_BADGE[event.event_type] ?? 'secondary'} className="capitalize">{event.event_type}</Badge>{event.is_management_event && <Badge variant="default" className="ml-1.5">Management Calendar</Badge>}</p>
                    {!event.editable && <p className="text-xs text-graphite-400">This event is pulled automatically from {event.source.replace('-', ' ')} and can't be edited here.</p>}
                </div>
                <DialogFooter>
                    {event.url && <Button variant="outline" asChild><Link href={event.url}>Open Record</Link></Button>}
                    {event.editable && <Button variant="destructive" onClick={destroy}>Delete</Button>}
                    <Button variant="outline" onClick={onClose}>Close</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
