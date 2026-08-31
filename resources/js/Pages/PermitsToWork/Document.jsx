import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import StatusBadge from '@/Components/shared/StatusBadge';
import PersonChip from '@/Components/shared/PersonChip';
import { ArrowLeft, Printer, Download, FlaskConical, Users, ShieldCheck, FileCheck2, Gavel } from 'lucide-react';

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
 * v2.20.0 (PTW Experience & Visual Polish pass, Parts 2-5). Root-cause
 * of the "looks like a rendered form, not a document" feeling this pass
 * set out to fix: the previous layout was one flat, undifferentiated
 * `Section` after another (colon-separated label:value pairs, no visual
 * weight difference between the document title and a field label).
 * Reworked around a numbered information architecture (01 Work
 * Information -> 05 Supporting Documents, matching this pass's own
 * directive) with a dominant document-title block, small uppercase
 * metadata labels with the value on its OWN line beneath (not
 * colon-inline), and real People data (PIC/Workforce) shown as
 * `PersonChip` avatars instead of a plain "- Name" text list. NOT
 * changed: what data exists, where it comes from, the workflow/PDF
 * pipeline, or any business logic -- this is presentation only, matched
 * line-for-line in `pdf/permit-to-work.blade.php`'s own equivalent pass
 * (see that file's own comments).
 *
 * Deliberately did NOT add a "document classification" header element
 * (suggested as an example by this pass's own directive) -- there is no
 * classification field anywhere in the PTW data model, and inventing one
 * for visual purposes would violate this codebase's own "never fabricate
 * data" rule that every other part of this document already follows.
 *
 * Print uses the browser's own print, isolated to just the paper via the
 * `@media print` rule below (a well-established, dependency-free CSS
 * technique -- no second rendering pipeline). Download PDF is untouched,
 * reusing the exact same PdfGeneratorService/DocumentEngine route this
 * codebase already had before this pass.
 */
export default function PermitToWorkDocument({ permit: p, company, documentTemplate, branding, rejectionReason }) {
    const hasSupportingDocs = p.risk_assessment || p.jsa;

    return (
        <AuthenticatedLayout>
            <Head title={`${p.ptw_number} -- Document`} />

            {/* v2.10.0 (PTW Document Polish pass, Phase 3D): the isolation
                rule (visibility: hidden on body *, visible on the print
                area) was already correct and is unchanged. Three real
                print-quality gaps found by this pass's own audit and
                fixed here:
                1. This app's dark mode is CLASS-based (tailwind.config.js
                   darkMode: ['class']), not a media query -- the `.dark`
                   class on <html> stays present during print, so every
                   `dark:` utility on this page (dark:bg-slate-900,
                   dark:text-slate-100, etc.) would otherwise still apply
                   to a printed page, risking white-on-white or
                   low-contrast output for anyone printing with dark mode
                   on. Forced back to plain black-on-white inside the
                   print area, unconditionally.
                2. No `@page` rule existed -- added A4 size + sane margins
                   instead of relying on the browser's own default print
                   margins/orientation.
                3. No `break-inside: avoid` anywhere -- a Section or the
                   signature block could previously split awkwardly
                   across a page boundary. */}
            <style>{`
                @media print {
                    @page { size: A4; margin: 14mm; }
                    body * { visibility: hidden; }
                    #ptw-print-area, #ptw-print-area * { visibility: visible; }
                    #ptw-print-area {
                        position: absolute; left: 0; top: 0; width: 100%;
                        padding: 0; margin: 0; box-shadow: none !important; border: none !important;
                        color: #000 !important; background: #fff !important;
                    }
                    #ptw-print-area * {
                        color: #000 !important; background: transparent !important; border-color: #999 !important;
                    }
                    #ptw-print-area [data-print-section] { break-inside: avoid; }
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
                naturally stacking on mobile. */}
            <div id="ptw-print-area" className="mx-auto max-w-[820px] rounded-xl border border-graphite-200 bg-white shadow-card dark:border-slate-700 sm:shadow-card-hover">
                <div className="p-5 sm:p-10">
                    {/* Company identity header */}
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex min-w-0 items-center gap-3">
                            {documentTemplate?.show_logo && branding?.logo_url && (
                                <img src={branding.logo_url} alt="Logo" className="h-12 w-auto max-w-[60px] shrink-0 object-contain" />
                            )}
                            <div className="min-w-0">
                                <p className="truncate text-lg font-bold text-graphite-900">{branding?.company_name || company?.name || 'IOMS'}</p>
                                {branding?.address && <p className="truncate text-xs text-graphite-500">{branding.address}</p>}
                            </div>
                        </div>
                        <div className="shrink-0 text-right">
                            <StatusBadge value={p.status} />
                        </div>
                    </div>
                    {documentTemplate?.header_text && (
                        <p className="mt-2 text-[10px] text-graphite-400">{documentTemplate.header_text}</p>
                    )}

                    {/* v2.20.0: the dominant document-title block --
                        previously the permit's own identity (what kind of
                        permit, what number) was visually equal to every
                        other field on the page. Now the single largest,
                        boldest element on the document, matching this
                        pass's own "make the document title visually
                        dominant" direction. */}
                    <div className="mt-6 border-b-2 border-graphite-900 pb-5 dark:border-slate-200">
                        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-graphite-400">Permit To Work</p>
                        <h1 className="mt-1 text-2xl font-bold uppercase tracking-tight text-graphite-900 sm:text-3xl">
                            {titleCase(p.permit_type)}
                        </h1>
                        <p className="mt-1.5 font-mono text-sm text-graphite-500">{p.ptw_number}</p>
                    </div>

                    {/* 01 -- Work Information */}
                    <DocSection index="01" title="Work Information">
                        <FieldGrid>
                            <Field label="Project / Site" value={p.project?.name} />
                            <Field label="Specific Location" value={p.location} />
                            <Field label="Valid From" value={formatDateTime(p.start_datetime)} />
                            <Field label="Valid Until" value={formatDateTime(p.end_datetime)} />
                        </FieldGrid>
                        <div className="mt-4">
                            <FieldLabel>Work Description</FieldLabel>
                            <p className="mt-1 whitespace-pre-wrap text-sm leading-relaxed text-graphite-800 dark:text-slate-200">{p.work_description}</p>
                        </div>
                    </DocSection>

                    {/* 02 -- Safety Controls */}
                    <DocSection index="02" title="Safety Controls" icon={ShieldCheck}>
                        {p.precautions ? (
                            <p className="whitespace-pre-wrap text-sm leading-relaxed text-graphite-800 dark:text-slate-200">{p.precautions}</p>
                        ) : (
                            <p className="text-sm italic text-graphite-400">Tidak ada catatan pengendalian risiko tambahan.</p>
                        )}
                        {p.required_qualification && (
                            <div className="mt-3">
                                <FieldLabel>Required Qualification</FieldLabel>
                                <p className="mt-1 text-sm text-graphite-800 dark:text-slate-200">{p.required_qualification}</p>
                            </div>
                        )}

                        {(p.gas_tests && p.gas_tests.length > 0) && (
                            <div className="mt-5 border-t border-graphite-100 pt-4 dark:border-slate-800">
                                <FieldLabel icon={FlaskConical}>Gas Test Readings</FieldLabel>
                                <div className="mt-2 overflow-x-auto rounded-lg border border-graphite-200 dark:border-slate-700">
                                    <table className="w-full min-w-[560px] border-collapse text-xs">
                                        <thead>
                                            <tr className="border-b border-graphite-200 bg-graphite-50 text-left uppercase tracking-wide text-graphite-500 dark:border-slate-700 dark:bg-slate-800/60">
                                                <th className="py-2 pl-3 pr-3">Time</th>
                                                <th className="py-2 pr-3">Location</th>
                                                <th className="py-2 pr-3">Stage</th>
                                                <th className="py-2 pr-3">O2%</th>
                                                <th className="py-2 pr-3">LEL%</th>
                                                <th className="py-2 pr-3">H2S</th>
                                                <th className="py-2 pr-3">CO</th>
                                                <th className="py-2 pr-3">Result</th>
                                                <th className="py-2 pr-3">By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {p.gas_tests.map((g) => (
                                                <tr key={g.id} className="border-b border-graphite-100 last:border-0 dark:border-slate-800">
                                                    <td className="py-2 pl-3 pr-3">{formatDateTime(g.tested_at)}</td>
                                                    <td className="py-2 pr-3">{g.location || '-'}</td>
                                                    <td className="py-2 pr-3 capitalize">{(g.stage || 'initial').replace('_', ' ')}</td>
                                                    <td className="py-2 pr-3">{g.o2_level ?? '-'}</td>
                                                    <td className="py-2 pr-3">{g.lel_level ?? '-'}</td>
                                                    <td className="py-2 pr-3">{g.h2s_level ?? '-'}</td>
                                                    <td className="py-2 pr-3">{g.co_level ?? '-'}</td>
                                                    <td className="py-2 pr-3 capitalize">{g.result}</td>
                                                    <td className="py-2 pr-3">{g.tester?.name || '-'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}
                    </DocSection>

                    {/* 03 -- Workforce (v2.17.0 data, v2.20.0 presentation).
                        Real data only -- an unset PIC/empty Workforce list
                        renders an honest empty state, never a fabricated
                        name or count. PersonChip is the same shared
                        component used anywhere else IOMS shows a real
                        Employee/User reference. */}
                    <DocSection index="03" title="Workforce" icon={Users}>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <FieldLabel>PIC / Supervisor Lapangan</FieldLabel>
                                <div className="mt-2">
                                    {p.pic ? (
                                        <PersonChip name={p.pic.full_name} />
                                    ) : (
                                        <p className="text-sm italic text-graphite-400">Belum ditentukan.</p>
                                    )}
                                </div>
                            </div>
                            <div>
                                <FieldLabel>Requester / Pemohon</FieldLabel>
                                <div className="mt-2">
                                    <PersonChip name={p.requester?.name} />
                                </div>
                            </div>
                        </div>

                        <div className="mt-5 border-t border-graphite-100 pt-4 dark:border-slate-800">
                            <div className="flex items-center justify-between">
                                <FieldLabel>Personnel Involved</FieldLabel>
                                <span className="text-xs font-medium text-graphite-500 dark:text-slate-400">
                                    Total: {p.personnel?.length || 0} orang
                                </span>
                            </div>
                            {p.personnel && p.personnel.length > 0 ? (
                                <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    {p.personnel.map((person) => <PersonChip key={person.id} name={person.full_name} size="sm" />)}
                                </div>
                            ) : (
                                <p className="mt-2 text-sm italic text-graphite-400">Belum ada personel yang dicatat.</p>
                            )}
                        </div>
                    </DocSection>

                    {/* 04 -- Authorization -- reflects the ACTUAL workflow
                        state, never a fabricated approval. */}
                    <DocSection index="04" title="Authorization" icon={Gavel}>
                        <FieldGrid>
                            <Field label="Requester" value={p.requester?.name} />
                            <Field
                                label="HSE Approver"
                                value={p.hse_approver?.name || (p.status === 'submitted' ? 'Menunggu Persetujuan' : '-')}
                            />
                            {p.area_authority && <Field label="Area Authority" value={p.area_authority.name} />}
                            {p.closer && <Field label="Closed By" value={p.closer.name} />}
                        </FieldGrid>
                        {p.status === 'rejected' && (
                            <p className="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-400">
                                <span className="font-medium">Alasan Penolakan:</span> {rejectionReason || 'Tidak ada alasan tercatat.'}
                            </p>
                        )}
                    </DocSection>

                    {/* 05 -- Supporting Documents -- only rendered when at
                        least one linked document actually exists. */}
                    {hasSupportingDocs && (
                        <DocSection index="05" title="Supporting Documents" icon={FileCheck2}>
                            <div className="space-y-2">
                                {p.risk_assessment && (
                                    <div className="flex items-center justify-between gap-3 rounded-lg border border-graphite-200 px-3 py-2.5 dark:border-slate-700">
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium text-graphite-800 dark:text-slate-200">{p.risk_assessment.title}</p>
                                            <p className="text-xs text-graphite-500 dark:text-slate-400">HIRADC &middot; {p.risk_assessment.ra_number}</p>
                                        </div>
                                    </div>
                                )}
                                {p.jsa && (
                                    <div className="flex items-center justify-between gap-3 rounded-lg border border-graphite-200 px-3 py-2.5 dark:border-slate-700">
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium text-graphite-800 dark:text-slate-200">{p.jsa.job_title}</p>
                                            <p className="text-xs text-graphite-500 dark:text-slate-400">JSA &middot; {p.jsa.jsa_number}</p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </DocSection>
                    )}

                    {/* Signature area -- placeholders only where a real
                        person is actually associated with that role;
                        never a fake signature for a role no one has
                        filled yet. "Area Authority" will currently ALWAYS
                        render blank: `area_authority_id` has no writer
                        anywhere in this codebase -- an honest blank block
                        for an unwired field, not a bug to fix here. */}
                    <div data-print-section className="mt-8 grid grid-cols-1 gap-8 border-t border-graphite-200 pt-6 dark:border-slate-700 sm:grid-cols-3">
                        <SignatureBlock role="Applicant" name={p.requester?.name} />
                        <SignatureBlock role="HSE Approver" name={p.hse_approver?.name} />
                        <SignatureBlock role="Area Authority" name={p.area_authority?.name} />
                    </div>
                </div>

                {/* Document footer */}
                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-graphite-100 px-5 py-3 text-[10px] text-graphite-400 dark:border-slate-800 sm:px-10">
                    <span className="font-mono">{p.ptw_number}</span>
                    <span>{documentTemplate?.footer_text || `Generated by ${branding?.company_name || 'IOMS'}`} &middot; {new Date().toLocaleString('en-US', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' })}</span>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

/**
 * v2.20.0. Replaces the old flat `Section` -- adds a two-digit index
 * (matching this pass's own "01 Work Information" IA), a subtle tinted
 * header strip instead of a bare bottom border (the "excessive borders /
 * visually flat sections" the audit flagged), and an optional icon.
 * Still one component, still print-safe (`data-print-section` +
 * `break-inside: avoid` unchanged).
 */
function DocSection({ index, title, icon: Icon, children }) {
    return (
        <div data-print-section className="mt-6 first:mt-0">
            <div className="flex items-center gap-2 rounded-t-lg border border-graphite-200 bg-graphite-50 px-3 py-2 dark:border-slate-700 dark:bg-slate-800/60">
                <span className="text-[11px] font-bold tabular-nums text-graphite-400">{index}</span>
                {Icon && <Icon className="h-3.5 w-3.5 text-graphite-400" />}
                <p className="text-[11px] font-bold uppercase tracking-wide text-graphite-800 dark:text-slate-200">{title}</p>
            </div>
            <div className="rounded-b-lg border border-t-0 border-graphite-200 px-4 py-4 dark:border-slate-700">
                {children}
            </div>
        </div>
    );
}

function FieldGrid({ children }) {
    return <div className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">{children}</div>;
}

function FieldLabel({ icon: Icon, children }) {
    return (
        <p className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-graphite-400 dark:text-slate-500">
            {Icon && <Icon className="h-3 w-3" />}
            {children}
        </p>
    );
}

/**
 * v2.20.0: label now sits ABOVE the value on its own line (small
 * uppercase metadata label, clear value below) instead of the previous
 * `Label : Value` inline-colon layout -- easier to scan, matches this
 * pass's own "small uppercase metadata labels, clear values" direction.
 */
function Field({ label, value }) {
    return (
        <div>
            <FieldLabel>{label}</FieldLabel>
            <p className="mt-0.5 text-sm text-graphite-900 dark:text-slate-100">{value || '-'}</p>
        </div>
    );
}

function SignatureBlock({ role, name }) {
    return (
        <div className="text-center">
            <div className="h-12" />
            <p className="border-t border-graphite-400 pt-1.5 text-sm font-medium text-graphite-900 dark:border-slate-600 dark:text-slate-100">{name || ' '}</p>
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
