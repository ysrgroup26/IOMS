import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import PersonChip from '@/Components/shared/PersonChip';
import { ArrowLeft, Send, CheckCircle2, XCircle, PlayCircle, FlaskConical, Wind, Download, Printer, FileText, RotateCcw } from 'lucide-react';

export default function PermitToWorkShow({ permit: p, activities, canManage, rejectionReason }) {
    const [gasTestOpen, setGasTestOpen] = useState(false);
    // v1.10.9: location pre-filled from this permit's own p.location (the
    // scope a PTW is raised for) but independently editable -- a gas
    // reading can be taken at a specific sub-location within that scope.
    // stage defaults to 'initial'; a user re-testing later picks 're_test'
    // or 'final' -- see GasTestRecord::STAGES.
    const gasTestForm = useForm({ location: p.location || '', tested_at: new Date().toISOString().slice(0, 16), stage: 'initial', o2_level: '20.9', lel_level: '0', h2s_level: '0', co_level: '0', result: 'pass', notes: '' });

    function transition(status, confirmMessage) {
        if (confirmMessage && !confirm(confirmMessage)) return;
        router.post(route('permits-to-work.transition', p.id), { status });
    }

    // v2.4.0 (PTW UX + Field Operations pass, Part 14): rejecting now
    // requires a short reason -- a native prompt() rather than a new
    // Dialog component, matching this page's own existing lightweight
    // confirm()-based interaction style rather than introducing a new UI
    // pattern for one field.
    function reject() {
        const reason = prompt('Alasan penolakan PTW ini?');
        if (reason === null) return;
        if (!reason.trim()) { alert('Alasan penolakan wajib diisi.'); return; }
        router.post(route('permits-to-work.transition', p.id), { status: 'rejected', reason: reason.trim() });
    }

    function submitGasTest(e) {
        e.preventDefault();
        gasTestForm.post(route('permits-to-work.gas-tests.store', p.id), { preserveScroll: true, onSuccess: () => { gasTestForm.reset(); setGasTestOpen(false); } });
    }

    return (
        <AuthenticatedLayout>
            <Head title={p.ptw_number} />

            <Link href={route('permits-to-work.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Permit To Work
            </Link>

            {/* v2.20.0 (PTW Experience & Visual Polish pass, Part 9): PTW
                identity/status made more prominent -- permit type now
                reads as an actual subtitle (capitalized, its own line)
                rather than sharing a small caption line with the
                date/location metadata, and the status badge sits next
                to the eyebrow instead of crowding the PTW number. */}
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <p className="font-mono text-xs font-medium text-graphite-400">{p.ptw_number}</p>
                        <StatusBadge value={p.status} />
                    </div>
                    <h1 className="mt-0.5 text-2xl font-bold capitalize tracking-tight text-graphite-900">{p.permit_type.replace('_', ' ')}</h1>
                    <p className="mt-0.5 text-xs text-graphite-500">{new Date(p.start_datetime).toLocaleString('en-US', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })} - {new Date(p.end_datetime).toLocaleString('en-US', { hour: 'numeric', minute: '2-digit' })}{p.location && ` · ${p.location}`}</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {/* v2.6.0 (PTW Document View pass): the new PRIMARY
                        document action -- was previously just Download
                        PDF/Print with no in-browser document presentation
                        at all, so a permit only ever looked like an
                        application record, never a real HSE document.
                        Solid variant so it visually dominates the
                        secondary actions next to it, matching the
                        product's own "primary action should visually
                        dominate" direction. Not gated by canManage --
                        same reasoning as Download PDF/Print below: a
                        Foreman must be able to open/share their own
                        permit's document, not just HSE. */}
                    <Button asChild><Link href={route('permits-to-work.document', p.id)}><FileText className="h-4 w-4" /> View PTW Document</Link></Button>
                    {/* v2.4.0 (PTW UX + Field Operations pass, Part 13):
                        Download PDF / Print -- always visible to anyone
                        who can view this permit (not gated by canManage,
                        matching the product requirement that a Foreman
                        must be able to download/print their own approved
                        PTW to share on-site). Kept here too (not only on
                        the Document page) as quick secondary actions --
                        same reasoning as "Download PDF should remain
                        easily accessible" from the Phase 1 direction. */}
                    <Button variant="outline" asChild><a href={route('permits-to-work.pdf', p.id)} target="_blank" rel="noopener noreferrer"><Download className="h-4 w-4" /> Download PDF</a></Button>
                    {/* Opens the same inline PDF in a new tab -- the
                        browser's own PDF viewer provides Print from
                        there (Ctrl+P / its own print icon); no separate
                        print-only rendering pipeline exists or is needed. */}
                    <Button variant="outline" asChild><a href={route('permits-to-work.pdf', p.id)} target="_blank" rel="noopener noreferrer"><Printer className="h-4 w-4" /> Print</a></Button>
                    {canManage && (<>
                        {p.status === 'draft' && (<Button variant="outline" onClick={() => transition('submitted', 'Submit for approval?')}><Send className="h-4 w-4" /> Submit</Button>)}
                        {p.status === 'submitted' && (<>
                            <Button onClick={() => transition('approved', 'Approve this permit?')}><CheckCircle2 className="h-4 w-4" /> Approve</Button>
                            <Button variant="outline" className="text-red-600" onClick={reject}>Reject</Button>
                        </>)}
                        {/* v2.8.0 (PTW Mobile / Task-First pass, Phase 3B,
                            Part 7): PermitToWork's own $transitions array
                            has always allowed rejected -> draft (a real
                            "resubmit" path -- not invented here), but no
                            button ever triggered it -- a rejected permit
                            previously had zero available action besides
                            Cancel. Moves back to Draft; the existing
                            Draft "Submit" button above then carries it to
                            Submitted again, matching the two-step state
                            machine exactly as modeled, not bypassing it
                            with a direct rejected->submitted shortcut. */}
                        {p.status === 'rejected' && (<Button variant="outline" onClick={() => transition('draft', 'Kembalikan PTW ini ke Draft untuk diajukan ulang?')}><RotateCcw className="h-4 w-4" /> Resubmit</Button>)}
                        {p.status === 'approved' && (<Button onClick={() => transition('active', 'Activate this permit -- work may begin?')}><PlayCircle className="h-4 w-4" /> Activate</Button>)}
                        {p.status === 'active' && (<Button onClick={() => transition('closed', 'Close this permit -- work complete?')}><CheckCircle2 className="h-4 w-4" /> Close</Button>)}
                        {!['closed', 'cancelled'].includes(p.status) && (
                            <Button variant="ghost" className="text-red-600" onClick={() => transition('cancelled', 'Batalkan PTW ini? PTW yang sudah dibatalkan tidak bisa digunakan kembali.')}><XCircle className="h-4 w-4" /> Cancel</Button>
                        )}
                    </>)}
                </div>
            </div>

            {/* v2.8.0 (PTW Mobile / Task-First pass, Phase 3B, Part 6):
                rejection reason previously only ever appeared inside the
                Activity timeline card (secondary, off-screen on mobile
                without scrolling) -- surfaced here immediately below the
                header, matching the product's own "For rejected PTW:
                show rejection reason if already supported" direction.
                Never fabricated: rejectionReason is null (and this
                banner shows a real "no reason recorded" fallback rather
                than inventing one) for a permit rejected before v2.4.0
                added the reason field. */}
            {p.status === 'rejected' && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-400">
                    <span className="font-medium">Alasan Penolakan:</span> {rejectionReason || 'Tidak ada alasan tercatat.'}
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div><span className="text-xs uppercase text-graphite-400">Work Description</span><p className="whitespace-pre-wrap">{p.work_description}</p></div>
                            {p.precautions && <div><span className="text-xs uppercase text-graphite-400">Precautions</span><p className="whitespace-pre-wrap">{p.precautions}</p></div>}
                            {p.required_qualification && <div><span className="text-xs uppercase text-graphite-400">Required Qualification</span><p>{p.required_qualification}</p></div>}
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div><span className="text-xs uppercase text-graphite-400">Requested By</span><p>{p.requester?.name}</p></div>
                                <div><span className="text-xs uppercase text-graphite-400">HSE Approver</span><p>{p.hse_approver?.name || '-'}</p></div>
                                <div><span className="text-xs uppercase text-graphite-400">Closed By</span><p>{p.closer?.name || '-'}</p></div>
                            </div>
                            {/* v2.17.0 (PTW Field Workflow Foundation, Part 8/9/14):
                                only rendered when actually set -- an
                                empty Workforce section isn't shown at
                                all rather than a blank "-"/"0" row for
                                every permit created before this pass. */}
                            {/* v2.20.0: PIC/Workforce now shown as
                                PersonChip (initials avatar + name), the
                                same reusable component the Document view
                                uses -- consistent visual language for
                                "this is a real person" across both
                                pages, instead of plain text here vs. an
                                avatar chip there. */}
                            {(p.pic || (p.personnel && p.personnel.length > 0)) && (
                                <div className="grid grid-cols-1 gap-3 border-t border-graphite-100 pt-3 sm:grid-cols-2 dark:border-slate-800">
                                    {p.pic && (
                                        <div>
                                            <span className="text-xs uppercase text-graphite-400">PIC / Supervisor</span>
                                            <div className="mt-1.5"><PersonChip name={p.pic.full_name} size="sm" /></div>
                                        </div>
                                    )}
                                    {p.personnel && p.personnel.length > 0 && (
                                        <div>
                                            <span className="text-xs uppercase text-graphite-400">Workforce ({p.personnel.length} orang)</span>
                                            <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1.5">
                                                {p.personnel.map((e) => <PersonChip key={e.id} name={e.full_name} size="sm" />)}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                            {(p.risk_assessment || p.jsa) && (
                                <div className="flex gap-4">
                                    {p.risk_assessment && <Link href={route('risk-assessments.show', p.risk_assessment.id)} className="text-brand-700 hover:underline">HIRADC: {p.risk_assessment.ra_number}</Link>}
                                    {p.jsa && <Link href={route('job-safety-analyses.show', p.jsa.id)} className="text-brand-700 hover:underline">JSA: {p.jsa.jsa_number}</Link>}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div className="flex items-center gap-2"><FlaskConical className="h-4 w-4 text-graphite-400" /><CardTitle>Gas Tests</CardTitle></div>
                            {canManage && <Button variant="outline" size="sm" onClick={() => setGasTestOpen((v) => !v)}>{gasTestOpen ? 'Cancel' : 'Add Reading'}</Button>}
                        </CardHeader>
                        <CardContent>
                            {gasTestOpen && (
                                <form onSubmit={submitGasTest} className="mb-4 grid grid-cols-1 gap-3 rounded-md border border-graphite-100 p-3 sm:grid-cols-2 lg:grid-cols-3">
                                    <div className="space-y-1"><Label className="text-xs">Location / Object</Label><Input placeholder="e.g. Tank TK-001" value={gasTestForm.data.location} onChange={(e) => gasTestForm.setData('location', e.target.value)} /></div>
                                    <div className="space-y-1">
                                        <Label className="text-xs">Test Stage</Label>
                                        <Select value={gasTestForm.data.stage} onValueChange={(v) => gasTestForm.setData('stage', v)}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent><SelectItem value="initial">Initial</SelectItem><SelectItem value="re_test">Re-Test</SelectItem><SelectItem value="final">Final</SelectItem></SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1"><Label className="text-xs">Tested At</Label><Input type="datetime-local" value={gasTestForm.data.tested_at} onChange={(e) => gasTestForm.setData('tested_at', e.target.value)} /></div>
                                    <div className="space-y-1"><Label className="text-xs">O2 %</Label><Input type="number" step="0.1" value={gasTestForm.data.o2_level} onChange={(e) => gasTestForm.setData('o2_level', e.target.value)} /></div>
                                    <div className="space-y-1"><Label className="text-xs">LEL %</Label><Input type="number" step="0.1" value={gasTestForm.data.lel_level} onChange={(e) => gasTestForm.setData('lel_level', e.target.value)} /></div>
                                    <div className="space-y-1"><Label className="text-xs">H2S ppm</Label><Input type="number" step="0.1" value={gasTestForm.data.h2s_level} onChange={(e) => gasTestForm.setData('h2s_level', e.target.value)} /></div>
                                    <div className="space-y-1"><Label className="text-xs">CO ppm</Label><Input type="number" step="0.1" value={gasTestForm.data.co_level} onChange={(e) => gasTestForm.setData('co_level', e.target.value)} /></div>
                                    <div className="space-y-1">
                                        <Label className="text-xs">Result</Label>
                                        <Select value={gasTestForm.data.result} onValueChange={(v) => gasTestForm.setData('result', v)}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent><SelectItem value="pass">Pass</SelectItem><SelectItem value="fail">Fail</SelectItem></SelectContent>
                                        </Select>
                                    </div>
                                    <div className="sm:col-span-2 lg:col-span-3"><Button type="submit" size="sm" disabled={gasTestForm.processing}>Save Reading</Button></div>
                                </form>
                            )}
                            {(!p.gas_tests || p.gas_tests.length === 0) ? (
                                <EmptyState icon={FlaskConical} title="No gas test readings recorded" />
                            ) : (
                                <Table>
                                    <TableHeader><TableRow><TableHead>Time</TableHead><TableHead>Location</TableHead><TableHead>Stage</TableHead><TableHead>O2</TableHead><TableHead>LEL</TableHead><TableHead>H2S</TableHead><TableHead>CO</TableHead><TableHead>Result</TableHead><TableHead>By</TableHead></TableRow></TableHeader>
                                    <TableBody>
                                        {p.gas_tests.map((g) => (
                                            <TableRow key={g.id}>
                                                <TableCell>{new Date(g.tested_at).toLocaleString('en-US', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}</TableCell>
                                                <TableCell>{g.location || '-'}</TableCell>
                                                <TableCell className="capitalize">{(g.stage || 'initial').replace('_', ' ')}</TableCell>
                                                <TableCell>{g.o2_level ?? '-'}</TableCell>
                                                <TableCell>{g.lel_level ?? '-'}</TableCell>
                                                <TableCell>{g.h2s_level ?? '-'}</TableCell>
                                                <TableCell>{g.co_level ?? '-'}</TableCell>
                                                <TableCell><StatusBadge value={g.result === 'fail' ? 'rejected' : 'approved'} label={g.result} /></TableCell>
                                                <TableCell>{g.tester?.name}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>

                    {p.loto_records?.length > 0 && (
                        <Card>
                            <CardHeader className="flex flex-row items-center gap-2"><Wind className="h-4 w-4 text-graphite-400" /><CardTitle>LOTO Records</CardTitle></CardHeader>
                            <CardContent>
                                <ul className="divide-y divide-graphite-100">
                                    {p.loto_records.map((l) => (
                                        <li key={l.id} className="flex items-center justify-between py-2 text-sm">
                                            <span>{l.loto_number} -- {l.equipment_name}</span>
                                            <StatusBadge value={l.status} />
                                        </li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    )}
                </div>
                <Card>
                    <CardHeader><CardTitle>Activity</CardTitle></CardHeader>
                    <CardContent><ActivityTimeline activities={activities} /></CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
