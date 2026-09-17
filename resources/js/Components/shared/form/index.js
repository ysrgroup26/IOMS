/**
 * v2.65.0 -- THE IOMS FORM EXPERIENCE SYSTEM.
 *
 * Five components and one hook. Deliberately not a framework: the risk
 * with a form layer is that it grows into an abstraction nobody wants to
 * fight, so this stops at the smallest set that closes the gaps the
 * audit actually measured across all 27 module forms.
 *
 *   FormDocumentHeader  what am I creating, and what happens to it
 *   FormSection       a group that explains what it is asking for
 *   FormField         label, requiredness, hint, error -- wired for a11y
 *   FormActions       a save you can always reach; destructive separated
 *   ErrorSummary      what happens when submission fails
 *   SearchableSelect  id-backed picker for lists already in the payload
 *   useUnsavedChanges (lib/useUnsavedChanges) don't lose somebody's work
 *
 * WHAT THIS DOES NOT DO, on purpose: it does not own validation rules, it
 * does not describe forms as data, and it does not wrap Inertia's
 * `useForm`. Each form still declares its own fields in JSX and posts
 * through the same backend contract it always did. This layer changes
 * how a form BEHAVES and READS, never what it submits or what the server
 * accepts.
 *
 * PAGE FORMS vs DIALOG FORMS (v2.66.0, learned during the master-data
 * rollout). A dialog already has an action area -- DialogFooter -- and is
 * short enough to be read whole, so:
 *
 *   PAGE   FormSection + FormField + FormActions + ErrorSummary
 *   DIALOG FormField only
 *
 * OPERATIONAL FORMS vs MASTER DATA (v2.72.0). A form that creates a
 * record somebody else will act on -- a permit, a material request --
 * also gets FormDocumentHeader: its reference number, who is responsible
 * and what happens after submission. A master-data form does NOT. There
 * is no reference number for an equipment type, nobody is accountable for
 * it, and nothing happens downstream when it is saved; dressing it as a
 * controlled document would say something false about it. Master data
 * keeps PageHeader kind="master", which is the v2.71.0 distinction this
 * deliberately reinforces rather than blurs.
 *
 * Putting FormActions inside a dialog gives it two action bars; putting an
 * ErrorSummary above five visible fields restates what is already on
 * screen. The field contract is the part that generalises to both, which
 * is why the answer was to use LESS of the system rather than to grow a
 * second dialog-flavoured set of components.
 *
 * See docs/UX_ARCHITECTURE_DISCOVERY.md §8 and §20 for the reasoning,
 * Pages/Employees/Form.jsx for the page reference, and Pages/Ppe/Master.jsx
 * for the dialog reference.
 */
export { default as FormDocumentHeader } from './FormDocumentHeader';
export { default as FormSection } from './FormSection';
export { default as FormField } from './FormField';
export { default as FormActions } from './FormActions';
export { default as ErrorSummary } from './ErrorSummary';
export { default as SearchableSelect } from './SearchableSelect';
