import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * v2.73.0 -- PICK SEVERAL FROM A SHORT, KNOWN LIST.
 *
 * Built for PPE on a permit, which is the case that needed it: eight to
 * twelve items from the tenant's own master, chosen on a phone, at a gate,
 * possibly wearing gloves.
 *
 * WHY NOT A MULTI-SELECT DROPDOWN. A dropdown hides the options until
 * tapped and then hides the SELECTION behind a summary line, so the
 * question a permit actually has to answer -- "is a harness on this list
 * or not" -- takes two interactions to see. Chips are all visible at
 * once, and the selected state is the answer.
 *
 * WHY NOT CHECKBOXES. Same information, roughly twice the vertical space,
 * and a 14px tap target where this gives a whole chip. The list is short
 * enough to wrap into two or three lines, which is precisely the range
 * where chips beat a column of boxes.
 *
 * NOT FOR LONG LISTS. Past ~20 options this becomes a wall; use
 * `SearchableSelect` there. That boundary is the reason this stayed a
 * separate component rather than becoming a mode of that one.
 */
export default function ChipSelect({
    options = [],
    value = [],
    onChange,
    disabled = false,
    idKey = 'id',
    labelKey = 'name',
    emptyLabel = 'No options available.',
    ariaLabel,
    className,
}) {
    // Compared as strings throughout: ids arrive as numbers from the
    // server and as strings from form state, and `includes` on mixed
    // types silently reports false.
    const selected = (value || []).map(String);

    function toggle(id) {
        if (disabled) return;
        const key = String(id);
        onChange(selected.includes(key) ? selected.filter((v) => v !== key) : [...selected, key]);
    }

    if (options.length === 0) {
        return <p className="text-sm text-graphite-400 dark:text-slate-500">{emptyLabel}</p>;
    }

    return (
        <div role="group" aria-label={ariaLabel} className={cn('flex flex-wrap gap-2', className)}>
            {options.map((o) => {
                const id = o[idKey];
                const isOn = selected.includes(String(id));

                return (
                    <button
                        key={id}
                        type="button"
                        onClick={() => toggle(id)}
                        disabled={disabled}
                        // `aria-pressed` rather than a checkbox role: these
                        // are toggle buttons, and a screen reader should
                        // say "Safety Harness, pressed".
                        aria-pressed={isOn}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                            'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-1',
                            isOn
                                ? 'border-brand-600 bg-brand-600 text-white shadow-[0_1px_3px_-1px_rgba(33,102,196,0.5)]'
                                : 'border-graphite-200 bg-white text-graphite-700 hover:border-steel-300 hover:bg-steel-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800',
                            disabled && 'cursor-not-allowed opacity-60'
                        )}
                    >
                        {isOn && <Check className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />}
                        {o[labelKey]}
                    </button>
                );
            })}
        </div>
    );
}
