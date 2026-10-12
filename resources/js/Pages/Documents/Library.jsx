import { Head } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import {
    FileSpreadsheet,
    FileText,
    FileType2,
    Library,
    Upload,
    CheckCircle2,
    AlertTriangle,
} from 'lucide-react';

/*
 * v2.110.0 -- THE DOCUMENT LIBRARY.
 *
 * One surface for every document IOMS can generate, grouped by the
 * workspace that owns the data, plus an honest statement of which input
 * formats THIS deployment can read.
 *
 * The list is served by `DocumentLibrary::catalogue()`, which already
 * filtered it to what this user may read. Nothing here decides access --
 * a format button that appeared because the frontend guessed would be a
 * button that 404s.
 *
 * Language follows the house rule (CLAUDE.md): titles, column headers and
 * action labels in English; descriptions, hints and empty-state guidance
 * in Indonesian.
 */

const FORMAT_META = {
    xlsx: { label: 'Excel', icon: FileSpreadsheet },
    docx: { label: 'Word', icon: FileType2 },
    pdf: { label: 'PDF', icon: FileText },
};

const WORKSPACE_LABELS = {
    hse: 'Health, Safety & Environment',
    hr: 'People / HRD',
    logistics: 'Warehouse & Logistics',
    management: 'Management',
};

export default function DocumentLibraryPage({ documents = [], capabilities }) {
    const [inspection, setInspection] = useState(null);
    const [inspecting, setInspecting] = useState(false);
    const [inspectError, setInspectError] = useState(null);
    const fileInput = useRef(null);

    const grouped = documents.reduce((accumulator, entry) => {
        const key = entry.workspace || 'other';
        accumulator[key] = accumulator[key] || [];
        accumulator[key].push(entry);
        return accumulator;
    }, {});

    const inspect = async (event) => {
        const file = event.target.files?.[0];
        if (!file) return;

        setInspecting(true);
        setInspectError(null);
        setInspection(null);

        const body = new FormData();
        body.append('file', file);

        try {
            const response = await fetch(route('documents.inspect'), {
                method: 'POST',
                body,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
            });

            const payload = await response.json();

            if (!response.ok) {
                setInspectError(payload.message ?? 'Berkas ini tidak dapat dibaca.');
                return;
            }

            setInspection(payload);
        } catch (error) {
            setInspectError('Berkas tidak dapat dikirim. Periksa koneksi lalu coba lagi.');
        } finally {
            setInspecting(false);
            if (fileInput.current) fileInput.current.value = '';
        }
    };

    const readableFormats = Object.entries(capabilities?.formats ?? {});

    return (
        <AuthenticatedLayout>
            <Head title="Document Library" />

            <PageHeader
                title="Document Library"
                subtitle="Semua dokumen yang dapat dihasilkan IOMS, dikelompokkan menurut ruang kerja pemilik datanya."
            />

            {documents.length === 0 ? (
                <Card>
                    <CardContent className="pt-6">
                        <EmptyState
                            icon={Library}
                            title="No documents available"
                            description="Dokumen akan muncul di sini setelah modul pemiliknya diaktifkan."
                        />
                    </CardContent>
                </Card>
            ) : (
                <div className="space-y-6">
                    {Object.entries(grouped).map(([workspace, items]) => (
                        <section key={workspace}>
                            <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {WORKSPACE_LABELS[workspace] ?? workspace}
                            </h2>

                            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                {items.map((item) => (
                                    <Card key={item.key} className="flex flex-col">
                                        <CardHeader>
                                            <CardTitle className="text-base">{item.title}</CardTitle>
                                            <CardDescription>{item.description}</CardDescription>
                                        </CardHeader>

                                        <CardContent className="mt-auto space-y-3">
                                            <div className="flex flex-wrap gap-1">
                                                {item.columns.slice(0, 4).map((column) => (
                                                    <Badge key={column} variant="secondary" className="font-normal">
                                                        {column}
                                                    </Badge>
                                                ))}
                                                {item.columns.length > 4 && (
                                                    <Badge variant="outline" className="font-normal">
                                                        +{item.columns.length - 4}
                                                    </Badge>
                                                )}
                                            </div>

                                            <div className="flex flex-wrap gap-2">
                                                {item.formats.map((format) => {
                                                    const meta = FORMAT_META[format];
                                                    const Icon = meta?.icon ?? FileText;

                                                    return (
                                                        <Button
                                                            key={format}
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                window.open(
                                                                    route('documents.export', {
                                                                        key: item.key,
                                                                        format,
                                                                    }),
                                                                    '_blank',
                                                                    'noopener'
                                                                )
                                                            }
                                                        >
                                                            <Icon className="mr-1.5 h-4 w-4" />
                                                            {meta?.label ?? format.toUpperCase()}
                                                        </Button>
                                                    );
                                                })}
                                            </div>
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            )}

            {/*
                What this deployment can READ. Stated rather than implied,
                because an input format whose extractor is missing has to
                say so with its reason instead of silently failing on
                upload.
            */}
            <Card className="mt-8">
                <CardHeader>
                    <CardTitle className="text-base">Supported Input Formats</CardTitle>
                    <CardDescription>
                        Format yang dapat dibaca oleh instalasi ini. Berkas maksimal{' '}
                        {capabilities?.max_upload_mb ?? 15} MB dan {(capabilities?.max_rows ?? 20000).toLocaleString('id-ID')} baris.
                    </CardDescription>
                </CardHeader>

                <CardContent className="space-y-4">
                    <div className="flex flex-wrap gap-2">
                        {readableFormats.map(([format, state]) => (
                            <Badge
                                key={format}
                                variant={state.available ? 'secondary' : 'outline'}
                                title={state.reason ?? undefined}
                                className="font-normal"
                            >
                                {state.available ? (
                                    <CheckCircle2 className="mr-1.5 h-3.5 w-3.5 text-emerald-600" />
                                ) : (
                                    <AlertTriangle className="mr-1.5 h-3.5 w-3.5 text-amber-600" />
                                )}
                                {state.label}
                            </Badge>
                        ))}
                    </div>

                    {capabilities?.ocr && !capabilities.ocr.available && (
                        /* The reason is a complete sentence from
                           OcrExtractor::unavailableReason(); prefixing it
                           with our own would say the same thing twice. */
                        <p className="text-xs text-slate-500">{capabilities.ocr.reason}</p>
                    )}

                    <div className="rounded-md border border-dashed p-4">
                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => fileInput.current?.click()}
                                disabled={inspecting}
                            >
                                <Upload className="mr-1.5 h-4 w-4" />
                                {inspecting ? 'Reading...' : 'Inspect a Document'}
                            </Button>

                            <span className="text-xs text-slate-500">
                                Membaca isi berkas dan menampilkan tabel yang ditemukan. Tidak ada data yang disimpan.
                            </span>
                        </div>

                        <input
                            ref={fileInput}
                            type="file"
                            className="hidden"
                            accept=".xlsx,.xls,.csv,.docx,.pdf"
                            onChange={inspect}
                        />

                        {inspectError && (
                            <p className="mt-3 text-sm text-red-600">{inspectError}</p>
                        )}

                        {inspection && (
                            <div className="mt-4 space-y-3">
                                <p className="text-xs text-slate-500">
                                    {inspection.format_label} &middot; {inspection.tables.length} tabel ditemukan
                                </p>

                                {inspection.warnings?.map((warning) => (
                                    <p key={warning} className="text-xs text-amber-700">
                                        {warning}
                                    </p>
                                ))}

                                {inspection.tables.map((table) => (
                                    <div key={table.index} className="rounded border p-3">
                                        <div className="mb-2 flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-medium">{table.source}</span>
                                            <Badge variant="outline" className="font-normal">
                                                {table.row_count} baris
                                            </Badge>
                                            {/*
                                                Confidence is shown whenever it is
                                                below certainty, because a table
                                                reconstructed from a PDF's glyph
                                                positions is a reading of a drawing
                                                and the reader has to know that.
                                            */}
                                            {table.confidence < 1 && (
                                                <Badge variant="outline" className="font-normal text-amber-700">
                                                    Keyakinan {Math.round(table.confidence * 100)}%
                                                </Badge>
                                            )}
                                        </div>

                                        <div className="flex flex-wrap gap-1">
                                            {table.headings.map((heading, index) => (
                                                <Badge key={`${heading}-${index}`} variant="secondary" className="font-normal">
                                                    {heading}
                                                </Badge>
                                            ))}
                                        </div>

                                        {table.notes?.map((note) => (
                                            <p key={note} className="mt-2 text-xs text-slate-500">
                                                {note}
                                            </p>
                                        ))}
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </CardContent>
            </Card>
        </AuthenticatedLayout>
    );
}
