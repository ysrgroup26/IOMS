import { useState, useEffect } from 'react';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { ImagePlus, X } from 'lucide-react';

/**
 * Single-image upload with a live preview -- the one consistent pattern
 * every single-image upload in the app should use (v1.5.2). Shows
 * whichever is relevant: a newly-selected (not yet saved) file preview,
 * the existing saved image, or an empty placeholder. Currently used by
 * Branding Logo and Employee Photo; any future single-image upload
 * (Company Logo per-company, Asset Photo, etc.) should reuse this
 * component rather than re-implementing file-input + preview logic.
 *
 * Props:
 *   label        - field label text
 *   existingUrl  - URL of the already-saved image, if any (from the
 *                  model's real Eloquent accessor, e.g. photo_url)
 *   file         - the currently-selected File object (or null)
 *   onChange(file)      - called with the new File, or null when removed
 *   accept       - file input accept attribute (default: common image types)
 *   shape        - 'square' | 'circle' (preview container shape)
 *   error        - validation error message, if any
 */
export default function ImageUploadField({
    label, existingUrl, file, onChange,
    accept = 'image/png,image/jpeg,image/jpg,image/webp,image/svg+xml',
    shape = 'square', error,
}) {
    const [previewUrl, setPreviewUrl] = useState(null);

    useEffect(() => {
        if (!file) { setPreviewUrl(null); return; }
        const url = URL.createObjectURL(file);
        setPreviewUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [file]);

    const displayUrl = previewUrl || existingUrl;
    const shapeClass = shape === 'circle' ? 'rounded-full' : 'rounded-lg';

    function handleFileSelect(e) {
        onChange(e.target.files[0] || null);
        e.target.value = ''; // allow re-selecting the same file after removing it
    }

    function handleRemove() {
        onChange(null);
    }

    return (
        <div className="space-y-1.5">
            <Label>{label}</Label>
            <div className="flex items-center gap-3">
                <div className={`flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden border border-graphite-200 bg-graphite-50 ${shapeClass}`}>
                    {displayUrl ? (
                        <img src={displayUrl} alt={label} className="h-full w-full object-cover" />
                    ) : (
                        <ImagePlus className="h-5 w-5 text-graphite-300" />
                    )}
                </div>
                <div className="flex flex-col gap-1.5">
                    <label className="cursor-pointer">
                        <span className="inline-flex items-center gap-1.5 rounded-lg border border-graphite-200 bg-white px-3 py-1.5 text-xs font-medium text-graphite-700 shadow-sm hover:bg-graphite-50">
                            <ImagePlus className="h-3.5 w-3.5" /> {displayUrl ? 'Replace' : 'Upload'}
                        </span>
                        <input type="file" accept={accept} className="hidden" onChange={handleFileSelect} />
                    </label>
                    {displayUrl && (
                        <button type="button" onClick={handleRemove} className="flex items-center gap-1 text-xs text-graphite-400 hover:text-red-600">
                            <X className="h-3 w-3" /> Remove
                        </button>
                    )}
                </div>
            </div>
            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    );
}
