import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Badge } from '@/Components/ui/badge';
import { FileSpreadsheet, Columns3 } from 'lucide-react';

const NONE = '__none__';

/**
 * v2.125.0 -- THE MAPPING THE CUSTOMER WAS SHOWN IS THE MAPPING THEY
 * AGREED TO.
 *
 * `ImportHeaderMatcher` has guessed which of the customer's headings is
 * which IOMS field since v2.102.0, and v2.108.1 fixed the import to
 * actually USE that guess. Both of those are about the machine's answer.
 * This is the part that was missing: the customer never saw the answer and
 * could not change it.
 *
 * Which matters because the guess can be confidently wrong in ways only
 * the person who made the file can see -- a workbook with both "NIK" and
 * "No. Induk" where one is the national ID and the other is the badge
 * number, a column called "Nama" that holds a supervisor rather than the
 * worker. The old flow imported those silently and the customer found out
 * from the row report, after the fact, having been told to go and
 * rearrange their file.
 *
 * And it is the brief's actual ask, which this panel answers literally:
 * suggest a mapping from the column-name variants, then let the person
 * confirm or override it, and let them remap WITHOUT editing the source
 * file.
 *
 * SHEET SELECTION lives here too, because it is the same question one
 * level up -- "which part of this workbook are we reading" before "which
 * column is which". The reader used to take whichever table it was most
 * confident about and never said which; a company workbook with Data,
 * Rekap and Template tabs made that a coin toss.
 *
 * Props:
 *   inspection  the inspect endpoint's body: field_labels, mapping,
 *               headings, sheets, chosen
 *   sheet       currently selected sheet name (or null for "as found")
 *   overrides   {fieldKey: heading} the user has confirmed
 */
export default function ImportMappingPanel({ inspection, sheet, overrides, onSheetChange, onOverridesChange }) {
    const fields = inspection?.field_labels ?? {};
    const sheets = inspection?.sheets ?? [];

    // Headings come from the SELECTED sheet, so switching tabs re-offers
    // that sheet's own columns rather than the first one's. No second
    // request: every sheet's headings arrived with the inspection.
    const selected = sheets.find((s) => s.name === sheet);
    const headings = selected?.headings ?? inspection?.headings ?? [];

    const unmatched = headings.filter((h) => !Object.values(overrides ?? {}).includes(h));

    return (
        <div className="space-y-4">
            {sheets.length > 1 && (
                <div className="space-y-1.5">
                    <Label className="flex items-center gap-1.5 text-xs">
                        <FileSpreadsheet className="h-3.5 w-3.5 text-graphite-400" /> Sheet
                    </Label>
                    <Select value={sheet ?? undefined} onValueChange={onSheetChange}>
                        <SelectTrigger><SelectValue placeholder="Pilih sheet" /></SelectTrigger>
                        <SelectContent>
                            {sheets.map((s) => (
                                <SelectItem key={s.name} value={s.name}>
                                    {s.name} ({s.row_count} baris, {s.column_count} kolom)
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <p className="text-[11px] leading-snug text-graphite-500">
                        Berkas ini punya {sheets.length} sheet. Pilih sheet yang memuat data yang ingin diimpor.
                    </p>
                </div>
            )}

            {sheets.length === 1 && (
                <p className="text-[11px] leading-snug text-graphite-500">
                    Sheet yang dibaca: <span className="font-medium text-graphite-700 dark:text-slate-300">{sheets[0].name}</span>
                    {' '}({sheets[0].row_count} baris).
                </p>
            )}

            <div className="space-y-2">
                <Label className="flex items-center gap-1.5 text-xs">
                    <Columns3 className="h-3.5 w-3.5 text-graphite-400" /> Column Mapping
                </Label>
                <p className="text-[11px] leading-snug text-graphite-500">
                    Kolom di berkas Anda sudah dicocokkan otomatis. Periksa hasilnya, dan ganti jika ada yang keliru.
                    Anda tidak perlu menyusun ulang berkas aslinya.
                </p>

                <div className="space-y-2 rounded-lg border border-graphite-200 p-3 dark:border-slate-700">
                    {Object.entries(fields).map(([field, label]) => {
                        const current = overrides?.[field] ?? '';
                        const suggestedHeading = inspection?.mapping?.[field]?.heading ?? null;

                        return (
                            <div key={field} className="grid grid-cols-1 items-center gap-1.5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] sm:gap-3">
                                <span className="min-w-0 truncate text-xs font-medium text-graphite-700 dark:text-slate-300">{label}</span>
                                <div className="min-w-0">
                                    <Select
                                        value={current === '' ? NONE : current}
                                        onValueChange={(v) => onOverridesChange({ ...(overrides ?? {}), [field]: v === NONE ? '' : v })}
                                    >
                                        <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {/* An explicit "not in my file" is a real
                                                answer, not an empty one: it is how a
                                                customer says a column genuinely does
                                                not exist rather than leaving the
                                                matcher's guess standing. */}
                                            <SelectItem value={NONE}>Tidak ada di berkas ini</SelectItem>
                                            {headings.map((h) => (
                                                <SelectItem key={h} value={h}>{h}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {suggestedHeading && current !== suggestedHeading && (
                                        <p className="mt-0.5 text-[10px] text-graphite-400">
                                            Saran otomatis: {suggestedHeading}
                                        </p>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {unmatched.length > 0 && (
                <div className="space-y-1.5">
                    <p className="text-[11px] font-medium text-graphite-600 dark:text-slate-400">
                        Kolom lain di berkas Anda
                    </p>
                    <div className="flex flex-wrap gap-1">
                        {unmatched.map((h) => (
                            <Badge key={h} variant="secondary" className="max-w-full text-[10px] font-normal">
                                <span className="truncate">{h}</span>
                            </Badge>
                        ))}
                    </div>
                    <p className="text-[11px] leading-snug text-graphite-500">
                        Kolom ini belum dipakai sebagai kolom identitas. Pada impor PPE, kolom bertanggal akan
                        dicocokkan dengan katalog PPE perusahaan Anda, dan kolom yang tidak dikenali akan
                        dilaporkan namanya saat pratinjau.
                    </p>
                </div>
            )}
        </div>
    );
}

/** The confirmed mapping, as the import endpoints expect it. */
export function appendConfirmedMapping(formData, overrides, sheet) {
    Object.entries(overrides ?? {}).forEach(([field, heading]) => {
        if (heading) formData.append(`column_mapping[${field}]`, heading);
    });

    if (sheet) formData.append('sheet', sheet);
}

/** The matcher's suggestion, as this panel's initial override state. */
export function overridesFromSuggestion(inspection) {
    const out = {};

    Object.keys(inspection?.field_labels ?? {}).forEach((field) => {
        out[field] = inspection?.mapping?.[field]?.heading ?? '';
    });

    return out;
}
