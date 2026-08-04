import { useState } from 'react';
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
export default function EmployeeImportDialog({ open, onOpenChange, companies }) {
    const [stage, setStage] = useState('upload'); // upload | previewing | preview | processing | summary
    const [companyId, setCompanyId] = useState(companies.length === 1 ? String(companies[0].id) : undefined);
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState(null);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);

    function reset() {
        setStage('upload');
        setFile(null);
        setPreview(null);
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
        if (!file || !companyId) return;

        setStage('previewing');
        setError(null);

        const formData = new FormData();
        formData.append('file', file);
        formData.append('company_id', companyId);

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
                <DialogHeader><DialogTitle>Import Employees from Excel</DialogTitle></DialogHeader>

                {stage === 'upload' && (
                    <div className="space-y-4">
                        <div className="rounded-lg border border-graphite-100 bg-graphite-50/60 p-3">
                            <p className="mb-2 text-[13px] text-graphite-600">
                                Download the template, fill in your employees, then upload it below.
                            </p>
                            <Button variant="outline" size="sm" asChild>
                                <a href={route('employees.import-template')}><Download className="h-3.5 w-3.5" /> Download Template</a>
                            </Button>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={companyId} onValueChange={setCompanyId}>
                                <SelectTrigger><SelectValue placeholder="Select company" /></SelectTrigger>
                                <SelectContent>
                                    {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Excel File (.xlsx or .xls)</Label>
                            <label className="flex h-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-graphite-300 text-graphite-400 hover:border-brand-400 hover:text-brand-600">
                                <Upload className="h-5 w-5" />
                                <span className="text-xs">{file ? file.name : 'Click to choose a file'}</span>
                                <input type="file" accept=".xlsx,.xls" className="hidden" onChange={(e) => setFile(e.target.files[0])} />
                            </label>
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

                        <div className="grid grid-cols-2 gap-3">
                            <PreviewStat label="Total Rows" value={preview.total_rows} />
                            <PreviewStat label="Valid Employees" value={preview.valid_rows} />
                            <PreviewStat label="Departments" value={`${preview.departments.existing.length} Existing / ${preview.departments.new.length} New`} small />
                            <PreviewStat label="Positions" value={`${preview.positions.existing.length} Existing / ${preview.positions.new.length} New`} small />
                            <PreviewStat label="Invalid Rows" value={preview.invalid_rows} accent={preview.invalid_rows > 0 ? 'amber' : null} />
                        </div>

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

                        <DialogFooter>
                            <Button type="button" onClick={close}>Done</Button>
                        </DialogFooter>
                    </div>
                )}
            </DialogContent>
        </Dialog>
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
