import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Download, Upload, Loader2, CheckCircle2, AlertTriangle, Sparkles } from 'lucide-react';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]').content;
}

/**
 * Employee Import (v1.6.8, Smart Master Data Detection added v1.6.10).
 * Still deliberately a single dialog with a linear flow, not a
 * multi-step wizard component -- Download Template -> Upload -> Preview
 * -> Processing -> Summary is just conditional rendering inside one
 * dialog based on a `stage` string, per the original "no unnecessary
 * wizard" instruction. Preview is a real new stage, not a rename of an
 * existing one -- it's where Smart Master Data Detection surfaces
 * before anything is written to the database.
 */
/*
 * v2.108.1 -- [field => the heading in THEIR file], flattened onto the
 * request exactly as the server validates it. Only entries the inspector
 * actually matched are sent; anything it could not place is left out so
 * the configured mapping still answers for that field.
 */
function appendColumnMapping(formData, suggested) {
    if (!suggested?.mapping) return;

    Object.entries(suggested.mapping).forEach(([field, match]) => {
        if (match?.heading) {
            formData.append(`column_mapping[${field}]`, match.heading);
        }
    });
}

export default function EmployeeImportDialog({ open, onOpenChange, companies }) {
    // v2.105.0: the viewer's own operating unit, when they can see it.
    const ownCompanyId = usePage().props.auth?.user?.company_id;
    const defaultCompanyId = companies.find((c) => String(c.id) === String(ownCompanyId))
        ? String(ownCompanyId)
        : (companies.length === 1 ? String(companies[0].id) : undefined);
    const [stage, setStage] = useState('upload'); // upload | previewing | preview | processing | summary
    const [companyId, setCompanyId] = useState(defaultCompanyId);
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState(null);
    // v2.102.0: which of THEIR columns feeds which IOMS field, suggested
    // by the server and shown before anything is written.
    const [mapping, setMapping] = useState(null);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);

    function reset() {
        setStage('upload');
        setFile(null);
        setPreview(null);
        setMapping(null);
        setResult(null);
        setError(null);
    }

    function close() {
        onOpenChange(false);
        setTimeout(reset, 200);
        if (result?.imported > 0) {
            window.location.reload();
        }
    }

    async function runPreview() {
        /*
         * v2.105.0: this used to return silently, so an administrator with
         * more than one operating unit and nothing preselected saw a dialog
         * where choosing a file simply did nothing. Saying which piece is
         * missing costs one line and removes an entire class of "it does
         * not detect anything" reports.
         */
        if (!companyId) {
            setError('Pilih Operating Unit terlebih dahulu sebelum menjalankan preview.');
            return;
        }

        if (!file) {
            setError('Pilih berkas yang akan diimpor.');
            return;
        }

        setStage('previewing');
        setError(null);

        /*
         * v2.108.1 -- INSPECT BEFORE PREVIEW, AND SEND WHAT IT FOUND.
         *
         * These used to run the other way round: the file was previewed
         * first and the column mapping was fetched afterwards, purely to
         * draw a panel. So the panel said "IOMS matched your columns" and
         * then BOTH the preview and the import read the file through the
         * tenant's configured mapping instead -- for a customer's own
         * Indonesian column names, that imported nothing at all while the
         * screen showed a confident match.
         *
         * The mapping is now resolved first and travels with every request
         * that reads the file, so the preview is a promise the import
         * keeps. A failure to inspect is still not fatal: the mapping is
         * simply absent and the configured one applies, which is exactly
         * the official template's path.
         */
        let suggested = null;

        try {
            const inspectBody = new FormData();
            inspectBody.append('file', file);

            const inspected = await fetch(route('employees.import.inspect'), {
                method: 'POST',
                body: inspectBody,
                headers: { 'X-CSRF-TOKEN': csrfToken() },
            });

            suggested = inspected.ok ? await inspected.json() : null;
        } catch {
            suggested = null;
        }

        setMapping(suggested);

        const formData = new FormData();
        formData.append('file', file);
        formData.append('company_id', companyId);
        appendColumnMapping(formData, suggested);

        try {
            const response = await fetch(route('employees.import.preview'), {
                method: 'POST',
                body: formData,
                headers: { 'X-CSRF-TOKEN': csrfToken() },
            });

            if (!response.ok) {
                const body = await response.json().catch(() => null);
                throw new Error(body?.message || 'Could not read this file. Please check the format and try again.');
            }

            setPreview(await response.json());
            setStage('preview');
        } catch (e) {
            setError(e.message);
            setStage('upload');
        }
    }

    async function confirmImport(createMissing) {
        setStage('processing');
        setError(null);

        const formData = new FormData();
        formData.append('file', file);
        formData.append('company_id', companyId);
        // The same mapping the preview ran on and the panel displayed --
        // the import must not read the file a different way.
        appendColumnMapping(formData, mapping);

        if (createMissing) {
            preview.departments.new.forEach((name) => formData.append('new_departments[]', name));
            preview.positions.new.forEach((name) => formData.append('new_positions[]', name));
        }

        try {
            const response = await fetch(route('employees.import.create-missing-and-import'), {
                method: 'POST',
                body: formData,
                headers: { 'X-CSRF-TOKEN': csrfToken() },
            });

            if (!response.ok) {
                const body = await response.json().catch(() => null);
                throw new Error(body?.message || 'Import failed. Please check the file and try again.');
            }

            setResult(await response.json());
            setStage('summary');
        } catch (e) {
            setError(e.message);
            setStage('preview');
        }
    }

    return (
        <Dialog open={open} onOpenChange={(v) => (v ? onOpenChange(true) : close())}>
            <DialogContent className={stage === 'preview' ? 'max-w-lg' : undefined}>
                <DialogHeader><DialogTitle>Import Employees</DialogTitle></DialogHeader>

                {stage === 'upload' && (
                    <div className="space-y-4">
                        <div className="rounded-lg border border-graphite-100 bg-graphite-50/60 p-3">
                            <p className="mb-2 text-[13px] text-graphite-600">
                                Unduh templat, isi data karyawan, lalu unggah di bawah. Berkas Anda sendiri
                                juga dapat diunggah langsung tanpa templat.
                            </p>
                            <Button variant="outline" size="sm" asChild>
                                <a href={route('employees.import-template')}><Download className="h-3.5 w-3.5" /> Download Template</a>
                            </Button>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Operating Unit</Label>
                            <Select value={companyId} onValueChange={setCompanyId}>
                                <SelectTrigger><SelectValue placeholder="Select operating unit" /></SelectTrigger>
                                <SelectContent>
                                    {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Source File</Label>
                            <label className="flex h-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-graphite-300 text-graphite-400 hover:border-brand-400 hover:text-brand-600">
                                <Upload className="h-5 w-5" />
                                <span className="text-xs">{file ? file.name : 'Click to choose a file'}</span>
                                {/*
                                    v2.111.0 -- the picker now offers what the
                                    SERVER accepts. It offered .xlsx and .xls
                                    only, so the CSV, Word and PDF the import
                                    endpoints had already been reading since
                                    v2.110.0 were unreachable from the one
                                    screen a customer actually uses. A
                                    capability no file picker will open is not
                                    a capability.
                                */}
                                <input
                                    type="file"
                                    accept=".xlsx,.xls,.csv,.docx,.pdf"
                                    className="hidden"
                                    onChange={(e) => setFile(e.target.files[0])}
                                />
                            </label>
                            <p className="text-xs text-graphite-500">
                                Menerima Excel (.xlsx, .xls), CSV, Word (.docx) dan PDF. Baris judul kolom
                                tidak harus berada di baris pertama.
                            </p>
                        </div>

                        {error && <p className="text-xs text-red-600">{error}</p>}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={close}>Cancel</Button>
                            <Button type="button" onClick={runPreview} disabled={!file || !companyId}>Preview Import</Button>
                        </DialogFooter>
                    </div>
                )}

                {stage === 'previewing' && (
                    <div className="flex flex-col items-center justify-center gap-3 py-10">
                        <Loader2 className="h-8 w-8 animate-spin text-brand-500" />
                        <p className="text-sm text-graphite-500">Scanning your file...</p>
                    </div>
                )}

                {stage === 'preview' && preview && (
                    <div className="space-y-4">
                        <p className="text-xs uppercase tracking-wide text-graphite-400">Employee Import Preview</p>

                        {/*
                            v2.111.0 -- WHAT IOMS ACTUALLY READ.
                            The reader detects the format by content, picks a
                            worksheet or table, and LOCATES the heading row
                            rather than assuming row 1. All of that was
                            invisible, so a customer could only find out which
                            sheet had been read by comparing the row count
                            afterwards. A table recovered from a PDF is a
                            reconstruction, so its confidence is stated too --
                            never presented as a certainty.
                        */}
                        {mapping?.format_label && (
                            <div className="rounded-lg border border-graphite-100 bg-graphite-50/60 p-3">
                                <p className="mb-1.5 text-xs font-semibold text-graphite-600">Detected Source</p>
                                <p className="text-xs text-graphite-500">
                                    <span className="text-graphite-700">{mapping.format_label}</span>
                                    {mapping.source && <> &middot; {mapping.source}</>}
                                    {mapping.header_row > 1 && <> &middot; judul kolom di baris {mapping.header_row}</>}
                                    {mapping.confidence < 1 && <> &middot; keyakinan {Math.round(mapping.confidence * 100)}%</>}
                                </p>

                                {mapping.tables?.length > 1 && (
                                    <p className="mt-1 text-xs text-graphite-500">
                                        Berkas memuat {mapping.tables.length} tabel; yang dibaca adalah{' '}
                                        <span className="text-graphite-700">{mapping.source}</span>.
                                    </p>
                                )}

                                {mapping.warnings?.map((w) => (
                                    <p key={w} className="mt-1 text-xs text-amber-700">{w}</p>
                                ))}

                                {mapping.notes?.map((n) => (
                                    <p key={n} className="mt-1 text-xs text-graphite-500">{n}</p>
                                ))}
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-3">
                            <PreviewStat label="Total Rows" value={preview.total_rows} />
                            <PreviewStat label="Valid Employees" value={preview.valid_rows} />
                            <PreviewStat label="Departments" value={`${preview.departments.existing.length} Existing / ${preview.departments.new.length} New`} small />
                            <PreviewStat label="Positions" value={`${preview.positions.existing.length} Existing / ${preview.positions.new.length} New`} small />
                            <PreviewStat label="Invalid Rows" value={preview.invalid_rows} accent={preview.invalid_rows > 0 ? 'amber' : null} />
                        </div>

                        {preview.skipped?.length > 0 && (
                            <div>
                                <p className="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-graphite-600">
                                    <AlertTriangle className="h-3.5 w-3.5 text-amber-500" /> Rows That Will Be Skipped
                                </p>
                                <p className="mb-2 text-xs text-graphite-500">
                                    Baris berikut tidak akan diimpor. Perbaiki pada berkas Anda lalu unggah ulang,
                                    atau lanjutkan tanpa baris tersebut.
                                </p>
                                <div className="max-h-40 overflow-y-auto rounded-lg border border-graphite-100">
                                    {preview.skipped.map((s, i) => (
                                        <div key={i} className="flex justify-between gap-3 border-b border-graphite-100 px-3 py-1.5 text-xs last:border-0">
                                            <span className="shrink-0 text-graphite-500">Row {s.row}</span>
                                            <span className="text-right text-graphite-700">{s.reason}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {preview.warnings?.length > 0 && (
                            <RowNotes
                                title="Dates That Could Not Be Read"
                                hint="Baris ini tetap diimpor, tetapi tanggal masuknya dikosongkan karena formatnya tidak dapat dibaca. Gunakan format 15/01/2026 atau 2026-01-15."
                                rows={preview.warnings}
                            />
                        )}

                        {mapping && (mapping.unmatched_headings.length > 0 || mapping.missing_fields.length > 0) && (
                            <div className="rounded-lg border border-graphite-100 bg-graphite-50/60 p-3">
                                <p className="mb-1.5 text-xs font-semibold text-graphite-600">Column Mapping</p>
                                <p className="mb-2 text-xs text-graphite-500">
                                    IOMS membaca berkas Anda memakai pencocokan kolom berikut.
                                    Periksa sebelum melanjutkan; kolom yang tidak cocok tidak akan diimpor.
                                </p>

                                {Object.entries(mapping.mapping).map(([field, match]) => (
                                    <p key={field} className="text-xs text-graphite-500">
                                        <span className="font-medium text-graphite-700">{match.heading}</span>
                                        {' '}&rarr;{' '}
                                        {mapping.field_labels[field] ?? field}
                                        {match.matched_by !== 'exact' && (
                                            <span className="text-graphite-400"> ({match.matched_by})</span>
                                        )}
                                    </p>
                                ))}

                                {mapping.unmatched_headings.length > 0 && (
                                    <p className="mt-2 text-xs text-graphite-500">
                                        Kolom yang tidak dikenali dan akan diabaikan:{' '}
                                        <span className="text-graphite-700">{mapping.unmatched_headings.join(', ')}</span>
                                    </p>
                                )}

                                {mapping.missing_fields.length > 0 && (
                                    <p className="mt-1 text-xs text-graphite-500">
                                        Kolom sistem yang tidak terisi dari berkas ini:{' '}
                                        <span className="text-graphite-700">
                                            {mapping.missing_fields.map((f) => mapping.field_labels[f] ?? f).join(', ')}
                                        </span>
                                    </p>
                                )}
                            </div>
                        )}

                        {(preview.departments.new.length > 0 || preview.positions.new.length > 0) && (
                            <div className="rounded-lg border border-amber-200 bg-amber-50/60 p-3">
                                <p className="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-amber-700">
                                    <Sparkles className="h-3.5 w-3.5" /> New Master Data Detected
                                </p>
                                {preview.departments.new.length > 0 && (
                                    <p className="text-xs text-graphite-600">Departments: {preview.departments.new.join(', ')}</p>
                                )}
                                {preview.positions.new.length > 0 && (
                                    <p className="text-xs text-graphite-600">Positions: {preview.positions.new.join(', ')}</p>
                                )}
                            </div>
                        )}

                        {(Object.keys(preview.departments.suggestions).length > 0 || Object.keys(preview.positions.suggestions).length > 0) && (
                            <div className="rounded-lg border border-graphite-100 bg-graphite-50/60 p-3">
                                <p className="mb-1.5 text-xs font-semibold text-graphite-600">Possible Typos (not auto-created)</p>
                                {Object.entries({ ...preview.departments.suggestions, ...preview.positions.suggestions }).map(([typo, match]) => (
                                    <p key={typo} className="text-xs text-graphite-500">
                                        "<span className="text-graphite-700">{typo}</span>" -- Did you mean <span className="font-medium text-graphite-700">"{match}"</span>?
                                    </p>
                                ))}
                            </div>
                        )}

                        {error && <p className="text-xs text-red-600">{error}</p>}

                        <DialogFooter className="flex-wrap gap-2">
                            <Button type="button" variant="outline" onClick={close}>Cancel</Button>
                            <Button type="button" variant="outline" onClick={() => confirmImport(false)}>Import Valid Rows</Button>
                            {preview.has_missing_master_data && (
                                <Button type="button" onClick={() => confirmImport(true)}>Create Missing Master Data &amp; Import</Button>
                            )}
                        </DialogFooter>
                    </div>
                )}

                {stage === 'processing' && (
                    <div className="flex flex-col items-center justify-center gap-3 py-10">
                        <Loader2 className="h-8 w-8 animate-spin text-brand-500" />
                        <p className="text-sm text-graphite-500">Processing your file... this may take a moment for large files.</p>
                    </div>
                )}

                {stage === 'summary' && result && (
                    <div className="space-y-4">
                        <div className="flex items-center gap-3 rounded-lg border border-graphite-100 bg-graphite-50/60 p-3">
                            <CheckCircle2 className="h-8 w-8 shrink-0 text-emerald-500" />
                            <div>
                                <p className="text-sm font-semibold text-graphite-800">{result.total_rows} Rows Found</p>
                                <p className="text-xs text-graphite-500">
                                    {result.imported} imported successfully
                                    {result.needs_completion > 0 && ` (${result.needs_completion} need profile completion)`}
                                    {result.skipped.length > 0 && ` · ${result.skipped.length} skipped`}
                                </p>
                            </div>
                        </div>

                        {result.skipped.length > 0 && (
                            <div>
                                <p className="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-graphite-600">
                                    <AlertTriangle className="h-3.5 w-3.5 text-amber-500" /> Skipped Rows
                                </p>
                                <div className="max-h-40 overflow-y-auto rounded-lg border border-graphite-100">
                                    {result.skipped.map((s, i) => (
                                        <div key={i} className="flex justify-between border-b border-graphite-100 px-3 py-1.5 text-xs last:border-0">
                                            <span className="text-graphite-500">Row {s.row}</span>
                                            <span className="text-graphite-700">{s.reason}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {result.warnings?.length > 0 && (
                            <RowNotes
                                title="Dates That Could Not Be Read"
                                hint="Baris ini berhasil diimpor tanpa tanggal masuk. Lengkapi pada profil karyawan, atau perbaiki berkas lalu impor ulang."
                                rows={result.warnings}
                            />
                        )}

                        <DialogFooter>
                            <Button type="button" onClick={close}>Done</Button>
                        </DialogFooter>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

/*
 * v2.108.0 -- a row-numbered note list. The import already had two
 * near-identical copies of this markup for skipped rows (preview and
 * summary); warnings reuse this one rather than adding a third and a
 * fourth. A warning is not a refusal, so it is styled as information
 * rather than as an error.
 */
function RowNotes({ title, hint, rows }) {
    return (
        <div>
            <p className="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-graphite-600">
                <AlertTriangle className="h-3.5 w-3.5 text-amber-500" /> {title}
            </p>
            <p className="mb-2 text-xs text-graphite-500">{hint}</p>
            <div className="max-h-40 overflow-y-auto rounded-lg border border-graphite-100">
                {rows.map((r, i) => (
                    <div key={i} className="flex justify-between gap-3 border-b border-graphite-100 px-3 py-1.5 text-xs last:border-0">
                        <span className="shrink-0 text-graphite-500">Row {r.row}</span>
                        <span className="text-right text-graphite-700">{r.reason}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function PreviewStat({ label, value, small, accent }) {
    return (
        <div className="rounded-lg border border-graphite-100 p-2.5">
            <p className="text-[11px] uppercase tracking-wide text-graphite-400">{label}</p>
            <p className={`font-bold text-graphite-800 ${small ? 'text-[13px]' : 'text-lg'} ${accent === 'amber' ? 'text-amber-600' : ''}`}>{value}</p>
        </div>
    );
}
