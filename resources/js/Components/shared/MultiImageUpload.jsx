import { useState, useEffect } from 'react';
import { Label } from '@/Components/ui/label';
import { ImagePlus, X } from 'lucide-react';

/**
 * Multi-image upload with a preview grid (v1.5.2) -- the one consistent
 * pattern every multi-image upload in the app should use. Distinguishes
 * between images already saved to the server (shown with their real URL,
 * removable via a callback that the parent wires to an actual delete
 * request) and newly-selected files not yet saved (shown via local
 * object-URL previews, removable purely client-side before submit).
 * Currently used by Daily Report Documentation; any future multi-image
 * upload (Project Documentation, PPE Photos, Incident Evidence, Permit
 * Attachments, etc.) should reuse this rather than re-implementing a
 * preview grid from scratch.
 *
 * Props:
 *   label
 *   existingImages   - [{ id, url, caption? }] already saved
 *   onRemoveExisting(id) - called when removing an already-saved image
 *   files            - array of newly-selected File objects, not yet saved
 *   onFilesChange(files)
 *   maxFiles         - optional cap (default 10)
 *   error
 */
export default function MultiImageUpload({
    label, existingImages = [], onRemoveExisting,
    files = [], onFilesChange, maxFiles = 10, error,
}) {
    const [previews, setPreviews] = useState([]);

    useEffect(() => {
        const urls = files.map((f) => URL.createObjectURL(f));
        setPreviews(urls);
        return () => urls.forEach((u) => URL.revokeObjectURL(u));
    }, [files]);

    function handleFileSelect(e) {
        const selected = Array.from(e.target.files || []);
        onFilesChange([...files, ...selected].slice(0, maxFiles));
        e.target.value = '';
    }

    function removeNewFile(index) {
        onFilesChange(files.filter((_, i) => i !== index));
    }

    const totalCount = existingImages.length + files.length;

    return (
        <div className="space-y-2">
            {label && <Label>{label}</Label>}

            {(existingImages.length > 0 || previews.length > 0) && (
                <div className="grid grid-cols-3 gap-3 sm:grid-cols-4">
                    {existingImages.map((img) => (
                        <div key={`existing-${img.id}`} className="group relative aspect-square overflow-hidden rounded-lg border border-graphite-200">
                            <img src={img.url} alt={img.caption || ''} className="h-full w-full object-cover" />
                            {onRemoveExisting && (
                                <button
                                    type="button"
                                    onClick={() => onRemoveExisting(img.id)}
                                    className="absolute right-1 top-1 rounded-full bg-graphite-900/70 p-1 text-white opacity-0 transition-opacity group-hover:opacity-100"
                                >
                                    <X className="h-3 w-3" />
                                </button>
                            )}
                        </div>
                    ))}
                    {previews.map((url, i) => (
                        <div key={`new-${i}`} className="group relative aspect-square overflow-hidden rounded-lg border border-brand-200">
                            <img src={url} alt="" className="h-full w-full object-cover" />
                            <span className="absolute left-1 top-1 rounded bg-brand-600 px-1.5 py-0.5 text-[9px] font-semibold text-white">NEW</span>
                            <button
                                type="button"
                                onClick={() => removeNewFile(i)}
                                className="absolute right-1 top-1 rounded-full bg-graphite-900/70 p-1 text-white opacity-0 transition-opacity group-hover:opacity-100"
                            >
                                <X className="h-3 w-3" />
                            </button>
                        </div>
                    ))}
                </div>
            )}

            {totalCount < maxFiles && (
                <label className="flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-dashed border-graphite-300 py-3 text-sm text-graphite-500 hover:border-graphite-400 hover:bg-graphite-50">
                    <ImagePlus className="h-4 w-4" /> Add Photos
                    <input type="file" accept="image/*" multiple className="hidden" onChange={handleFileSelect} />
                </label>
            )}

            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    );
}
