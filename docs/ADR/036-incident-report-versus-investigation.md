# ADR 036 — The Initial Report and the Investigation are two records, not one

**Status:** Accepted and implemented (v2.73.0).
**Date:** 2026-09-20
**Supersedes:** the one-to-one `incident_investigations` enhancement shipped in Milestone 4,
Workstream B14 — the table is rebuilt, its data migrated, not dropped.

---

## The decision

**An incident report and an HSE investigation are separate records, in separate workspaces, with
separate numbers, workflows, owners and closure.** They share one source of fact — the report — and
the relationship between them is explicit and visible from both ends.

```
INC-2026-00003          →   INV-2026-00001          →   CAPA
Initial Accident Report     HSE Investigation           Corrective Actions
Reported                    In Progress                 2 still open
```

## What was there before

`incident_investigations` was seven columns hanging off an incident: `method`, `root_cause`,
`findings`, `recommendations`, `investigator_id`, `investigated_at`. No number. No status. No team,
evidence, interviews or review. One `POST /incidents/{incident}/investigation` on
`IncidentController`, reachable only from a card on the incident page.

**The absence of routes was the architecture.** Investigation was modelled as *extra fields on a
report*, and every consequence followed from that:

- there was no way to ask "what are we still investigating" — no index, no query, no state;
- the investigator was whoever had the incident page open, because `investigator_id` was set to
  `$request->user()->id` on save;
- a root cause could be typed and saved in the same minute the report was read, with no analysis
  behind it and nobody reviewing it;
- an investigation could not be open while the incident was closed for reporting purposes, or
  vice versa, because there was only one status.

## Why the split is the right call

These are genuinely different activities, not two views of one thing.

| | Initial Report | HSE Investigation |
|---|---|---|
| Filed by | whoever was there | a trained investigator, named deliberately |
| When | minutes after the event | over days or weeks |
| Answers | what happened, to whom, what was done | why it happened, and what must change |
| Optimised for | **speed and accuracy** | **rigour** |
| Ends when | it is submitted | findings are reviewed and actions are verified |

A form optimised for one is wrong for the other. A report that asks for a root cause at the scene
gets a guess — and that guess is later quoted as a finding. An investigation that has to be filed in
one pass gets filed empty.

### The report is the source of fact, and is not editable from the investigation

`IncidentInvestigationController` deliberately cannot modify the incident. An investigator revising
the report weeks later destroys the very thing they are working from. Where the investigation
disagrees with the first telling, it says so in `detailed_chronology` and `findings`, where the
disagreement is visible and attributable — and **both accounts are kept**.

### The causal chain is three fields, not one

`immediate_causes` → `basic_causes` → `root_cause`, plus `contributing_factors`.

A single "root cause" textarea gets *"operator error"* written in it. Asking separately for what
directly produced the harm, what conditions allowed it, and what system failure let those conditions
exist makes the shortcut visible: **if the immediate cause and the root cause say the same thing,
the analysis has not happened yet.**

### Witnesses are JSON on the report and rows in the investigation

Not an inconsistency — the same person at two levels of formality, which is the whole shape of this
ADR. On the report a witness is a name and a phone number written down at the scene, captured once.
In the investigation an *interview* happens on a date, with a named interviewer, produces a
statement that may be contradicted by another one, and "who have we still not spoken to" is a real
question. That is a table (`investigation_interviews`).

## On methodologies, and being precise about compliance

`method` is an optional analysis framework: `none`, `5_why`, `fishbone`, `scat`, `rca`, `other`.

**These are instruments, not legal requirements, and IOMS must not imply otherwise.** To be exact
about what the sources do and do not say:

- **Permenaker No. 03/MEN/1998** requires that workplace accidents be reported and examined.
- **PP No. 50 Tahun 2012 (SMK3)** requires incident investigation and follow-up as part of the
  safety management system.
- **SNI ISO 45001:2018** requires incident investigation and the determination of root causes.

**None of them names 5 Why, Fishbone, SCAT or TapRooT.** What is required is that an investigation
*happens*, is *recorded*, and *leads to action* — which is what the workflow enforces, independently
of which technique the investigator reaches for. `none` is a legitimate and often correct answer: a
straightforward event does not need a technique applied to it to be understood, and forcing one
produces filled-in boxes rather than insight.

Similarly, the initial report captures the **structured facts** an employment-injury submission
asks for, and records a claim reference so the incident can be found from it. It does not generate,
submit or track a claim, and no copy in the product says it does.

## Workflow

```
draft → in_progress → under_review → completed → closed
                            ↓ (sent back)
                      in_progress
```

Two deliberate shapes:

- **`under_review → in_progress` exists.** A reviewer sending work back is the normal outcome of a
  review that found something. A workflow without that path quietly teaches reviewers to approve.
- **`completed` and `closed` are different states.** Findings can be settled while the corrective
  actions they generated are not. Closing while an action is open is the commonest way an
  investigation becomes paperwork, so the UI states the position and the two are distinct.

`IncidentInvestigation::canBeClosedCleanly()` is **advisory, not enforced**. An HSE manager closing
with one action open, knowingly, is a judgement they are entitled to make; a system that refuses
gets worked around by cancelling the action instead, which destroys the record. The product states
the position plainly, a human decides, and the ActivityLog captures what they decided.

## What was deliberately *not* changed

- **No new permission.** Authorization reuses `canManageIncidents()`, unchanged. A separate
  workspace is not a separate authority, and inventing `canInvestigate()` here would fork the HSE
  permission model for no stated reason. Gating investigator competency separately is a product
  decision with its own ADR.
- **No second CAPA system.** Corrective actions raised from an investigation use the same
  polymorphic `CorrectiveAction` entity Incident, Safety Observation and HSE Inspection already use.
- **Still one-to-one.** `incident_id` stays unique: one event, one investigation.
- **No redirecting shim** for the removed `storeInvestigation()` endpoint. Its whole payload (a root
  cause, typed once, with no analysis behind it) has nowhere sensible to land in the new model, and
  silently accepting it would reintroduce exactly the shortcut this ADR removes.

## Consequences

- Existing investigations were migrated with a generated number and a status **derived from their
  content** — one with a recorded conclusion became `completed`, a blank one `in_progress`. More
  faithful than defaulting everything to `draft`, more honest than defaulting it to `closed`.
- The incident's own lifecycle now moves in step: opening an investigation moves a `reported`
  incident to `investigating`; closing the investigation closes the incident.
- `investigations` had to be added to `config/departments.php` in the same change — see
  `docs/CONVENTIONS.md`, where this cost a 403 during verification for the third recorded time.

## What would make this ADR wrong

If investigations in practice turn out to be raised against *several* incidents at once — a pattern
of similar events rather than one occurrence — the one-to-one constraint is the thing to revisit,
not the separation. The separation would still be right; the cardinality would not.
