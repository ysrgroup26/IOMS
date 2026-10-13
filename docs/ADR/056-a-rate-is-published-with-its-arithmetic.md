# ADR 056 — A frequency rate is published with its arithmetic, or not at all

- **Status:** Accepted
- **Date:** 2026-10-13 (v2.116.0)
- **Amends:** [ADR 044](044-management-workspace-and-admin-space.md) § "Nothing is invented", which
  recorded that IOMS publishes no TRIR. That remains true of the **Management** workspace; it is no
  longer true of the **HSE Overview**, and this ADR says why the difference is deliberate.

## Context

IOMS has refused to publish TRIR and LTIFR since v1.11.6, in four separate places, for three stated
reasons: no recordability classification, no lost-time days, and exposure hours that cover employees
rather than everyone on site. The refusal was right when it was written. Two of its three reasons
have since stopped being true, and nobody went back:

| Stated reason | Still true? |
|---|---|
| No recordability classification | **No.** `incidents.injury_severity` has existed since v2.73.0, holding one of `none`, `first_aid`, `medical_treatment`, `restricted_work`, `lost_time`, `fatality` — exactly the classification a recordable numerator needs. The claim outlived the column by six releases. |
| No lost-time days | **True, and irrelevant to these two.** Lost DAYS are the input to a severity rate. A frequency rate counts CASES. |
| Exposure hours are partial | **True**, where a contractor's workers are not in the workforce register or their hours are never logged. |

So the honest position was no longer "these cannot be computed". It was "these can be computed, from
real inputs, with one reservation about the denominator" — and the owner asked for them on the
Overview.

The danger in publishing them is real and specific. A safety rate is quoted. It goes into a board
pack, a client prequalification and a tender response, usually by somebody who did not see the
screen it came from. If the numerator silently included first-aid cases, or the denominator silently
excluded half the site, the number is still quoted — and it is now wrong in a document IOMS never
sees.

## Decision

**A rate is shown with its arithmetic visible, or it is not shown at all.**

### 1. Every input is on screen, next to the number

The Incident Frequency Rates panel prints, for each rate: the multiplier (×200,000, ×1,000,000), the
case count that forms the numerator, which classifications that numerator is made of
(`FAT + LTI + RWC + MTC` for TRIR, `LTI + FAT` for LTIFR), the period, and the exact man-hours
figure it divided by. A reader can therefore check the number without leaving the page, and a reader
who disagrees with the convention can see which convention was used rather than guessing.

### 2. The numerator is the classification a human recorded, never an inference

IOMS does not derive a classification. The five counts come from `injury_severity` as the reporter
selected it, they are disjoint because an incident carries exactly one, and an incident with no
classification is counted in none of them — and is reported separately as unclassified, because a
gap in the data is the reason a count looks low and hiding it is how a dashboard becomes reassuring
instead of true.

**Recordable excludes first aid.** That is the one judgement in the whole calculation, which is why
it is stated in the controller, in the panel, and in the Accident Classification card's own footer.

### 3. No denominator means no answer, and never a zero

`sumManHours()` already answers `null` rather than `0` for a period nobody logged. The rates inherit
that distinction exactly: with no exposure hours the panel says the rate cannot be calculated and
names the missing input. **"TRIR 0.00" on a site that has logged no hours is the most dangerous
output this panel could produce** — it reads as a perfect safety record, and it would be quoted as
one.

### 4. The reservation that is still true is printed, not buried

Under the rates, in the reader's own language: the denominator is the man-hours recorded this year,
and hours that were never logged, or worked by somebody not in the workforce register, are not in
it. That is ADR 044's surviving objection, answered by disclosure rather than by silence.

### 5. Classification and rate stay in separate cards

A count of cases and a rate per exposure hour are different measures. Shown as one block of eight
numbers, one will eventually be quoted as the other. They are two cards, each with its own period
and its own footer, and the Man-Hour panel that holds the denominator sits beside them so the
reading order is numerator, rate, denominator.

### 6. Management still publishes no rate, and that is not an inconsistency

ADR 044's refusal stands for the Management workspace, which answers "how is the company doing" to a
reader who is not an HSE specialist and who has no panel of inputs in front of them. The HSE
Overview answers "what is our safety performance" to the person who files the incidents and logs the
hours — and who can see, on the same screen, the classification counts and the exposure base the
rate was built from. The same number is responsible in one context and not in the other; the
difference is whether its inputs are visible.

## Consequences

- `safetyKpi.recordable_injuries_ytd` changed meaning, and the old meaning was wrong. It counted
  every incident of CATEGORY `injury` under the label "Recordable Injuries", so it included every
  first-aid case. It now counts FAT + LTI + RWC + MTC. Any tenant with first-aid cases recorded will
  see this figure drop, and the smaller number is the correct one.
- `safetyKpi.lost_time_metrics_available` is gone. It was a boolean that only ever said `false`; the
  rates now carry their own `available` flag with a reason attached.
- A tenant that logs no man-hours sees the classification counts and no rates, which is the correct
  output and is what most tenants will see first.
- The rates are YEAR TO DATE and say so. A selectable period is not implemented; adding one means
  deciding what a rate over a partial month means, which is a bigger question than this change.

Pinned by `HseOverviewDashboardTest`: the classification mapping, that the five counts partition the
cases, that an unclassified report is counted nowhere and reported, that recordable excludes first
aid, that a rate is null rather than zero without exposure hours, and the two formulas with worked
numbers.
