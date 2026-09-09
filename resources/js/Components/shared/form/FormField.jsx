import { useId } from 'react';
import { AlertCircle } from 'lucide-react';
import { Label } from '@/Components/ui/label';
import { cn } from '@/lib/utils';

/**
 * v2.65.0 -- THE FIELD CONTRACT.
 *
 * Promoted, not invented. A component doing almost exactly this already
 * existed as a local helper inside `Pages/Public/GetStarted.jsx` -- the
 * PUBLIC signup form -- where it marked required fields, rendered hints
 * and showed errors with an icon. Meanwhile all 27 authenticated module
 * forms hand-rolled `<Label>` + control + `{errors.x && <p className=
 * "text-xs text-red-600">}`, and consequently NONE of them marked a
 * required field at all. The prospect filling in a signup form was
 * better served than the customer using the product.
 *
 * So this is that component moved somewhere both sides can reach, with
 * the accessibility wiring the original did not have.
 *
 * WHAT IT ADDS OVER THE HAND-ROLLED PATTERN:
 *
 *   REQUIREDNESS IS VISIBLE AND PROGRAMMATIC. The asterisk is decorative
 *   (`aria-hidden`); the real signal is `aria-required` on the control,
 *   plus the word "required" in the accessible name. Colour alone is
 *   never the carrier -- an asterisk is a glyph, not a hue.
 *
 *   ERRORS ARE ANNOUNCED, NOT JUST PRINTED. The control gets
 *   `aria-invalid` and `aria-describedby` pointing at the message, and
 *   the message is `role="alert"`. Previously a screen reader user
 *   submitted a form, was told nothing, and had no way to find the bad
 *   field.
 *
 *   THE ANCHOR FOR ErrorSummary. The wrapper carries `data-field`, and
 *   the control gets a stable id, so the summary can focus and scroll to
 *   the offending control by name.
 *
 * WHY children AND NOT A `control` PROP: IOMS forms use Input, Textarea,
 * Select, Combobox, EmployeeSelector, SearchableSelect, checkbox groups
 * and file inputs. Enumerating them would make this component own a
 * registry it has no business owning. It renders whatever it is given and
 * hands down id/aria via a render prop when the caller wants them.
 *
 * HINTS ARE OPTIONAL AND SHOULD STAY THAT WAY. A hint under every field
 * is noise that trains people to stop reading hints. Use one where the
 * answer is genuinely not obvious from the label.
 */
export default function FormField({
    label,
    name,
    required = false,
    error,
    hint,
    className,
    labelFor,
    children,
}) {
    const generatedId = useId();
    const controlId = labelFor ?? (name ? `field-${name}` : generatedId);
    const hintId = hint ? `${controlId}-hint` : undefined;
    const errorId = error ? `${controlId}-error` : undefined;

    // Passed to a render-prop child so a control can wire itself up
    // correctly without every call site repeating the same five props.
    const controlProps = {
        id: controlId,
        'aria-required': required || undefined,
        'aria-invalid': error ? true : undefined,
        'aria-describedby': [errorId, hintId].filter(Boolean).join(' ') || undefined,
    };

    return (
        <div className={cn('min-w-0', className)} data-field={name}>
            {label && (
                <Label htmlFor={controlId} className="mb-1.5 flex items-baseline gap-1">
                    <span>{label}</span>
                    {required ? (
                        <>
                            <span aria-hidden="true" className="text-danger">*</span>
                            <span className="sr-only">(required)</span>
                        </>
                    ) : (
                        // Stated once, quietly, rather than left ambiguous.
                        // A form where only SOME fields are marked leaves
                        // the reader guessing about the rest.
                        <span className="text-[11px] font-normal text-graphite-400">optional</span>
                    )}
                </Label>
            )}

            {typeof children === 'function' ? children(controlProps) : children}

            {hint && !error && (
                <p id={hintId} className="mt-1 text-[11px] leading-relaxed text-graphite-400">
                    {hint}
                </p>
            )}

            {error && (
                <p id={errorId} role="alert" className="mt-1 flex items-start gap-1 text-[11px] text-danger">
                    <AlertCircle className="mt-px h-3 w-3 shrink-0" aria-hidden="true" />
                    <span>{error}</span>
                </p>
            )}
        </div>
    );
}
