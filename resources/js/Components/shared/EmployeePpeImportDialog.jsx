import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Download, Upload, Loader2, CheckCircle2, AlertTriangle, ShieldCheck } from 'lucide-react';

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
function appendColumnMapping(formData, suggested) {
    if (!suggested?.mapping) return;

    Object.entries(suggested.mapping).forEach(([field, match]) => {
        if (match?.heading) {
            formData.append(`column_mapping[${field}]`, match.heading);
        }
    });
}

export default function EmployeePpeImportDialog({ open, onOpenChange, companies = [] }) {
    const ownCompanyId = usePage().props.auth?.user?.company_id;
    const defaultCompanyId = companies.find((c) => String(c.id) === String(ownCompanyId))
        ? String(ownCompanyId)
        : (companies.length === 1 ? String(companies[0].id) : undefined);

    const [stage, setStage] = useState('upload'); // upload | previewing | preview | processing | summary
    const [companyId, setCompanyId] = useState(defaultCompanyId);
    const [file, setFile] = useState(null);
    const [mapping, setMapping] = useState(null);
    const [preview, setPreview] = useState(null);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);

    function reset() {
        setStage('upload');
        setFile(null);
        setMapping(null);
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

    async function runPreview() {
        setStage('previewing');
        setError(null);

        // The mapping is resolved FIRST and travels with every request that
        // reads the file, so the preview is a promise the import keeps --
        // the v2.108.1 correction, applied here from the start.
        let suggested = null;

        try {
            const inspectBody = new FormData();
            inspectBody.append('file', file);

            const inspected = await fetch(route('ppe.import.inspect'), {
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
            setStage('upload');
        }
    }

    async function confirmImport() {
        setStage('processing');
        setError(null);

        const formData = new FormData();
        formData.append('file', file);
        formData.append('company_id', companyId);
        // The same mapping the preview ran on -- the import must not read
        // the file a different way.
        appendColumnMapping(formData, mapping);

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
            <DialogContent className={stage === 'preview' || stage === 'summary' ? 'max-w-lg' : undefined}>
                <DialogHeader><DialogTitle>Import PPE</DialogTitle></DialogHeader>

                {stage === 'upload' && (
                    <div className="space-y-4">
                        <div className="rounded-lg border border-graphite-100 bg-graphite-50/60 p-3">
                            <p className="mb-2 text-[13px] text-graphite-600">
                                Terbitkan APD untuk banyak karyawan sekaligus. Karyawan dan jenis APD harus
                                sudah terdaftar: impor ini tidak pernah membuat karyawan baru maupun jenis
                                APD baru.
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
                                Menerima Excel (.xlsx, .xls), CSV, Word (.docx) dan PDF. Kolom wajib:
                                Employee ID, PPE Type dan Issued Date.
                            </p>
                        </div>

                        {error && <p className="text-xs text-red-600">{error}</p>}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={close}>Cancel</Button>
                            <Button type="button" onClick={runPreview} disabled={!file || !companyId}>Preview Import</Button>
                        </DialogFooter>
                    </div>
                )}

                {(stage === 'previewing' || stage === 'processing') && (
                    <div className="flex flex-col items-center justify-center gap-3 py-10">
                        <Loader2 className="h-8 w-8 animate-spin text-brand-500" />
                        <p className="text-sm text-graphite-500">
                            {stage === 'previewing' ? 'Scanning your file...' : 'Issuing PPE...'}
                        </p>
                    </div>
                )}

                {(stage === 'preview' || stage === 'summary') && report && (
                    <div className="space-y-4">
                        <p className="text-xs uppercase tracking-wide text-graphite-400">
                            {stage === 'preview' ? 'PPE Import Preview' : 'Import Summary'}
                        </p>

                        <div className="grid grid-cols-3 gap-2 text-center">
                            <div className="rounded-lg border border-graphite-100 p-2.5">
                                <p className="text-lg font-semibold text-navy-900">{report.total_rows}</p>
                                <p className="text-[11px] text-graphite-500">Rows Read</p>
                            </div>
                            <div className="rounded-lg border border-success/25 bg-success/[0.06] p-2.5">
                                <p className="text-lg font-semibold text-success">{report.imported}</p>
                                <p className="text-[11px] text-graphite-500">
                                    {stage === 'preview' ? 'Will Be Issued' : 'Issued'}
                                </p>
                            </div>
                            <div className="rounded-lg border border-graphite-100 p-2.5">
                                <p className="text-lg font-semibold text-navy-900">{report.skipped?.length ?? 0}</p>
                                <p className="text-[11px] text-graphite-500">Skipped</p>
                            </div>
                        </div>

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

                        {report.unknown_ppe_types?.length > 0 && (
                            <div className="rounded-lg border border-danger/25 bg-danger/[0.06] p-3">
                                <p className="mb-1 text-xs font-semibold text-red-900">
                                    Jenis APD berikut belum ada di PPE Master
                                </p>
                                <p className="text-xs leading-relaxed text-red-800">
                                    {report.unknown_ppe_types.join(', ')}
                                </p>
                                <p className="mt-1.5 text-xs text-red-800">
                                    Tambahkan lebih dulu di PPE Master, lalu impor ulang berkasnya. Impor ini
                                    sengaja tidak membuat jenis APD baru secara otomatis.
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
