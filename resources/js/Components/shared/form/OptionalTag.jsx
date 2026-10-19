import { cn } from '@/lib/utils';

/**
 * v2.125.0 -- "OPSIONAL" AS A LABEL BESIDE THE TITLE, NEVER PART OF IT.
 *
 * The PTW form carried the card title `Workforce -- Optional`, which got
 * three separate house rules wrong in one string: an em dash in user copy
 * (`CopyRulesTest`), a field's requirement state welded into the name of
 * the section, and the optionality shouting at the same weight as the
 * title it qualifies. A reader scanning the form read "Optional" as
 * loudly as "Workforce".
 *
 * Optionality is METADATA about a field, so it is set like metadata:
 * small, grey, lower-case-weighted, baseline-aligned beside the title it
 * belongs to. It carries no colour of its own, because nothing about a
 * field being optional is a warning or a success.
 *
 * Shared rather than inlined because it now appears in two places that
 * must agree -- `FormField`'s own label row and the operational forms
 * that build their section headings by hand -- and a second copy of a
 * string the product shows everywhere is a second chance to drift.
 */
export default function OptionalTag({ className }) {
    return (
        <span className={cn('text-[11px] font-normal leading-none text-graphite-400 dark:text-slate-500', className)}>
            Opsional
        </span>
    );
}
