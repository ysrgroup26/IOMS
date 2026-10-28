import * as React from 'react';
import { cn } from '@/lib/utils';

/**
 * v2.130.0 -- `uppercase`: a field whose business rules say so, and no other.
 *
 * Some values in this product are IDENTIFIERS -- a badge number, an
 * operating unit's code, a shift letter, a warehouse code. They are printed
 * on documents, matched on import, and read back by people; the convention
 * everywhere in IOMS is upper case, and asking somebody on a phone to find
 * Caps Lock for "EMP-0001" is asking them to do the system's work.
 *
 * SO IT IS OPT-IN, PER FIELD, and the exclusions are as deliberate as the
 * inclusions: never a person's name, an email address, a password, an
 * address or free text, and never a value whose case carries meaning -- a
 * regulatory waste code is `B105d` by law, and a machine key is
 * `used_oil` because that is the key. Anything where capitalisation is the
 * person's to choose is left alone.
 *
 * THE CURSOR IS THE WHOLE DIFFICULTY. A controlled React input re-renders
 * with the transformed value, and a browser handed a new `value` can drop
 * the caret to the end -- so typing "GAJ" into the middle of a code sends
 * the caret to the end on every keystroke. Two things prevent that here:
 * the value is transformed ON THE EVENT (so React never sees a mismatch
 * between what was typed and what is set), and the selection is captured
 * and restored in a layout effect, before the browser paints.
 *
 * Transforming the event also means every CALLER is consistent for free:
 * `onChange={(e) => setData('code', e.target.value)}` posts the uppercase
 * value, so the form state, the request and the stored row all agree
 * without a single call site changing shape. The server normalises the
 * same fields again -- a paste into a disabled JavaScript environment, an
 * API client or an import must not be able to store a lower-case code that
 * then fails to match.
 */
const Input = React.forwardRef(({ className, type = 'text', uppercase = false, onChange, ...props }, ref) => {
    const innerRef = React.useRef(null);
    const caret = React.useRef(null);

    // Keep both the forwarded ref and the local one pointing at the element.
    const setRefs = React.useCallback((node) => {
        innerRef.current = node;

        if (typeof ref === 'function') ref(node);
        else if (ref) ref.current = node;
    }, [ref]);

    React.useLayoutEffect(() => {
        if (!caret.current || !innerRef.current) return;

        const [start, end] = caret.current;
        caret.current = null;

        try {
            innerRef.current.setSelectionRange(start, end);
        } catch (error) {
            // Some input types (email, number) refuse selection calls. The
            // value is still correct; only the caret is the browser's.
        }
    });

    function handleChange(event) {
        if (uppercase) {
            const element = event.target;
            const next = element.value.toUpperCase();

            if (next !== element.value) {
                // Same length, so the caret positions stay valid -- which is
                // what makes editing the middle of a code work.
                caret.current = [element.selectionStart, element.selectionEnd];
                element.value = next;
            }
        }

        onChange?.(event);
    }

    return (
        <input
            type={type}
            ref={setRefs}
            onChange={handleChange}
            /*
             * `autoCapitalize`/`autoCorrect` ask a mobile keyboard to stop
             * fighting the field: without them an iPhone offers lower-case
             * suggestions for a value it is about to be given in capitals.
             * `spellCheck` off for the same reason -- a code is not a word.
             */
            autoCapitalize={uppercase ? 'characters' : undefined}
            autoCorrect={uppercase ? 'off' : undefined}
            spellCheck={uppercase ? false : undefined}
            // v1.11.12 (Final Visual Design System pass): was h-8(32px)
            // shrinking to lg:h-7(28px) -- under this pass's exact "Filter/
            // Search height: 36px" target, and in the wrong direction (spec
            // wants the SAME height on desktop, not a further desktop-only
            // shrink). h-9(36px) uniform; horizontal padding bumped
            // px-2.5(10px)->px-3(12px) to match the spec's own number exactly.
            className={cn(
                'flex h-10 w-full rounded-lg border border-input bg-white/90 px-3.5 py-2 text-[13px] text-navy-900 shadow-[inset_0_1px_2px_0_rgba(30,64,120,0.04)] transition-[border-color,box-shadow] duration-fast ease-out-soft dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100',
                'file:border-0 file:bg-transparent file:text-sm file:font-medium',
                'placeholder:text-graphite-400 dark:placeholder:text-slate-500',
                'outline-none hover:border-steel-300 focus-visible:border-brand-400 focus-visible:bg-white focus-visible:ring-4 focus-visible:ring-brand-500/15 dark:hover:border-slate-600',
                'disabled:cursor-not-allowed disabled:opacity-50',
                // An identifier reads better set as one: slight tracking,
                // and the monospaced face the product already uses for
                // codes, so a 0 cannot be read as an O.
                uppercase && 'font-mono tracking-[0.04em]',
                className
            )}
            {...props}
        />
    );
});
Input.displayName = 'Input';

export { Input };
