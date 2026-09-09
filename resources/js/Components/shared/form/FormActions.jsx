import { Loader2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * v2.65.0 -- THE SAVE BUTTON YOU CAN ALWAYS REACH.
 *
 * No form in the product had a sticky action area. On a twenty-field
 * master-data form -- and on a phone, on almost any form -- the primary
 * action sat below the fold, so completing a form ended with a scroll to
 * find the button that finishes it.
 *
 * On mobile this bar sticks to the bottom of the viewport. On desktop it
 * does not: there the whole form is usually in view, a permanently
 * floating bar would cover content for no benefit, and the convention a
 * desktop user expects is an action at the end of the thing they are
 * filling in.
 *
 * `safe-area-inset-bottom` is respected so the bar clears the home
 * indicator on modern phones instead of sitting under it.
 *
 * DESTRUCTIVE ACTIONS ARE SEPARATED, NOT STYLED DIFFERENTLY AND PLACED
 * ADJACENT. `destructive` renders hard left, away from Save, with the
 * cancel/submit pair grouped right. Putting Delete next to Save and
 * relying on colour to prevent a mistake is how mistakes happen.
 *
 * ONE PRIMARY ACTION, and a `secondary` slot only where a form genuinely
 * has TWO OUTCOMES. There is deliberately no "Save & add another" /
 * "Save & continue" convenience variant -- those make the common case
 * slower to read for no change in result. But Material Request really
 * does end in one of two different states, Draft or Submitted, and
 * flattening that into one button would hide a decision the user has to
 * make. The test is whether the two buttons leave the record in different
 * states, not whether one saves a click.
 */
export default function FormActions({
    submitLabel = 'Save',
    cancelHref,
    onCancel,
    processing = false,
    disabled = false,
    destructive,
    secondary,
    note,
    className,
}) {
    return (
        <div
            className={cn(
                // Mobile: pinned. Desktop: inline at the end of the form.
                'sticky bottom-0 z-20 -mx-4 mt-6 border-t border-graphite-200 bg-white/95 px-4 py-3 backdrop-blur',
                'pb-[max(0.75rem,env(safe-area-inset-bottom))]',
                'sm:static sm:mx-0 sm:rounded-lg sm:border sm:px-4 sm:pb-3 sm:backdrop-blur-none',
                'dark:border-slate-800 dark:bg-slate-900/95',
                className
            )}
        >
            <div className="flex flex-wrap items-center gap-3">
                {destructive && <div className="mr-auto">{destructive}</div>}

                <div className={cn('flex items-center gap-2', !destructive && 'ml-auto')}>
                    {(cancelHref || onCancel) && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onCancel}
                            disabled={processing}
                            asChild={Boolean(cancelHref && !onCancel)}
                        >
                            {cancelHref && !onCancel ? <a href={cancelHref}>Cancel</a> : <span>Cancel</span>}
                        </Button>
                    )}

                    {secondary}

                    <Button type="submit" disabled={processing || disabled}>
                        {processing && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
                        {processing ? 'Saving…' : submitLabel}
                    </Button>
                </div>
            </div>

            {/* "What happens after I save?" -- answered before it is
                asked, on the forms where the answer is not obvious. */}
            {note && (
                <p className="mt-2 text-[11px] leading-relaxed text-graphite-500 dark:text-slate-400">{note}</p>
            )}
        </div>
    );
}
