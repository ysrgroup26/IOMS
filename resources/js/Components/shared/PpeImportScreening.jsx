import { Label } from '@/Components/ui/label';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { CheckCircle2, AlertTriangle, HelpCircle, FileSpreadsheet, Rows3, XCircle, RotateCcw } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * v2.126.0 -- THE SCREENING THE OWNER ASKED FOR, ON SCREEN.
 *
 * "Saya ingin proses impor seperti melakukan screening terlebih dahulu:
 * lihat, cocokkan, koreksi atau tolak, periksa hasil akhir, lalu konfirmasi
 * upload."
 *
 * v2.125.0 shipped a sheet picker and a two-row mapping panel and the owner
 * reported that in use nothing had changed -- correctly, because the two
 * mappable fields for this import are the employee's number and name, and
 * everything that actually matters in a company's register (which column is
 * which equipment, which holds a date and which a size, which rows are even
 * people, who each row is about) was still decided silently.
 *
 * This panel renders the SERVER'S whole reading and lets the person
 * disagree with every part of it. It holds no interpretation of its own:
 * every role, every proposed PPE type, every match and every reason comes
 * from `PpeImportInterpreter`, and a correction is posted back and
 * re-screened rather than applied locally -- so what is on screen is always
 * what the server would actually do, which is the only thing that makes a
 * preview a promise.
 *
 * THREE STATES, AND THEY MEAN DIFFERENT THINGS. Matched is "this is
 * certain". Review is "this is probably right and you should look".
 * Unmatched is "I do not know". The owner's brief is explicit that
 * automatic detection must not be presented as certainty, so the three
 * never share a colour and `review` never reads as done.
 */

/*
 * The three words are STATUS LABELS, so they are English -- v2.53.0's
 * language hierarchy, which `LanguageHierarchyTest` pins for exactly this
 * slot. The REASONS beside them are explanatory copy and stay Indonesian,
 * which is the same split every other page in the product uses.
 */
const CONFIDENCE = {
    matched: { Icon: CheckCircle2, cls: 'text-emerald-600', label: 'Matched' },
    review: { Icon: AlertTriangle, cls: 'text-amber-600', label: 'Needs review' },
    unmatched: { Icon: HelpCircle, cls: 'text-graphite-400', label: 'No match' },
};

const MATCH_TONE = {
    matched: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300',
    review: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300',
    ambiguous: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300',
    not_found: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300',
};

const MATCH_LABEL = {
    matched: 'Matched',
    review: 'Needs review',
    ambiguous: 'Duplicate name',
    not_found: 'Not found',
};

const ROW_KIND_LABEL = {
    blank: 'Blank row',
    section: 'Heading / separator',
    subtotal: 'Subtotal',
};

export default function PpeImportScreening({
    screening,
    rejectedRows,
    onSheetChange,
    onHeaderRowChange,
    onColumnChange,
    onToggleReject,
    busy = false,
}) {
    const columns = screening?.columns ?? [];
    const rows = screening?.rows ?? [];
    const counts = screening?.counts ?? {};
    const catalogue = screening?.catalogue ?? [];
    const roles = screening?.roles ?? {};
    const sheets = screening?.sheets ?? [];

    const dataRows = rows.filter((r) => r.kind === 'data');
    const otherRows = rows.filter((r) => r.kind !== 'data');
    const typeName = (id) => catalogue.find((t) => Number(t.id) === Number(id))?.name ?? null;

    return (
        <div className={cn('space-y-5', busy && 'pointer-events-none opacity-60')}>
            {/* ---------- 1. which sheet, and where its headings are ---------- */}
            <section className="space-y-2">
                <Label className="flex items-center gap-1.5 text-xs">
                    <FileSpreadsheet className="h-3.5 w-3.5 text-graphite-400" /> Worksheet
                </Label>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <Select value={screening?.sheet ?? undefined} onValueChange={onSheetChange}>
                        <SelectTrigger className="h-9"><SelectValue placeholder="Pilih sheet" /></SelectTrigger>
                        <SelectContent>
                            {sheets.map((s) => (
                                <SelectItem key={s.name} value={s.name}>
                                    {s.name} ({s.row_count} baris)
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={String(screening?.header_row ?? 1)} onValueChange={(v) => onHeaderRowChange(Number(v))}>
                        <SelectTrigger className="h-9"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            {/* A register often carries a title and a blank
                                line above its headings, and the reader's
                                guess is a guess. */}
                            {[1, 2, 3, 4, 5, 6, 7, 8].map((n) => (
                                <SelectItem key={n} value={String(n)}>Baris judul: {n}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </section>

            {/* ---------- 2. what each column is ---------- */}
            <section className="space-y-2">
                <Label className="flex items-center gap-1.5 text-xs">
                    <Rows3 className="h-3.5 w-3.5 text-graphite-400" /> Column Mapping
                </Label>
                <p className="text-[11px] leading-snug text-graphite-500">
                    Ini hasil pembacaan IOMS atas kolom di berkas Anda, lengkap dengan alasannya. Periksa setiap
                    baris dan ubah yang keliru. Anda tidak perlu menyusun ulang berkas aslinya.
                </p>

                <div className="divide-y divide-graphite-100 overflow-hidden rounded-lg border border-graphite-200 dark:divide-slate-800 dark:border-slate-700">
                    {columns.map((c) => {
                        const tone = CONFIDENCE[c.confidence] ?? CONFIDENCE.unmatched;
                        const needsType = c.role === 'ppe_date' || c.role === 'ppe_size';

                        return (
                            <div key={c.index} className="grid grid-cols-1 gap-2 p-2.5 sm:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)_minmax(0,1fr)] sm:items-start sm:gap-3">
                                <div className="flex min-w-0 items-start gap-1.5">
                                    <tone.Icon className={cn('mt-0.5 h-3.5 w-3.5 shrink-0', tone.cls)} aria-hidden="true" />
                                    <span className="min-w-0">
                                        <span className="block truncate text-xs font-medium text-graphite-800 dark:text-slate-200">
                                            {c.heading || <em className="font-normal text-graphite-400">Tanpa judul</em>}
                                        </span>
                                        <span className="block text-[10px] text-graphite-400">Kolom {c.index + 1} · {tone.label}</span>
                                    </span>
                                </div>

                                <div className="min-w-0 space-y-1.5">
                                    <Select value={c.role} onValueChange={(v) => onColumnChange(c.index, { role: v })}>
                                        <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(roles).map(([key, label]) => (
                                                <SelectItem key={key} value={key}>{label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>

                                    {needsType && (
                                        <Select
                                            value={c.ppe_type_id ? String(c.ppe_type_id) : '__none__'}
                                            onValueChange={(v) => onColumnChange(c.index, { ppe_type_id: v === '__none__' ? null : Number(v) })}
                                        >
                                            <SelectTrigger className="h-8 text-xs"><SelectValue placeholder="Pilih jenis APD" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">Belum dipasangkan</SelectItem>
                                                {catalogue.map((t) => (
                                                    <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    )}
                                </div>

                                <p className="text-[11px] leading-snug text-graphite-500 dark:text-slate-400">{c.reason}</p>
                            </div>
                        );
                    })}
                </div>
            </section>

            {/* ---------- 3. what it read, row by row ---------- */}
            <section className="space-y-2">
                <div className="flex flex-wrap items-center gap-2">
                    <Label className="text-xs">Preview</Label>
                    <Badge variant="outline" className="text-[10px] font-normal">{counts.data ?? 0} employee rows</Badge>
                    {(counts.issuances ?? 0) > 0 && (
                        <Badge variant="outline" className="border-emerald-200 text-[10px] font-normal text-emerald-700">{counts.issuances} PPE to issue</Badge>
                    )}
                    {(counts.review ?? 0) > 0 && (
                        <Badge variant="outline" className="border-amber-200 text-[10px] font-normal text-amber-700">{counts.review} need review</Badge>
                    )}
                    {(counts.unresolved ?? 0) > 0 && (
                        <Badge variant="outline" className="border-red-200 text-[10px] font-normal text-red-700">{counts.unresolved} unmatched</Badge>
                    )}
                    {(counts.problems ?? 0) > 0 && (
                        <Badge variant="outline" className="border-red-200 text-[10px] font-normal text-red-700">{counts.problems} date problems</Badge>
                    )}
                </div>

                <div className="max-h-80 space-y-1.5 overflow-y-auto overscroll-contain rounded-lg border border-graphite-200 p-2 dark:border-slate-700">
                    {dataRows.length === 0 && (
                        <p className="px-2 py-6 text-center text-xs text-graphite-400">
                            Tidak ada baris karyawan yang terbaca pada sheet ini. Periksa pilihan sheet dan baris judul di atas.
                        </p>
                    )}

                    {dataRows.map((r) => {
                        const rejected = rejectedRows.includes(r.source_row);
                        const tone = MATCH_TONE[r.match?.status] ?? MATCH_TONE.not_found;

                        return (
                            <div
                                key={r.source_row}
                                className={cn(
                                    'rounded-md border p-2 text-xs transition-opacity',
                                    rejected ? 'border-graphite-200 bg-graphite-50 opacity-50 dark:border-slate-800 dark:bg-slate-900' : tone
                                )}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            <span className="mr-1.5 font-normal opacity-60">#{r.source_row}</span>
                                            {r.match?.employee?.full_name
                                                ?? <em className="font-normal">{r.match?.source || 'tanpa identitas'}</em>}
                                            {r.match?.employee?.employee_id && (
                                                <span className="ml-1.5 font-normal opacity-60">{r.match.employee.employee_id}</span>
                                            )}
                                        </p>
                                        <p className="text-[10px] opacity-80">
                                            {MATCH_LABEL[r.match?.status] ?? r.match?.status}
                                            {r.match?.matched_by ? ` · via ${r.match.matched_by}` : ''}
                                        </p>
                                        {r.match?.reason && <p className="mt-0.5 text-[10px] leading-snug opacity-90">{r.match.reason}</p>}
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-7 shrink-0 px-2 text-[11px]"
                                        onClick={() => onToggleReject(r.source_row)}
                                    >
                                        {rejected
                                            ? <><RotateCcw className="h-3 w-3" /> Restore</>
                                            : <><XCircle className="h-3 w-3" /> Reject</>}
                                    </Button>
                                </div>

                                {(r.issuances ?? []).length > 0 && (
                                    <div className="mt-1.5 flex flex-wrap gap-1">
                                        {r.issuances.map((i, n) => (
                                            <span
                                                key={n}
                                                className={cn(
                                                    'inline-flex max-w-full items-center gap-1 rounded border px-1.5 py-0.5 text-[10px]',
                                                    i.problem
                                                        ? 'border-red-300 bg-white text-red-700 dark:bg-slate-900'
                                                        : 'border-graphite-200 bg-white text-graphite-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'
                                                )}
                                                title={i.problem ?? undefined}
                                            >
                                                <span className="truncate font-medium">{typeName(i.ppe_type_id) ?? i.heading}</span>
                                                <span className="opacity-70">{i.issued_date ?? i.raw_date}</span>
                                                {i.size && <span className="opacity-70">· {i.size}</span>}
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>

                {otherRows.length > 0 && (
                    <details className="rounded-lg border border-graphite-200 p-2 dark:border-slate-700">
                        <summary className="cursor-pointer text-[11px] text-graphite-500">
                            {otherRows.length} baris dibaca sebagai bukan karyawan
                        </summary>
                        <ul className="mt-1.5 space-y-0.5">
                            {otherRows.slice(0, 60).map((r) => (
                                <li key={r.source_row} className="text-[11px] text-graphite-500">
                                    <span className="opacity-60">#{r.source_row}</span>{' '}
                                    <span className="font-medium">{ROW_KIND_LABEL[r.kind] ?? r.kind}</span>
                                    {r.reason ? `: ${r.reason}` : ''}
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
            </section>
        </div>
    );
}
