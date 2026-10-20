import { useId } from 'react';
import { cn } from '@/lib/utils';

/**
 * v2.128.0 -- A SINGLE-LINE FIELD WHOSE EXAMPLE FITS INSIDE THE BOX.
 *
 * THE DEFECT. Two PTW fields carried a long worked example as an
 * `<input placeholder>`:
 *
 *     e.g. Hull Block A-12 / Crusher Line 2 Overhaul / Tower Crane Erection
 *
 * An `<input>` placeholder is ONE LINE and is clipped at the right edge --
 * on a phone the reader saw "e.g. Hull Block A-12 / Crush" and nothing
 * else. The example was therefore repeated UNDER the field as helper text,
 * so the same sentence appeared twice, once broken and once whole, and the
 * field grew a three-line footer on the smallest screen it had.
 *
 * WHY NOT THE OBVIOUS FIXES.
 *
 *   - A shorter example. The example IS the help: it names a shipyard, a
 *     mining and a construction case, because the field means something
 *     different in each.
 *   - A fixed taller input. Still clips -- the point at which a placeholder
 *     runs out of room depends on the viewport, the font and the text, and
 *     a height guessed in advance is wrong on some phone.
 *   - A `<textarea>` with the placeholder on it, autosized by scrollHeight.
 *     This is the standard trick and it does NOT work here: the
 *     scrollHeight of an EMPTY textarea is one row regardless of how long
 *     its placeholder is, so the placeholder -- the only thing on screen at
 *     that moment -- is exactly what stays clipped.
 *
 * HOW IT ACTUALLY WORKS. A mirror element, which is the oldest autosize
 * technique and the only one that can measure text the field is not
 * holding. An invisible div renders the value, or the placeholder when
 * there is no value, with the SAME typography, padding and width; the
 * field is laid over it. The box is therefore as tall as its own contents
 * in CSS, with no measuring in JavaScript, no resize listener and nothing
 * to re-run on a font swap or an orientation change.
 *
 * IT IS STILL A SINGLE-LINE FIELD. The element is a `<textarea>` purely
 * because a textarea's placeholder wraps and an input's does not. Enter
 * cannot insert a newline, and newlines arriving by paste are collapsed to
 * spaces, so the submitted value is what an `<input>` would have submitted.
 * Validation, errors and the surrounding form are untouched.
 */
export default function ExampleInput({
    value,
    onChange,
    placeholder = '',
    className,
    id,
    maxLength,
    ...props
}) {
    const generatedId = useId();
    const fieldId = id ?? generatedId;

    // One class string for both layers. They must agree on font size, line
    // height, padding and border width or the mirror measures a different
    // box than the one the reader sees -- the whole mechanism rests on it.
    const shared = 'w-full rounded-lg border px-3 py-1.5 text-[13px] leading-[1.45] lg:text-xs';

    function handleChange(event) {
        /*
         * Paste is the only way a newline reaches this value (Enter is
         * refused below), and a pasted line break would turn a one-line
         * field into a two-line one with an invisible character in it.
         * Collapsed to a single space, which is what pasting a wrapped
         * address into a text input does everywhere else.
         */
        onChange(event.target.value.replace(/\s*[\r\n]+\s*/g, ' '));
    }

    function handleKeyDown(event) {
        if (event.key === 'Enter') {
            // Never a newline. Not submitted either: this field sits in a
            // long form where Enter submitting from one text box would be
            // a surprise, and the form has its own button.
            event.preventDefault();
        }
    }

    return (
        <div className="relative">
            {/*
                The mirror. `invisible` rather than `hidden` or
                `opacity-0`: it must still take up space (that is its whole
                job) but must not be read out, focusable or clickable. The
                zero-width space keeps an empty field one row tall instead
                of collapsing to zero.
            */}
            <div
                aria-hidden="true"
                className={cn(shared, 'pointer-events-none invisible whitespace-pre-wrap break-words border-transparent', className)}
            >
                {value || placeholder || ''}
                {'​'}
            </div>

            <textarea
                id={fieldId}
                rows={1}
                value={value}
                onChange={handleChange}
                onKeyDown={handleKeyDown}
                placeholder={placeholder}
                maxLength={maxLength}
                /*
                 * `enterKeyHint` asks a mobile keyboard to draw "Done"
                 * rather than a return arrow, so the key the field refuses
                 * is not the key the keyboard advertises.
                 */
                enterKeyHint="done"
                spellCheck="false"
                className={cn(
                    shared,
                    'absolute inset-0 resize-none overflow-hidden bg-white text-graphite-900 shadow-sm transition-colors',
                    'border-input dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100',
                    'placeholder:text-graphite-400 dark:placeholder:text-slate-500',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1',
                    'disabled:cursor-not-allowed disabled:opacity-50',
                    className
                )}
                {...props}
            />
        </div>
    );
}
