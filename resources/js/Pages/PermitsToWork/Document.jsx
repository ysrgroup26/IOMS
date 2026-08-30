import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import StatusBadge from '@/Components/shared/StatusBadge';
import { ArrowLeft, Printer, Download, FlaskConical } from 'lucide-react';

/**
 * v2.6.0 (PTW Document View pass). The actual product gap this pass
 * addresses: IOMS previously only ever showed a PTW as application data
 * in cards (`PermitsToWork/Show.jsx`) -- never as something that reads
 * like a real, printable HSE permit document. This page is a SEPARATE
 * read-oriented presentation, reached from Show's new "View Document"
 * action; Show itself is untouched in its role as the workflow/action
 * page (Approve/Reject/Cancel/Gas Test entry all still live there).
 *
 * Data comes from `PermitToWorkController::document()`, which loads the
 * exact same relations as `pdf()` -- one data shape, two renderers (this
 * page, and resources/views/pdf/permit-to-work.blade.php), so the
 * browser document and the downloaded PDF can never show different
 * information for the same permit.
 *
 * Print uses the browser's own print, isolated to just the paper via the
 * `@media print` rule below (a well-established, dependency-free CSS
 * technique -- no second rendering pipeline). Download PDF is untouched,
 * reusing the exact same PdfGeneratorService/DocumentEngine route this
 * codebase already had before this pass.
 */
export default function PermitToWorkDocument({ permit: p, company, documentTemplate, branding, rejectionReason }) {
    return (
        <AuthenticatedLayout>
            <Head title={`${p.ptw_number} -- Document`} />

            <style>{`
                @media print {
                    body * { visibility: hidden; }
                    #ptw-print-area, #ptw-print-area * { visibility: visible; }
                    #ptw-print-area { position: absolute; left: 0; top: 0; width: 100%; padding: 0; margin: 0; box-shadow: none !important; border: none !important; }
                    .no-print { display: none !important; }
                }
            `}</style>

            <div className="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
                <Link href={route('permits-to-work.show', p.id)} className="inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800 dark:text-slate-400 dark:hover:text-slate-200">
                    <ArrowLeft className="h-4 w-4" /> Back to PTW
                </Link>
                <div className="flex flex-wrap items-center gap-2">
                    <Button variant="outline" onClick={() => window.print()}><Printer className="h-4 w-4" /> Print Document</Button>
                    <Button variant="outline" asChild><a href={route('permits-to-work.pdf', p.id)} target="_blank" rel="noopener noreferrer"><Download className="h-4 w-4" /> Download PDF</a></Button>
                </div>
            </div>

            {/* The "paper" -- A4-proportioned on desktop, full-width and
                naturally stacking on mobile. Deliberately plain (white
                background, subtle border/shadow, no decorative UI) so it
                reads as a document, not another app screen. */}
            <div id="ptw-print-area" className="mx-auto max-w-[820px] rounded-lg border border-graphite-200 bg-white p-5 shadow-sm dark:border-slate-700 sm:p-8">
                {/* Company header */}
                <div className="flex flex-wrap items-start justify-between gap-4 border-b border-graphite-900 pb-4 dark:border-slate-700">
                    <div className="flex items-center gap-3">
                        {documentTemplate?.show_logo && branding?.logo_url && (
                            <img src={branding.logo_url} alt="Logo" className="h-12 w-auto max-w-[60px] object-contain" />
                        )}
                        <div>
                            <p className="text-base font-bold text-graphite-900">{branding?.company_name || company?.name || 'IOMS'}</p>
                            {branding?.address && <p className="text-xs text-graphite-500">{branding.address}</p>}
                        </div>
                    </div>
                    <div className="text-right">
                        <p className="text-sm font-bold uppercase tracking-wide text-graphite-900">Permit To Work</p>
                        <p className="text-xs text-graphite-500">Doc No: {p.ptw_number}</p>
                        <div className="mt-1"><StatusBadge value={p.status} /></div>
                    </div>
                </div>
                {documentTemplate?.header_text && (
                    <p className="mt-2 text-[10px] text-graphite-400">{documentTemplate.header_text}</p>
                )}

                {/* Document Information */}
                <Section title="Document Information">
                    <FieldGrid>
                        <Field label="Project / Site" value={p.project?.name} />
                        <Field label="Specific Location" value={p.location} />
                        <Field label="Permit Type" value={titleCase(p.permit_type)} />
                        <Field label="Valid From" value={formatDateTime(p.start_datetime)} />
                        <Field label="Valid Until" value={formatDateTime(p.end_datetime)} />
                        <Field label="Applicant" value={p.requester?.name} />
                    </FieldGrid>
                </Section>

                {/* Work Information */}
                <Section title="Work Information">
                    <p className="whitespace-pre-wrap text-sm text-graphite-800">{p.work_description}</p>
                </Section>

                {/* Hazards / Risk Controls -- only real, existing data;
                    no fabricated "Required PPE" section since the PTW
                    data model has no PPE field (per explicit instruction
                    not to invent fields for visual purposes). */}
                <Section title="Hazards / Risk Controls">
                    {p.precautions ? (
                        <p className="whitespace-pre-wrap text-sm text-graphite-800">{p.precautions}</p>
                    ) : (
                        <p className="text-sm italic text-graphite-400">Tidak ada catatan pengendalian risiko tambahan.</p>
                    )}
                    {(p.risk_assessment || p.jsa) && (
                        <div className="mt-3 flex flex-wrap gap-4 text-sm">
                            {p.risk_assessment && (
                                <span className="rounded border border-graphite-200 px-2 py-1 text-graphite-700 dark:border-slate-700 dark:text-slate-300">
                                    HIRADC: {p.risk_assessment.ra_number}
                                </span>
                            )}
                            {p.jsa && (
                                <span className="rounded border border-graphite-200 px-2 py-1 text-graphite-700 dark:border-slate-700 dark:text-slate-300">
                                    JSA: {p.jsa.jsa_number}
                                </span>
                            )}
                        </div>
                    )}
                    {p.required_qualification && (
                        <p className="mt-3 text-sm text-graphite-800"><span className="font-medium">Required Qualification:</span> {p.required_qualification}</p>
                    )}
                </Section>

                {/* Gas Test */}
                <Section title="Gas Test" icon={FlaskConical}>
                    {(!p.gas_tests || p.gas_tests.length === 0) ? (
                        <p className="text-sm italic text-graphite-400">Belum ada pembacaan uji gas yang tercatat.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[560px] border-collapse text-xs">
                                <thead>
                                    <tr className="border-b border-graphite-300 text-left uppercase text-graphite-500">
                                        <th className="py-1.5 pr-3">Time</th>
                                        <th className="py-1.5 pr-3">Location</th>
                                        <th className="py-1.5 pr-3">Stage</th>
                                        <th className="py-1.5 pr-3">O2%</th>
                                        <th className="py-1.5 pr-3">LEL%</th>
                                        <th className="py-1.5 pr-3">H2S</th>
                                        <th className="py-1.5 pr-3">CO</th>
                                        <th className="py-1.5 pr-3">Result</th>
                                        <th className="py-1.5">By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {p.gas_tests.map((g) => (
                                        <tr key={g.id} className="border-b border-graphite-100">
                                            <td className="py-1.5 pr-3">{formatDateTime(g.tested_at)}</td>
                                            <td className="py-1.5 pr-3">{g.location || '-'}</td>
                                            <td className="py-1.5 pr-3 capitalize">{(g.stage || 'initial').replace('_', ' ')}</td>
                                            <td className="py-1.5 pr-3">{g.o2_level ?? '-'}</td>
                                            <td className="py-1.5 pr-3">{g.lel_level ?? '-'}</td>
                                            <td className="py-1.5 pr-3">{g.h2s_level ?? '-'}</td>
                                            <td className="py-1.5 pr-3">{g.co_level ?? '-'}</td>
                                            <td className="py-1.5 pr-3 capitalize">{g.result}</td>
                                            <td className="py-1.5">{g.tester?.name || '-'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>

                {/* Authorization -- reflects the ACTUAL workflow state,
                    never a fabricated approval. */}
                <Section title="Authorization">
                    <FieldGrid>
                        <Field label="Requested By" value={p.requester?.name} />
                        <Field
                            label="HSE Approver"
                            value={
                                p.hse_approver?.name
                                    || (p.status === 'submitted' ? 'Menunggu Persetujuan' : (p.status === 'draft' ? '-' : '-'))
                            }
                        />
                        {p.area_authority && <Field label="Area Authority / PIC" value={p.area_authority.name} />}
                        {p.closer && <Field label="Closed By" value={p.closer.name} />}
                    </FieldGrid>
                    {p.status === 'rejected' && (
                        <p className="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/30 dark:text-red-400">
                            <span className="font-medium">Alasan Penolakan:</span> {rejectionReason || 'Tidak ada alasan tercatat.'}
                        </p>
                    )}
                </Section>

                {/* Signature area -- placeholders only where a real
                    person is actually associated with that role; never a
                    fake signature for a role no one has filled yet. */}
                <div className="mt-8 grid grid-cols-1 gap-8 border-t border-graphite-200 pt-6 dark:border-slate-700 sm:grid-cols-3">
                    <SignatureBlock role="Applicant" name={p.requester?.name} />
                    <SignatureBlock role="HSE Approver" name={p.hse_approver?.name} />
                    <SignatureBlock role="Area Authority / PIC" name={p.area_authority?.name} />
                </div>

                {/* Document footer */}
                <div className="mt-8 flex flex-wrap items-center justify-between gap-2 border-t border-graphite-100 pt-3 text-[10px] text-graphite-400 dark:border-slate-800">
                    <span>{p.ptw_number}</span>
                    <span>{documentTemplate?.footer_text || `Generated by ${branding?.company_name || 'IOMS'}`} &middot; {new Date().toLocaleString('en-US', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' })}</span>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Section({ title, icon: Icon, children }) {
    return (
        <div className="mt-5">
            <p className="mb-2 flex items-center gap-1.5 border-b border-graphite-200 pb-1 text-[11px] font-bold uppercase tracking-wide text-graphite-900 dark:border-slate-700 dark:text-slate-100">
                {Icon && <Icon className="h-3.5 w-3.5 text-graphite-400" />}
                {title}
            </p>
            {children}
        </div>
    );
}

function FieldGrid({ children }) {
    return <div className="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">{children}</div>;
}

function Field({ label, value }) {
    return (
        <div className="flex text-sm">
            <span className="w-36 shrink-0 text-graphite-500">{label}</span>
            <span className="text-graphite-400">:</span>
            <span className="ml-2 text-graphite-900 dark:text-slate-100">{value || '-'}</span>
        </div>
    );
}

function SignatureBlock({ role, name }) {
    return (
        <div className="text-center">
            <div className="h-12" />
            <p className="border-t border-graphite-400 pt-1.5 text-sm font-medium text-graphite-900 dark:border-slate-600 dark:text-slate-100">{name || ' '}</p>
            <p className="text-[10px] uppercase tracking-wide text-graphite-400">{role}</p>
        </div>
    );
}

function titleCase(s) {
    return (s || '').replace('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDateTime(value) {
    if (!value) return '-';
    return new Date(value).toLocaleString('en-US', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}
