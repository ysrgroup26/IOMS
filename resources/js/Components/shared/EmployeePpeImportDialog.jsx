import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Download, Upload, Loader2, CheckCircle2, AlertTriangle, ShieldCheck, ArrowLeft } from 'lucide-react';
import { appendConfirmedMapping } from '@/Components/shared/ImportMappingPanel';
import PpeImportScreening from '@/Components/shared/PpeImportScreening';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]').content;
}

/**
 * Bulk PPE issuance (v2.120.0).
 *
 * Deliberately the same linear flow as `EmployeeImportDialog` -- Download
 * Template, Upload, Preview, Processing, Summary as one `stage` string --
 * because it is the flow customers have already been taught here. It is a
 * separate component rather than a prop on that one: the two differ in
 * what the preview MEANS (there, master data to create; here, rows that
 * will be refused and why), and a single component carrying both would be
 * a switch statement pretending to be reuse.
 *
 * What this import never does, and says so on screen: create an employee,
 * or create a PPE type.
 */
/*
 * v2.125.0 -- the silent `appendColumnMapping()` that lived here is gone.
 *
 * It posted the matcher's suggestion straight through, so the customer
 * never saw which of their columns IOMS had decided was the Employee ID
 * and could not say otherwise. The mapping is now confirmed on its own
 * stage (`ImportMappingPanel`) and `appendConfirmedMapping()` sends what
 * the customer actually agreed to -- plus the sheet they chose, which this
 * flow could not express at all.
 */

export default function EmployeePpeImportDialog({ open, onOpenChange, companies = [] }) {
    const ownCompanyId = usePage().props.auth?.user?.company_id;
    const defaultCompanyId = companies.find((c) => String(c.id) === String(ownCompanyId))
        ? String(ownCompanyId)
        : (companies.length === 1 ? String(companies[0].id) : undefined);

    /*
     * v2.126.0 -- SEVEN STAGES, because the owner asked for a screening and
     * not a confirmation: upload, read the structure, correct the mapping,
     * check the identities and the dates, review the totals, confirm, and
     * only then a result.
     *
     * `screening` is the SERVER'S whole reading. Every correction is posted
     * back and re-screened rather than applied in the browser, so what the
     * person is looking at is always what the server would actually do --
     * which is the only thing that makes a preview a promise rather than an
     * illustration.
     */
    const [stage, setStage] = useState('upload'); // upload | screening | screen | previewing | preview | processing | summary
    const [companyId, setCompanyId] = useState(defaultCompanyId);
    const [file, setFile] = useState(null);
    const [mapping, setMapping] = useState(null);
    const [screening, setScreening] = useState(null);
    const [rejectedRows, setRejectedRows] = useState([]);
    // The person's own column corrections, keyed by position. Posted back
    // on every re-screen so the server applies them, never the browser.
    const [columnOverrides, setColumnOverrides] = useState({});
    const [busy, setBusy] = useState(false);
    // What the customer confirmed on the mapping stage, which is what
    // every later request is built from.
    const [overrides, setOverrides] = useState({});
    const [sheet, setSheet] = useState(null);
    const [headerRow, setHeaderRow] = useState(null);
    const [preview, setPreview] = useState(null);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);

    function reset() {
        setStage('upload');
        setFile(null);
        setMapping(null);
        setScreening(null);
        setRejectedRows([]);
        setColumnOverrides({});
        setBusy(false);
        setOverrides({});
        setSheet(null);
        setHeaderRow(null);
        setPreview(null);
        setResult(null);
        setError(null);
    }

    function close() {
        onOpenChange(false);
        // Anything issued must be visible behind the dialog, not after a
        // manual refresh.
        if (result?.imported > 0) router.reload({ only: ['employees'] });
        reset();
    }

    /*
     * Read the file's shape and SHOW it, before anything is read as data.
     *
     * A failure to inspect is still not fatal -- the import can run on the
     * configured mapping exactly as it did before this stage existed --
     * but it is no longer invisible: the customer is told the columns
     * could not be read and goes straight to the preview, which is the
     * step that refuses rows one at a time and says why.
     */
    /**
     * The whole reading, from the server, for the person to check.
     *
     * Posts the current corrections back with every call, so a changed
     * sheet, header row or column mapping is RE-READ rather than patched in
     * the browser. Slower by one request and correct by construction: the
     * person never looks at an interpretation the server would not perform.
     */
    async function runScreen(next = {}) {
        setStage(screening ? 'screen' : 'screening');
        setBusy(true);
        setError(null);

        const nextSheet = next.sheet !== undefined ? next.sheet : sheet;
        const nextHeaderRow = next.headerRow !== undefined ? next.headerRow : headerRow;
        const nextColumns = next.columns !== undefined ? next.columns : columnOverrides;

        const body = new FormData();
        body.append('file', file);
        body.append('company_id', companyId);
        if (nextSheet) body.append('sheet', nextSheet);
        if (nextHeaderRow) body.append('header_row', String(nextHeaderRow));
        Object.entries(nextColumns).forEach(([index, patch]) => {
            if (patch.role !== undefined) body.append(`columns[${index}][role]`, patch.role);
            if (patch.ppe_type_id !== undefined && patch.ppe_type_id !== null) {
                body.append(`columns[${index}][ppe_type_id]`, String(patch.ppe_type_id));
            }
        });

        try {
            const response = await fetch(route('ppe.import.screen'), {
                method: 'POST',
                body,
                headers: { 'X-CSRF-TOKEN': csrfToken() },
            });

            if (!response.ok) {
                const payload = await response.json().catch(() => null);
                throw new Error(payload?.message
                    || payload?.errors?.sheet?.[0]
                    || payload?.errors?.file?.[0]
                    || 'Berkas ini tidak dapat dibaca. Periksa formatnya lalu coba lagi.');
            }

            const read = await response.json();
            setScreening(read);
            setSheet(read.sheet ?? null);
            setHeaderRow(read.header_row ?? null);
            setStage('screen');
        } catch (e) {
            setError(e.message);
            setStage(screening ? 'screen' : 'upload');
        } finally {
            setBusy(false);
        }
    }

    /**
     * The identity mapping the EXISTING importer needs, derived from the
     * screening rather than asked for twice.
     *
     * `column_mapping` is keyed by IOMS field and valued with the heading
     * in the customer's file -- see FieldMappingService::importColumnKeys().
     * The screening already decided which column is the Employee ID and
     * which the name, so asking the person again would be asking them to
     * repeat themselves.
     */
    function identityMappingFromScreening() {
        const out = {};

        (screening?.columns ?? []).forEach((c) => {
            if ((c.role === 'employee_id' || c.role === 'employee_name') && c.heading) {
                out[c.role] = c.heading;
            }
        });

        return out;
    }

    /** The plan, as the importer expects it: position, role and target. */
    function planFromScreening() {
        return (screening?.columns ?? [])
            .filter((c) => c.role === 'ppe_date' || c.role === 'ppe_size')
            .map((c) => ({ index: c.index, role: c.role, ppe_type_id: c.ppe_type_id, heading: c.heading }));
    }

    /**
     * The plan and the rejections, on every request that reads the file.
     *
     * One function, called by both the preview and the import, because the
     * two reading the file differently is the exact defect v2.108.1 fixed
     * for the mapping -- and a screening the import then ignores would be
     * that defect wearing a bigger interface.
     */
    function appendScreeningPlan(formData) {
        if (!screening) return;

        if (headerRow) formData.append('header_row', String(headerRow));

        planFromScreening().forEach((c, i) => {
            formData.append(`plan[${i}][index]`, String(c.index));
            formData.append(`plan[${i}][role]`, c.role);
            formData.append(`plan[${i}][heading]`, c.heading ?? '');
            if (c.ppe_type_id) formData.append(`plan[${i}][ppe_type_id]`, String(c.ppe_type_id));
        });

        rejectedRows.forEach((row, i) => formData.append(`rejected_rows[${i}]`, String(row)));

        /*
         * The rows the screening read as NOT being people -- titles,
         * department separators, subtotals, blanks.
         *
         * Sent because the importer has no way of knowing: without them it
         * re-read a department heading as an Employee ID and the summary
         * said "Employee ID berikut tidak ditemukan: FABRIKASI, PRODUKSI,
         * Total". The screening already made that judgement and showed it;
         * this is the import honouring it rather than making a second,
         * worse one.
         */
        (screening.rows ?? [])
            .filter((r) => r.kind !== 'data')
            .forEach((r, i) => formData.append(`ignored_rows[${i}]`, String(r.source_row)));

        Object.entries(identityMappingFromScreening()).forEach(([field, heading]) => {
            formData.append(`column_mapping[${field}]`, heading);
        });
    }

    async function runPreview(confirmedOverrides = overrides, confirmedSheet = sheet) {
        setStage('previewing');
        setError(null);

        const formData = new FormData();
        formData.append('file', file);
        formData.append('company_id', companyId);
        // The mapping the customer SAW and agreed to, and the sheet they
        // picked -- the v2.108.1 rule ("the preview is a promise the
        // import keeps") now applied to a mapping they chose rather than
        // one they were never shown.
        appendConfirmedMapping(formData, confirmedOverrides, confirmedSheet);
        appendScreeningPlan(formData);

        try {
            const response = await fetch(route('ppe.import.preview'), {
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
            // Back to the mapping stage when there is one to go back to:
            // a refused file is usually a wrong sheet or a wrong column,
            // and sending the customer back to the file picker loses the
            // work they just did.
            setStage(screening ? 'screen' : 'upload');
        }
    }

    async function confirmImport() {
        setStage('processing');
        setError(null);

        const formData = new FormData();
        formData.append('file', file);
        formData.append('company_id', companyId);
        // The same mapping and sheet the preview ran on -- the import must
        // not read the file a different way.
        appendConfirmedMapping(formData, overrides, sheet);
        appendScreeningPlan(formData);

        try {
            const response = await fetch(route('ppe.import'), {
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

    const report = preview ?? result;

    return (
        <Dialog open={open} onOpenChange={(v) => (v ? onOpenChange(true) : close())}>
            <DialogContent className={stage === 'screen' ? 'max-w-3xl' : (['preview', 'summary'].includes(stage) ? 'max-w-lg' : undefined)}>
                <DialogHeader><DialogTitle>Import PPE</DialogTitle></DialogHeader>

                {stage === 'upload' && (
                    <div className="space-y-4">
                        <div className="rounded-lg border border-graphite-100 bg-graphite-50/60 p-3">
                            <p className="mb-2 text-[13px] text-graphite-600">
                                Satu baris untuk satu karyawan, dan satu kolom tanggal untuk setiap jenis APD.
                                Sel yang dikosongkan berarti tidak ada catatan pemberian dan tidak akan
                                menerbitkan apa pun. Karyawan dan jenis APD harus sudah terdaftar: impor ini
                                tidak pernah membuat karyawan baru maupun jenis APD baru.
                            </p>
                            <Button variant="outline" size="sm" asChild>
                                <a href={route('ppe.import-template', companyId ? { company_id: companyId } : {})}>
                                    <Download className="h-3.5 w-3.5" /> Download Template
                                </a>
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
                                <input
                                    type="file"
                                    accept=".xlsx,.xls,.csv,.docx,.pdf"
                                    className="hidden"
                                    onChange={(e) => setFile(e.target.files[0])}
                                />
                            </label>
                            <p className="text-xs text-graphite-500">
                                Menerima Excel (.xlsx, .xls), CSV, Word (.docx) dan PDF. Kolom wajib hanya
                                Employee ID; judul kolom APD harus sama dengan nama jenis di PPE Master.
                            </p>
                        </div>

                        {error && <p className="text-xs text-red-600">{error}</p>}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={close}>Cancel</Button>
                            <Button type="button" onClick={() => runScreen()} disabled={!file || !companyId}>Continue</Button>
                        </DialogFooter>
                    </div>
                )}

                {stage === 'screen' && (
                    <div className="space-y-4">
                        <PpeImportScreening
                            screening={screening}
                            rejectedRows={rejectedRows}
                            busy={busy}
                            onSheetChange={(v) => {
                                /*
                                 * A different sheet has different columns, so
                                 * the corrections cannot carry over -- keeping
                                 * them would map this sheet's data through the
                                 * other sheet's positions. The rejections go
                                 * too: a row number means a different row now.
                                 */
                                setColumnOverrides({});
                                setRejectedRows([]);
                                setSheet(v);
                                setHeaderRow(null);
                                runScreen({ sheet: v, headerRow: null, columns: {} });
                            }}
                            onHeaderRowChange={(n) => {
                                setColumnOverrides({});
                                setRejectedRows([]);
                                setHeaderRow(n);
                                runScreen({ headerRow: n, columns: {} });
                            }}
                            onColumnChange={(index, patch) => {
                                const next = {
                                    ...columnOverrides,
                                    [index]: { ...(columnOverrides[index] ?? {}), ...patch },
                                };
                                setColumnOverrides(next);
                                runScreen({ columns: next });
                            }}
                            onToggleReject={(row) => setRejectedRows((prev) =>
                                prev.includes(row) ? prev.filter((r) => r !== row) : [...prev, row])}
                        />

                        {error && <p className="text-xs text-red-600">{error}</p>}

                        <DialogFooter className="gap-2 sm:gap-0">
                            <Button type="button" variant="outline" onClick={() => setStage('upload')}>
                                <ArrowLeft className="h-3.5 w-3.5" /> Back
                            </Button>
                            {/* Nothing has been written at this point and
                                nothing will be until the review stage's own
                                confirm -- this only asks the IMPORTER what it
                                would do, which is a second opinion on the
                                screening from the code that does the writing. */}
                            <Button type="button" onClick={() => runPreview()} disabled={busy || (screening?.counts?.data ?? 0) === 0}>
                                Review &amp; Confirm
                            </Button>
                        </DialogFooter>
                    </div>
                )}

                {(stage === 'screening' || stage === 'previewing' || stage === 'processing') && (
                    <div className="flex flex-col items-center justify-center gap-3 py-10">
                        <Loader2 className="h-8 w-8 animate-spin text-brand-500" />
                        <p className="text-sm text-graphite-500">
                            {stage === 'screening'
                                ? 'Membaca struktur berkas...'
                                : stage === 'previewing' ? 'Scanning your file...' : 'Issuing PPE...'}
                        </p>
                    </div>
                )}

                {(stage === 'preview' || stage === 'summary') && report && (
                    <div className="space-y-4">
                        <p className="text-xs uppercase tracking-wide text-graphite-400">
                            {stage === 'preview' ? 'PPE Import Preview' : 'Import Summary'}
                        </p>

                        {/*
                            v2.121.0 -- a wide file has TWO counts that matter.
                            "24 items" alone hides how many people it covers,
                            and "8 rows" alone hides how much was issued.
                        */}
                        <div className="grid grid-cols-4 gap-2 text-center">
                            <div className="rounded-lg border border-graphite-100 p-2.5">
                                <p className="text-lg font-semibold text-navy-900">{report.total_rows}</p>
                                <p className="text-[11px] text-graphite-500">Rows Read</p>
                            </div>
                            <div className="rounded-lg border border-success/25 bg-success/[0.06] p-2.5">
                                <p className="text-lg font-semibold text-success">{report.imported}</p>
                                <p className="text-[11px] text-graphite-500">
                                    {stage === 'preview' ? 'PPE To Issue' : 'PPE Issued'}
                                </p>
                            </div>
                            <div className="rounded-lg border border-graphite-100 p-2.5">
                                <p className="text-lg font-semibold text-navy-900">{report.employees_touched ?? 0}</p>
                                <p className="text-[11px] text-graphite-500">Employees</p>
                            </div>
                            <div className="rounded-lg border border-graphite-100 p-2.5">
                                <p className="text-lg font-semibold text-navy-900">{report.skipped?.length ?? 0}</p>
                                <p className="text-[11px] text-graphite-500">Skipped</p>
                            </div>
                        </div>

                        {report.blank_cells > 0 && (
                            <p className="text-xs text-graphite-500">
                                {report.blank_cells} sel dikosongkan, jadi tidak ada catatan pemberian untuk
                                jenis APD tersebut. Tidak ada tanggal yang dikarang.
                            </p>
                        )}

                        {report.already_held > 0 && (
                            <div className="flex items-start gap-2 rounded-lg border border-graphite-100 bg-graphite-50/60 p-3">
                                <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                                <p className="text-xs leading-relaxed text-graphite-600">
                                    <span className="font-semibold text-navy-900">{report.already_held} baris</span>{' '}
                                    dilewati karena karyawannya masih memegang APD sejenis (status issued atau in use).
                                    Ini yang membuat impor ulang berkas yang sama aman: tidak ada penerbitan ganda.
                                </p>
                            </div>
                        )}

                        {report.unknown_columns?.length > 0 && (
                            <div className="rounded-lg border border-danger/25 bg-danger/[0.06] p-3">
                                <p className="mb-1 text-xs font-semibold text-red-900">
                                    Kolom berikut tidak cocok dengan jenis APD mana pun di PPE Master
                                </p>
                                <p className="text-xs leading-relaxed text-red-800">
                                    {report.unknown_columns.join(', ')}
                                </p>
                                <p className="mt-1.5 text-xs text-red-800">
                                    Isi kolom tersebut tidak terbaca. Tambahkan jenisnya di PPE Master lalu impor
                                    ulang, atau hapus kolomnya bila memang bukan APD.
                                </p>
                            </div>
                        )}

                        {report.unknown_employees?.length > 0 && (
                            <div className="rounded-lg border border-danger/25 bg-danger/[0.06] p-3">
                                <p className="mb-1 text-xs font-semibold text-red-900">
                                    Employee ID berikut tidak ditemukan di unit operasi ini
                                </p>
                                <p className="text-xs leading-relaxed text-red-800">
                                    {report.unknown_employees.slice(0, 12).join(', ')}
                                    {report.unknown_employees.length > 12 && ` (+${report.unknown_employees.length - 12} lainnya)`}
                                </p>
                                <p className="mt-1.5 text-xs text-red-800">
                                    Daftarkan karyawannya lebih dulu lewat Employees &gt; Import, lalu impor ulang.
                                </p>
                            </div>
                        )}

                        {report.warnings?.length > 0 && (
                            <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
                                <p className="mb-1 flex items-center gap-1.5 text-xs font-semibold text-amber-900">
                                    <AlertTriangle className="h-3.5 w-3.5" /> {report.warnings.length} catatan
                                </p>
                                <ul className="max-h-24 space-y-0.5 overflow-y-auto text-xs text-amber-800">
                                    {report.warnings.slice(0, 10).map((w) => (
                                        <li key={`${w.row}-${w.reason}`}>Baris {w.row}: {w.reason}</li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {report.skipped?.length > 0 && (
                            <div className="rounded-lg border border-graphite-100 p-3">
                                <p className="mb-1 text-xs font-semibold text-graphite-600">Baris yang dilewati</p>
                                <ul className="max-h-32 space-y-0.5 overflow-y-auto text-xs text-graphite-500">
                                    {report.skipped.slice(0, 20).map((s) => (
                                        <li key={`${s.row}-${s.reason}`}>Baris {s.row}: {s.reason}</li>
                                    ))}
                                    {report.skipped.length > 20 && (
                                        <li className="text-graphite-400">+{report.skipped.length - 20} baris lainnya</li>
                                    )}
                                </ul>
                            </div>
                        )}

                        {error && <p className="text-xs text-red-600">{error}</p>}

                        <DialogFooter>
                            {stage === 'preview' ? (
                                <>
                                    <Button type="button" variant="outline" onClick={() => setStage('upload')}>Back</Button>
                                    <Button type="button" onClick={confirmImport} disabled={!report.imported}>
                                        Issue {report.imported} PPE Item(s)
                                    </Button>
                                </>
                            ) : (
                                <Button type="button" onClick={close}>
                                    <CheckCircle2 className="h-4 w-4" /> Done
                                </Button>
                            )}
                        </DialogFooter>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
