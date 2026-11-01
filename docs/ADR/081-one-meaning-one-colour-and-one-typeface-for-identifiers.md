# ADR 081: One meaning, one colour; identifiers in the interface typeface

- Status: accepted (v2.138.0, on branch `claude/eloquent-newton-pbxxdg`; not deployed until approved)
- Refines: ADR 079 (typography), ADR 080 (semantic tints and capsules)

## Context

A design-system audit after v2.137.0 rendered all 125 signed-in screens and read the COMPUTED font
of every visible text element (not just the source). Everything was Manrope except 65 elements in
IBM Plex Mono -- equipment IDs, waste record numbers, permit numbers, document references -- plus 8
elements at weight 800. The colour audit found the same meaning painted differently from page to
page: "in progress" green (success) on Tasks and the shared badge, "expiring soon" blue on
Competency and grey on PPE, a high priority amber on one register and red on Tasks, a submitted PPE
replacement request green, a pending approval grey, and violet used for things that are not a
category (a material-request queue, open CAPA, notification approvals, the selected calendar day).
Groups of KPI cards lit every card in a different colour, including cards whose value was 0.

## Decisions

1. **Identifiers people read are set in Manrope** (`.type-code`: tabular, lining figures, light
   tracking). Monospace stays only for machine output: Master Admin release signatures and database
   names, the organisation slug, configuration keys in `<code>`, the My Work access code (0 vs O),
   and the printed permit page (it matches the PDF, ADR 065/070).
2. **Weights:** 400 body, 500 labels and navigation, 600 emphasis, 700 titles and headline figures.
   800 is no longer used in the application.
3. **Numerals:** tables, inputs, badges, counters and identifiers are tabular. A single headline KPI
   figure stays proportional (ADR 079 §3: "11" set tabular reads as "1 1" at 24-30px).
4. **One status-to-tone map**, `resources/js/lib/semantics.js` (`STATUS_TONE`, `statusTone()`).
   `StatusBadge` and every page that kept its own copy (Tasks, Projects, PPE assignments and
   replacement requests, Competency, Employee profile, Waste records) read it. Tones: informational
   blue (underway), success (done / good standing), warning (awaiting a decision, due soon, high),
   critical (expired, overdue, rejected, critical), neutral (draft, closed, archived, cancelled).
   Stored values are untouched; an unmapped value is neutral until someone decides its meaning.
5. **A KPI card wears its semantic colour only while its number means something.** Warning,
   critical and category accents fall back to neutral at zero (`StatCard`, HSE `OperationalKpiCard`).
   A green "0" on a compliance figure stays: it is the deliberate "green is a claim" rule (v2.109.0).
6. **Violet is a category, not decoration.** Kept for B3 waste and a chart series; removed where it
   meant nothing (material requests -> blue, open CAPA -> amber, notification approvals -> amber,
   selected calendar day -> brand blue, superseded regulations -> neutral, procurement value -> blue).

## Deliberate typography exceptions

- Email templates (system font stack: mail clients cannot load the bundle).
- The boot splash in `app.blade.php` (renders before the bundle and its font exist).
- The QR label print window (`QrLabel.jsx`), a bare document opened for the printer.
- PDFs (dompdf embeds static Archivo/Plex instances, ADR 070).
- The machine-output monospace list in decision 1.

## Left for a product decision (not changed)

- "Due in 30 Days" / "Coming Due" describe scheduling, not a standing; the standing is "Due Soon".
- PPE completeness "Incomplete" is critical (red); a tenant may prefer warning while issuance is
  being entered.
- "Disposed / Closed" is one waste figure; disposed is a success, closed a neutral archive.
- Master Admin (platform) pages keep their own status maps; they are operator tools outside the
  four workspaces and were not re-toned.
- Whether a single headline KPI figure should be tabular after all (decision 3).
