import { useState } from 'react';
import { X } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';

/**
 * v2.73.0 -- A SHORT LIST OF FREE-TEXT ENTRIES, ENTERED ONE AT A TIME.
 *
 * For the places where a controlled vocabulary genuinely does not exist:
 * the hazards a specific permit is controlling, PPE a tenant has not put
 * in its master yet.
 *
 * WHAT THIS REPLACES. JSA stores `required_ppe` as a comma-separated
 * string typed into one box (`value.split(',')`), which has the failure
 * mode you would expect: a trailing comma makes an empty entry, an item
 * containing a comma splits into two, and nothing shows the user what
 * they have actually got until they save. Here each entry is committed on
 * Enter and then visible as its own removable chip, so the list is the
 * thing being edited rather than a string that happens to be parsed as
 * one.
 *
 * ENTER COMMITS. Blur commits too -- somebody who types an entry and taps
 * Save should not silently lose it, which is exactly what a commit-on-
 * Enter-only field does. Backspace on an empty box removes the last chip,
 * because that is what every tag field does and fighting it is worse than
 * supporting it.
 */
export default function TagInput({
    value = [],
    onChange,
    placeholder,
    disabled = false,
    maxLength = 160,
    ariaLabel,
    className,
}) {
    const [draft, setDraft] = useState('');
    const tags = value || [];

    function commit() {
        const entry = draft.trim();
        if (!entry) { setDraft(''); return; }
        // Duplicates are dropped rather than rejected with a message: on a
        // permit, "harness" twice is a slip, not something worth
        // interrupting somebody over.
        if (!tags.includes(entry)) onChange([...tags, entry.slice(0, maxLength)]);
        setDraft('');
    }

    function handleKeyDown(e) {
        if (e.key === 'Enter') {
            // Enter in a field inside a <form> would otherwise submit the
            // whole permit.
            e.preventDefault();
            commit();
            return;
        }
        if (e.key === 'Backspace' && draft === '' && tags.length > 0) {
            onChange(tags.slice(0, -1));
        }
    }

    return (
        <div className={cn('space-y-2', className)}>
            <Input
                value={draft}
                disabled={disabled}
                placeholder={placeholder}
                aria-label={ariaLabel}
                onChange={(e) => setDraft(e.target.value)}
                onKeyDown={handleKeyDown}
                onBlur={commit}
            />

            {tags.length > 0 && (
                <ul className="flex flex-wrap gap-2" aria-label={ariaLabel ? `${ariaLabel} entries` : undefined}>
                    {tags.map((tag, i) => (
                        <li
                            key={`${tag}-${i}`}
                            className="inline-flex max-w-full items-center gap-1.5 rounded-full border border-steel-200 bg-steel-50 py-1 pl-3 pr-1.5 text-[13px] text-navy-800 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-200"
                        >
                            <span className="truncate">{tag}</span>
                            {!disabled && (
                                <button
                                    type="button"
                                    onClick={() => onChange(tags.filter((_, idx) => idx !== i))}
                                    aria-label={`Remove ${tag}`}
                                    className="rounded-full p-0.5 text-graphite-400 hover:bg-graphite-200/60 hover:text-graphite-700 dark:hover:bg-slate-700 dark:hover:text-slate-200"
                                >
                                    <X className="h-3 w-3" aria-hidden="true" />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
