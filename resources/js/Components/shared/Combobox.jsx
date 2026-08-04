import { useState, useRef, useEffect } from 'react';
import { Input } from '@/Components/ui/input';

/**
 * Searchable combobox (v1.6.2) -- type-ahead suggestions from a known
 * list, while still allowing completely free text (the value is saved
 * on every keystroke, not just when a suggestion is clicked). This is
 * the "reusable component" version of what was previously a one-off
 * plain text input on Daily Report's Department field; any future field
 * that wants "suggest from existing values but don't force a selection"
 * should reuse this rather than re-implementing it.
 *
 * Deliberately not a traditional <select> and doesn't restrict input to
 * only the suggested list -- typing something not in `suggestions` is
 * always valid and is exactly what gets saved.
 *
 * Props:
 *   value        - current string value (controlled)
 *   onChange(value) - fires on every keystroke AND on suggestion click
 *   suggestions  - array of known values to offer as autocomplete
 *   placeholder
 */
export default function Combobox({ value, onChange, suggestions = [], placeholder, className }) {
    const [query, setQuery] = useState(value || '');
    const [open, setOpen] = useState(false);
    const containerRef = useRef(null);

    useEffect(() => setQuery(value || ''), [value]);

    useEffect(() => {
        function handleClickOutside(e) {
            if (containerRef.current && !containerRef.current.contains(e.target)) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const filtered = query.trim()
        ? suggestions.filter((s) => s.toLowerCase().includes(query.trim().toLowerCase()))
        : suggestions;

    function handleInputChange(e) {
        const v = e.target.value;
        setQuery(v);
        onChange(v); // free text is always the saved value, live -- never gated behind picking a suggestion
        setOpen(true);
    }

    function selectSuggestion(s) {
        setQuery(s);
        onChange(s);
        setOpen(false);
    }

    return (
        <div ref={containerRef} className="relative">
            <Input
                value={query}
                onChange={handleInputChange}
                onFocus={() => setOpen(true)}
                placeholder={placeholder}
                className={className}
                autoComplete="off"
            />
            {open && filtered.length > 0 && (
                <div className="absolute z-[120] mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-graphite-200 bg-white py-1 shadow-card-hover">
                    {filtered.map((s) => (
                        <button
                            key={s}
                            type="button"
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => selectSuggestion(s)}
                            className="block w-full px-3 py-2 text-left text-sm text-graphite-700 hover:bg-graphite-50"
                        >
                            {s}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
