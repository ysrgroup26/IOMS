---
title: PTW and Field Access Master Revision
type: backlog
status: PARTIAL (v2.126.0) — A–L delivered across two releases; the owner's v2.125.0 rejections are closed in v2.126.0; remaining scope recorded per row
updated: 2026-10-19
tags: [kb/backlog, status/implemented]
---

# PTW and Field Access Master Revision

**Source:** owner directive, 2026-10-19 — one brief covering Permit to Work end to end, field
access, notifications, the PPE import, copy, responsiveness and the document design, plus a stamp
visual reference. **Board:** [[Project Board]] · **Register:** [[Requirements Register]] ·
**Releases:** `2.125.0`, then `2.126.0` (the owner's follow-up review)

This is the ONE master checklist for that directive. Every lettered group below is a row with its
own status and evidence; nothing in the brief is tracked anywhere else. Where a group turned out to
need no change, the check and its evidence are recorded rather than the row being removed.

> [!important] The status words, and what they are allowed to mean
> The directive names seven states. They map onto this vault's own six tags
> ([[Working with This Knowledge Base]]) so one search finds everything:
>
> | Directive status | Vault tag | May be claimed when |
> |---|---|---|
> | Belum dikerjakan | `#status/planned` | Nothing started |
> | Sedang dikerjakan | `#status/in-progress` | Being built now |
> | Terimplementasi, menunggu pengujian | `#status/implemented` | Code exists, suite not yet run on it |
> | Lulus pengujian | `#status/implemented` | Suite green **and** the named tests pass |
> | Menunggu deployment | `#status/implemented` | Released and pushed, not yet deployed |
> | Terverifikasi di produksi | `#status/verified` | The two signatures match (ADR 057) **and** the behaviour was exercised there |
> | Terhambat | `#status/blocked` | Named blocker, named unblocker |
>
> **Nothing below is `Terverifikasi di produksi`.** This release has not been deployed. What was
> done instead is stated per row, and it is a different claim: the suite is green, and the
> behaviour was exercised against **MySQL 8 in a real browser on this machine**. ADR 057 exists
> because the stronger claim was made three times and was wrong three times.

---

## Why this order

Driven by what blocks what, not by the letters in the brief.

1. **D first — the personnel search.** The only item that made the permit form unusable rather than
   untidy, and the root cause turned out to explain why it looked like a data problem. **C depended
   on it**: a default PIC is useless if the selector cannot resolve the name it is handed.
2. **B beside D**, because it touches the same allow-list and the same `canCreatePtw()` gate.
3. **C** next, needing D's lookup to hydrate.
4. **G then E.** Both were fed by one defect — `class_basename()` in the producers — so fixing it
   once served the notification work and the copy audit together. G first because G changes what the
   strings *are*, and auditing copy that is about to be rewritten is wasted work.
5. **J and K after E**, because the stamps render the vocabulary E settles.
6. **F after E**, because overflow is caused by the labels, placeholders and helper text E changes.
7. **H last** — independent of every PTW item, self-contained, and most of its machinery already
   existed.

---

## The follow-up review, 2026-10-19 (v2.126.0)

The owner checked v2.125.0 **by looking at the rendered PDF and by using the import**, and rejected
three of its claims. All three rejections were correct, and the per-group rows below carry the
detail. What they have in common is worth stating once:

| What v2.125.0 claimed | What was actually true |
|---|---|
| "Paper gets the composition without the two effects Dompdf cannot honour" (ADR 065) | **Dompdf renders both.** The claim was written from memory and never tested. The PDF stamp was a plain blue box, exactly as reported. |
| "Branding is supported; the header carries the company identity" | The header carried the company NAME. The logo was gated behind a document template almost nobody creates, and `identity()['name']` defaulted to the product name, so the letterhead read **IOMS**. |
| "The PPE import accepts a company workbook with different column order" | True and useless. The mapping panel offered **two** rows, because the mappable fields are the employee's number and name. Everything that matters in a register was still decided silently, in English. |

> [!important] The lesson, recorded in [[Verification Status]]
> Two of the three were invisible to the test suite and to code reading, and obvious within seconds
> of looking at the artefact. A renderer's limits are a thing to **measure**, in five minutes — a
> limitation asserted from memory becomes a design decision nobody revisits.

---

## A — Obsidian documentation and this checklist

**Asked:** inspect existing docs before implementing; update what is relevant; keep ONE master
checklist, linked to the technical docs, design decisions, PTW workflow, testing and releases.

**Status:** **Lulus pengujian** `#status/implemented`

**Found:** the vault already carried [[Operational Workflows]], [[UX and Design Principles]],
[[Decision Register]], [[Verification Status]] and a `backlog/` folder of per-directive notes in
exactly this shape — so this is a new note, not a new structure, and per-dimension facts stay in
the notes that own them rather than being copied here.

**Done:** this note; two ADRs ([[Decision Register]] rows below); [[Release History]];
[[Verification Status]]; [[Operational Workflows]]; [[UX and Design Principles]];
`docs/CONVENTIONS.md` (one new pitfall).

---

## B — Field access, PTW access and the two quotas

**Asked:** prove *Land in My Work*, *PTW Access* and the My Work account class are three different
things; prove the two quotas are separate; **do not change rules that are already right.**

**Status:** **Lulus pengujian** `#status/implemented` — **no production code changed. This was
already correct.**

**Investigation:** the three concepts are separate in `app/Models/User.php` and documented there
(`is_field_user` = where you land; `user_type` = which class of account; `canCreatePtw()` =
`canManageHse() OR ptw_access`). The two quotas read different columns —
`EntitlementService::myWorkUsersUsedCount()` counts `user_type`, `ptwUsersUsedCount()` counts
`ptw_access` — so enabling PTW Access cannot consume a My Work seat. Submission and approval
authority are already distinct: `canCreatePtw()` gates create/store; the approval transition is
gated by `canManageHse()` and writes `hse_approver_id`.

**Change made:** regression tests only, because the matrix had none.

**Evidence:** `PtwFieldRevisionTest` —
`test_ptw_access_a_landing_preference_and_the_account_class_are_three_separate_facts`,
`test_revoking_ptw_access_leaves_the_class_and_the_landing_preference_alone`,
`test_being_the_creator_and_the_pic_grants_no_approval_authority`.

**Remaining:** a plan **change** (upgrade/downgrade) with seats already in use is still not covered
by a test. Recorded as [[Requirements Register]] work, not claimed here.

---

## C — Default requester, creator and Penanggung Jawab Pekerjaan

**Asked:** a permit raised by a My Work account auto-fills the PIC from the creator's own identity;
a dashboard account with the same grants gets the same default, still editable; requester, creator
and PIC stay distinguishable; no approval authority follows from being the creator.

**Status:** **Lulus pengujian** `#status/implemented`

**Root cause:** `PermitToWorkController::create()` shipped no default for `pic_employee_id` at all —
the field opened empty for every account, so a supervisor on a phone searched the directory for
themselves before they could start. The identity was already on the row: `users.employee_id` is the
employee a My Work account IS (ADR 062), and a full user may carry the same link.

**Change:** `defaultPicEmployeeId` is computed on the server from `$request->user()->employee_id`
and `''` for an account with no employee link — a default, not an assignment; the field stays a
normal editable `EmployeeSelector`. `requested_by` is still set from the session in `store()` and
still has no key in `StorePermitToWorkRequest`, so nothing about this grants approval rights.

**Evidence:** `PtwFieldRevisionTest::test_the_create_form_defaults_the_pic_to_the_creators_own_employee_record`,
`…_an_account_without_an_employee_link_gets_an_empty_default`. Browser: the create form arrives with
"Andi Pratama" in the PIC field, and the submitted permit's document view shows
**Penanggung Jawab Pekerjaan: Andi Pratama / Requester: Andi Pratama / Personnel: Misrani Saputra** —
three slots, correctly distinct.

---

## D — Personnel search returned "Karyawan tidak ditemukan"

**Asked:** find the real cause and fix it; a registered, accessible employee must be findable;
tolerate spacing and capitalisation; **never** fix it by weakening tenant isolation.

**Status:** **Lulus pengujian** `#status/implemented`

**Root cause, and it was never the data.** `EmployeeSelector` fetches `/employee-lookup`
(`employee-lookup.index`). `RestrictMyWorkUser::ALLOWED_PREFIXES` did not contain
`employee-lookup`, so **every** lookup from a My Work account answered **403**. The component
treated a non-OK response as an empty result set (`r.ok ? r.json() : { data: [] }`) and the empty
set rendered as "Karyawan tidak ditemukan." — **a permission refusal displayed as a statement about
the workforce.** People searched harder, because the message told them to. ADR 061's rule, applied
to a data endpoint rather than a link.

**Secondary cause:** `Employee::scopeSearch()` was a single `LIKE %term%`, so it matched only
contiguous text. `"Saputra Misrani"`, `"Misrani  Saputra"`, a leading space from a phone keyboard
and `"Misrani EMP-10244"` each found nobody who existed.

**Changes:** the prefix added to the allow-list (read-only, 50-row-capped, tenant-scoped, no write);
`scopeSearch()` tokenised, every token must match somewhere, AND across tokens; and the component
now distinguishes a refusal, a transport failure and a genuine miss as three different messages.

**Explicitly not done:** the `whereIn('company_id', $tenantCompanyIds)` scope is untouched.

**Evidence:** `PtwFieldRevisionTest` — eight data-provider cases for how people actually type, plus
`test_a_my_work_account_may_read_the_employee_lookup_its_permit_form_depends_on`,
`test_the_lookup_never_reaches_another_tenants_employees`,
`test_an_extra_token_that_does_not_match_narrows_to_nothing`. Browser (MySQL 8, 390px, My Work
account): `/employee-lookup` answers **200, 200, 200**; "Misrani Saputra" is found by `Misrani`,
`Saputra Misrani` and `Misrani  Saputra`; "tidak ditemukan" no longer appears; the selection saves
and shows on the detail, the document view and the PDF.

---

## E — PTW copy audit

**Asked:** never show an internal identifier such as `PermitToWork`; natural professional Indonesian
in the slots that take it; `Workforce` and `Precautions` reconsidered; no em dashes;
`Workforce -- Optional` becomes a real title plus a separate quiet `Opsional` label; statuses
consistent across every surface.

**Status:** **Lulus pengujian** `#status/implemented` — **with a stated remaining scope.**

**Fixed:**
- **Three copies of the class-name leak.** `HasWorkflow::notifyStatusChange()` titled every
  notification `class_basename($this).' '.$number.' is now '.ucfirst($status)`, so a field worker
  was sent **"PermitToWork PTW-2026-00001 is now Submitted"**. `ApprovalEngine` did the same for
  approval requests and escalations. And the third — **found in the browser, not by reading** —
  `transitionTo()`'s default description wrote **"PermitToWork moved from Draft to Submitted."**
  onto the permit's own activity timeline, the surface an auditor opens. `documentLabel()` and
  `statusLabel()` are now the single places that decide both.
- `Workforce -- Optional` → **`Work Personnel`** plus a separate `Opsional` tag.
- A new shared `OptionalTag` (small, grey, baseline-aligned, no colour of its own), adopted by
  `FormField` too so the product cannot word or weight it two ways.
- Field labels in the form, in the Indonesian the directive supplied: `Personel Pekerjaan`,
  `Penanggung Jawab Pekerjaan`, `Bahaya Pekerjaan`, `APD Wajib`, `APD Tambahan di Luar Master`,
  **`Tindakan Pencegahan dan Pengamanan`**, `Kualifikasi yang Diwajibkan`, `HIRADC Terkait`,
  `JSA Terkait`.
- Four `--` sequences in PTW-visible copy rewritten as sentences.

**The language split applied here, deliberately.** The **form** — where a field worker types — takes
the Indonesian equivalents above. The **detail, document view and PDF** keep English captions
(`Work Personnel`, `Work Hazards`, `Precautions & Safeguards`), because they are a controlled
document and `docs/CONVENTIONS.md` assigns titles and column headers to English; the brief
explicitly permits keeping English where it is genuinely more appropriate. Both sides now use the
same *terms*, which is the consistency the brief asked for.

**Remaining, and not claimed:** the audit covered the PTW **form, detail, document view, PDF,
notifications and activity timeline**. The PTW **list** filters, the gas-test and LOTO sub-panels
and the validation messages were read but not rewritten; nothing internal leaks from them, and no
em dash does, but they are stiffer than the form now is.

**Evidence:** `LanguageHierarchyTest` and `CopyRulesTest` green (they read both the JSX literals and
the rendered server props). Browser: the form shows `Work Personnel` + `Opsional`, no
`Workforce -- Optional`; the notification menu reads `Permit to Work PTW-2026-00001 is now
Approved`; the timeline's new rows read `Permit to Work moved from Submitted to Approved.`

---

## F — PTW form and input responsiveness

**Asked:** long placeholders not truncated into uselessness; textareas that grow; single-line inputs
that stay single-line; nothing past the viewport; no horizontal overflow; a mobile keyboard that
does not cover the field or its results.

**Status:** **Lulus pengujian** `#status/implemented` — **with a stated remaining scope.**

**Fixed, each against a measured symptom:**
- **The keyboard covered the results.** `EmployeeSelector`'s list opens directly below its input,
  which on a phone is directly behind the keyboard the tap just raised — so the search looked like
  it returned nothing, indistinguishable from the 403 above, and it would have **survived** that
  fix. The field is now scrolled to the middle of what remains visible, after 300ms, because the
  viewport resize is animated on both mobile platforms.
- **The list could not be reached.** `max-h-64` is 256px on every device; bounded by the viewport as
  well (`max-h-[min(16rem,42vh)]`) and `overscroll-contain`, so scrolling to the last result does
  not start scrolling the page behind it.
- **Textareas grow**, via `field-sizing: content` — the browser doing it natively, no resize
  observer and nothing to get wrong when the font loads late — with `min-h` so the empty state still
  looks like a paragraph and `max-h-[60vh]` so a pasted essay cannot push the submit button off a
  phone. Where unsupported it falls back to `rows` exactly as before. Measured: 72px → **190px** for
  a long work description.
- **A 78-character caveat was living inside a single-line placeholder** on Required Qualification,
  so a phone showed "Contoh: Sertifikat Confined Spac…" and cut off the part that mattered (that
  IOMS does not verify it). Moved to helper text, which wraps.

**Evidence:** browser at **390px**, the full create form with a long description and a selected
colleague: **horizontal overflow = 0px**. The document view at 390px: **0px**.

**Remaining, and not claimed:** measured at 390 and 1440 only. 768px (tablet portrait) and a
landscape phone were not driven, and the gas-test and LOTO panels on the detail page were not
measured.

---

## G — Interactive PTW notifications

**Asked:** notifications must be part of the workflow, not text. Unread more prominent than read;
title, summary, time and the record's identity; clicking opens the matching permit or action; never
open a permit the reader may not access; read state survives a reload; never look interactive
without being so.

**Status:** **Lulus pengujian** `#status/implemented`

**Root cause.** Both producers passed `null` for the url, and the menu navigates only when one is
set. So the whole chain existed except its destination: a permit came back for correction, the
creator was told, they tapped it, it went grey, and nothing opened. **The reported symptom was
exactly true.** Full reasoning and the design: **ADR 064**.

**Changes:** every workflow model (14) declares the route that shows one of its records; the row
carries that route name; the shared prop exposes its prefix; and the menu checks it through the
**same** `isRouteBlockedForUser()` the sidebar and the tiles use, so a row is offered as openable
only when the server would allow it, and one that is not says so and still marks read. Unread now
carries weight and a tinted ground against a deliberately quiet read state, and a returned permit's
reason gets two lines instead of being truncated mid-word — the reason IS the notification.

**Evidence:** `PtwFieldRevisionTest` —
`test_a_status_change_notification_carries_the_permits_own_address` (asserts the url, the stored
route name **and** that the rejection reason is the body),
`test_a_notification_names_the_document_in_the_products_own_words`,
`test_a_multi_word_status_is_rendered_as_language`,
`test_every_workflow_model_names_a_real_destination_route` (resolves every declared name against the
real route table). Browser, My Work account at 390px: the menu reads
`Permit to Work PTW-2026-00001 is now Approved · just now · Buka`, tapping it lands on
`/permits-to-work/1`, and the read state survives a reload.

**Checked and correct already, no change:** a worker is not notified of their own action
(`notificationRecipient()` skips actor == recipient) — browser-confirmed, the menu was empty
immediately after the worker submitted and populated once HSE acted.

---

## H — Flexible Employee PPE import

**Asked:** keep the IOMS template; accept a company's own workbook with different column order;
sheet selection for multi-sheet workbooks; header recognition with a suggested mapping the user can
confirm or override; preview; validation; duplicate explanation; a per-row report; remapping without
editing the source file; catalogue-matched PPE types; an empty date creating no transaction; no
regression to the employee import.

**Status:** **Lulus pengujian** `#status/implemented`

**Already true before this release, and verified rather than rebuilt** (ADR 059/060, v2.120.0 /
v2.121.0): the file is wide, one row per person; an empty cell produces no transaction; PPE columns
resolve against `PpeType::effectiveForCompany()` and never a hardcoded name; a heading matching
nothing is reported by name; duplicates are guarded by type FAMILY; `.csv`, Word and PDF already
arrive through `ReadsImportedSpreadsheets`; column order was already free because Maatwebsite reads
by heading; inspect → preview → import already existed.

**The two real gaps, both fixed:**
1. **No sheet selection.** The shared front door took `$document->primaryTable()` — whichever table
   the locator liked best — and never said which. A company workbook with Data / Rekap / Template
   tabs imported a coin toss decided by confidence and row count, and a summary tab winning that tie
   reported eight rows as the whole file. A **named** sheet is now honoured (through the bridge even
   for `.xlsx`, because Maatwebsite reads the first worksheet unless told otherwise), and a name
   that is not in the file is **refused** rather than falling back — falling back is how a customer
   comes to believe they chose something.
2. **The suggested mapping was applied without being shown.** The dialog posted the matcher's guess
   straight through as `column_mapping[...]`, so a customer could not correct a confidently wrong
   guess and was told to rearrange their file instead. New shared `ImportMappingPanel`: the sheet,
   every sheet's row and column counts, a per-field heading selector with the suggestion shown
   beside it, an explicit "Tidak ada di berkas ini", and the file's unused columns listed. The
   confirmed mapping is what the preview **and** the import run on.

**Evidence:** `FlexibleImportSheetAndMappingTest` (8 tests) —
a **decoy sheet** with more rows and equally clean headings is correctly passed over when the real
one is named; an unknown sheet name is a 422 that writes nothing; no sheet behaves as before; a
mapping over the customer's own reversed headings (`No. Induk`, `Nama Pekerja`) imports; preview and
import agree on a mapping the customer edited; a mapping pointed at the wrong column **reports**
rather than inventing; and a mapping cannot reach another tenant's employees.

### v2.126.0 — the owner used the feature and nothing had changed. They were right.

**Status:** **Lulus pengujian** `#status/implemented` · Full reasoning: **ADR 066**

> "Saya mencoba fitur impor PPE, tetapi dari sisi penggunaan hasilnya masih sama seperti sebelumnya."

Correct, and the reason is instructive. The mappable fields for this import are `employee_id` and
`employee_name` — so v2.125.0's panel offered **two rows**. Everything that actually matters in a
company's register was still decided silently, by matching a heading against a catalogue name **in
English**:

- which column is which equipment,
- which holds a date and which a size,
- which rows are even people,
- who each row is about.

A register whose columns read *Tgl Sepatu Safety*, *Ukuran Sepatu*, *Tgl Baju Kerja* matched nothing.
The interface had grown; the reading had not.

**What was built — a screening, in seven stages, writing nothing until the last.**
`POST /ppe/import/screen` returns the server's whole reading with its reasons, and every correction
is **posted back and re-read by the server**, never patched in the browser — so what the person sees
is always what the server would do, which is the only thing that makes a preview a promise.

| What a real register does | What v2.125.0 did | What v2.126.0 does |
|---|---|---|
| An instructions tab before the data | Opened sheet one | Opens the widest sheet; all sheets listed and switchable |
| Title + blank line above the headings | Locator took a sub-table 10 rows in, reported a worker's NAME as a heading | Reads the **raw grid**; header row guessed and movable (1–8) |
| `No. Induk` | Unrecognised (`comparisonKey` keeps the period) | Employee ID |
| `Tgl Sepatu Safety` / `Ukuran Sepatu` | Neither recognised | Safety Shoes date / Safety Shoes size |
| `FABRIKASI`, `Total` rows | Reported as **missing employees** | Read as furniture, counted, not errors |
| `Baju Kerja` **and** `Coverall` | Only Coverall existed at all | Two different catalogue rows |
| A duplicate name | — | `ambiguous`, with both candidates |

**One reader, shared.** `WorkbookGrid` is used by the screening **and** the import. If the import
found its own sheet and header row, everything the person approved would describe a different file
— the v2.108.1 defect in a larger and more convincing disguise.

**Nothing about the PPE rules moved.** An empty date cell is still not a fact; a type is still
resolved against the company catalogue and never invented; the duplicate guard still resolves a type
FAMILY; a plan arriving from a request has every type id re-checked against the company's own
catalogue so it cannot name another tenant's row; and an import that sends **no plan** reads the
IOMS template exactly as before.

**Evidence — driven end to end through the real dialog, against MySQL 8**, on a workbook carrying
every awkwardness in the table above: 4 employee rows read out of 12, 8 items issued to 3 people,
`Tgl Baju Kerja` → Work Uniform and `Tgl Coverall` → Coverall **as two catalogue rows from one
file**, sizes 42 and 41 recorded against Safety Shoes, `FABRIKASI`/`PRODUKSI`/`Total` absent from
the report, `ZZZ-9999` refused, one unreadable date refused by column **and** row, and — after
pairing `Ukuran Baju` with Work Uniform by hand — sizes **L** and **M** on the uniform issuances,
read back from the database. The IOMS template re-run on a clean slate: 2 rows, 6 items, 0 skipped.
Pinned by `PpeImportScreeningTest` (32 tests).

**A defect of my own, caught in the dialog:** the first cut passed `SpreadsheetDate::parse()`'s
return value to `->format()`, but it already returns a `Y-m-d` string — the screening threw and the
dialog showed the generic "berkas tidak dapat dibaca". Found by uploading a file, not by reading.

### 4.5 — why the uniform choice was only ever Coverall

**Status:** **Lulus pengujian** `#status/implemented`

**Root cause, and it was not the UI.** The catalogue **mechanism** works — PPE Master exposes
`compliance_role`, and a tenant can add a type whenever they like. What it produced in practice is a
shared catalogue whose only work-clothing entry is **Coverall**, because ADR 060 judged a uniform to
be a per-customer item. So an HSE officer issuing a baju kerja had one option and it was the wrong
garment.

**Fixed as master data through the same mechanism Coverall uses**, not as a hardcoded name: `Work
Uniform` joins the shared catalogue with `ROLE_WORK_CLOTHING`, by migration, idempotently, nothing
backfilled, and a tenant's own `Work Uniform` row stays theirs (ADR 058). ADR 060's completeness
rule is **unchanged** — work clothing is satisfied by Work Uniform **OR** Coverall, which is what it
has always said; this is the first release in which the shipped catalogue offers both. A new test
asserts that **either one alone** satisfies the group, so a yard that issues uniforms rather than
coveralls is never told its whole workforce is non-compliant.

**Remaining, and not claimed:** `EmployeeImportDialog` still applies its suggestion without showing
it. The endpoints accept `sheet` and `header_row` and the screening is shared, so adopting it there
is small — but the employee importer is surrounded by ~3,800 lines of regression born of four
consecutive production incidents, and "no regression to the existing Employee Import" was an
explicit requirement of the brief that introduced this work. Tracked as PTW-002 in
[[Known Issues and Limitations]].

---

## J — End-to-end PTW flow and document design

**Asked:** map the real flow; status-label consistency across list, detail, dashboard, notifications
and PDF; never render an enum or class name as a status; a permit header with the company logo,
legal name and address from Admin settings, no banner photo, and never the IOMS logo standing in for
company identity; ACTIVE/CLOSED rules; the applicant as a profile card; verification and approval
from the real action history; **no new status, stage or authority invented.**

**Status:** **Lulus pengujian** `#status/implemented` — **partly "checked, no change needed".**

**The flow, as it actually is** (confirmed by driving it): create (`canCreatePtw()`, quota checked
before the form) → `store()` writes `requested_by` from the session and immediately transitions
draft → submitted through the state machine → HSE approves (writes `hse_approver_id` and
`confirmed_ppe_ids`) or rejects with a required reason → approved → active → closed. One gate, one
approver column. Recorded in [[Operational Workflows]].

**Fixed:** the class name on the activity timeline (see E); ACTIVE and CLOSED stamps on the document
view and the PDF; the approval seal's colour and its job title (see K).

**A real defect in the header, found by looking at the rendered document rather than the code.**
Both views were written as `branding['company_name'] ?? $company->name ?? …`, which is correct. But
`DocumentEngine::branding()` **defaulted that field to the string `'IOMS'`**, so the chain stopped at
the first link: a tenant who had not filled in Settings → Company Name had **the product's name
printed as their own company identity** on a permit they issued — the brief's own rule broken in the
plainest way — while IOMS knew the operating unit's real name one `??` later. The letterhead read
"IOMS" over a permit belonging to "Business Unit".

The default is now `null`, which is what every consumer was already written for, and the last resort
is a shared `product_name` prop from `config/ioms.php` rather than `'IOMS'` typed into a page (the
v2.38.0 lesson). **And fixing it exposed a second one in the same chain**: the PDF footers fell
through to `config('ioms.company')`, which is the **developer's** name — so "Generated by Tahada
Group" would have printed on a customer's permit, material request and waste movement. Those
footers now name the issuing company, and no PDF view reaches for `ioms.company` any more.

Pinned by `PtwFieldRevisionTest::test_an_unset_company_name_does_not_put_the_product_name_on_a_permit`,
asserted on the prop because the same prop feeds the screen, the print view and the PDF.

### v2.126.0 — the header, after the owner looked at the PDF

**Status:** **Lulus pengujian** `#status/implemented`

> "Logo perusahaan pelanggan benar-benar ditampilkan dari pengaturan branding perusahaan, bukan
> hanya nama perusahaan."

Three separate faults, all found by rendering a permit rather than reading the template:

1. **The permit was the one document not using the shared letterhead.** It had its own navy band
   built from `branding()`, whose `logo_url` is an **HTTP URL** — so rendering a permit made an
   outbound request to our own public host while Dompdf waited, and behind a firewall or a wrong
   `APP_URL` drew nothing at all. That is the defect v2.112.0 fixed for every document that uses
   `partials/letterhead`. The permit now uses it: logo from **local disk**, plus the legal name, the
   address, the locality, the contact line and the registry numbers, in the hierarchy every other
   IOMS document already uses.
2. **The on-screen logo was gated behind a document template.** `documentTemplate?.show_logo &&
   branding?.logo_url`, and `documentTemplate` is null until a Company Admin creates one for the
   `permit_to_work` module key — which almost nobody does. A tenant who had uploaded their logo saw
   their company name where their mark should be, and nothing explained why. The template's switch
   still governs; its **absence** no longer does.
3. **`identity()['name']` defaulted to the product name** — the same truthy-default defect found in
   `branding()` one release earlier, in the second of the two methods. The letterhead read **IOMS**
   over a permit belonging to "Business Unit".

**And a duplicated title block went with them:** the sheet rendered "PERMIT TO WORK / HOT WORK /
PTW-2026-00001" twice, a few millimetres apart — obvious the moment the rendered page was looked at,
invisible while only the template was read.

**Evidence:** the rendered PDF shows the customer's logo, `Galangan Nusantara Jaya`,
`PT Galangan Nusantara Jaya`, the address, `Batam, Kepulauan Riau 29444, Indonesia`, the phone/email/
website line and `NPWP 01.234.567.8-901.000`, with the ACTIVE stamp top right inside the type area.
The screen header at 1440px and 390px now carries the same block. `DocumentIdentityTest` gained a
source scan asserting that **no** PDF view reaches for `config('ioms.company')` — the developer's
name, which was the next link in the chain and would have printed "Generated by Tahada Group" on a
customer's permit.

**Checked, already correct, no change made:**
- **The rest of the header.** The address comes from the same branding, and the logo renders **only**
  when `documentTemplate.show_logo && branding.logo_url` — no photo banner, and the IOMS logo is
  never drawn in place of a customer's.
- **The applicant is a profile card, not a stamp** — `PersonChip` (initials avatar, name) in the
  Work Personnel and Authorization sections. Verified in the browser.
- **The PDF status band already names states in words** (`Active: Work In Progress`,
  `Awaiting Approval`, `Draft: Not Valid For Work`), never the enum.
- **The approval record was already honest** — `authorizationFor()` returns `null` unless the permit
  is genuinely authorised, and reads the approver's name from the `ActivityLog` row for the
  approving action rather than the live relation (v2.100.0). That contract was joined, not replaced.

**Not done, deliberately:** no HSE-verification stage was created. PTW has one gate; the brief
forbids inventing authority. See **ADR 065**.

**Remaining, and not claimed:** label consistency was checked on the form, detail, document view,
PDF, notifications and timeline. The **dashboard** cards and the PTW **list** filter chips were read
(no internal identifiers, no enums) but not re-driven in the browser after this release's changes.

**One cosmetic flaw seen and deliberately left:** the document view's section numbers can SKIP.
Section 03 (Hazards & Required PPE) renders only when the permit has hazards or required PPE, so a
permit with neither shows 01, 02, 04, 05 — and a numbered section that skips a number reads, on a
controlled document, as a missing page. Recorded in [[Known Issues and Limitations]] rather than
fixed, because numbering the sections dynamically touches the screen, the print view and the PDF and
is not what this directive asked for.

---

## K — Stamp components and per-company job titles

**Asked:** build the reference as reusable components (SVG/CSS, not a pasted picture); firm stamps on
desktop and PDF, compact badges on mobile; job titles never hardcoded to "HSE Officer" or "Manager"
but read from the company's own configuration and representing who actually acted; old permits
unaffected by a later configuration change; nothing faked when an action has not happened.

**Status:** **Lulus pengujian** `#status/implemented`

**Built:** `DocumentStamp.jsx` — two families (state: `active`/`closed`, slanted, double-ruled,
distressed, **no date**; action: `verified`/`approved`, upright, iconed, naming person + title +
time), a `compact` variant for mobile, and `DocumentStandingMark` which picks between them in CSS
rather than with `useMediaQuery` (a document view is also printed). `ApprovalStamp` became the
PTW-specific door onto it and kept its no-boolean contract. The PDF gets the composition without
the slant and the distress, because Dompdf can honour neither.

**The title gap, and the fix.** `authorizationFor()` returned `$approver->roleLabel()` — the IOMS
**system role** ("Super Admin", "HSE"), not a job title. The per-company title already existed as
`positions.name`, reachable from the account through `users.employee_id`. A new nullable
`activity_logs.actor_title` captures it **at action time** (not backfilled), so a company
reorganising afterwards cannot rewrite a permit already signed — the identical defect v2.100.0 fixed
for the approver's *name*. Read order: stored title → the approver's current position → the system
role → nothing at all rather than something invented.

**One defect of my own, caught in the browser:** the first cut's `compact` action stamp dropped the
title, so the most-visited surface (the detail page) left out the one fact this work exists for.
Compact now means smaller, not less true.

**Evidence:** `PtwFieldRevisionTest::test_the_approval_stamp_shows_the_companys_own_job_title`
(asserts `SPV HSE`, not `HSE`) and
`…test_renaming_a_position_later_does_not_rewrite_a_signed_permit` (renames the position afterwards
and asserts the permit still says `Manager Shipyard`). Both approve through the **real endpoint**,
because that is the only context in which the acting account's company resolves — driving the model
directly would have passed while production stored nothing. Browser: `APPROVED` + `SPV HSE` on both
the detail and the document view; the `ACTIVE` standing stamp on the document view; 0px overflow at
390px; the PDF 8,053 bytes; `activity_logs` holding `actor_title = 'SPV HSE'` on the approving row
and `'Welder'` on the requester's.

### v2.126.0 — the owner rejected this and was right

**Status:** **Lulus pengujian** `#status/implemented`

> "Stamp approval masih berupa kotak biru biasa dengan tulisan APPROVED."

Exactly so. v2.125.0 drew the stamps as a React component and gave the PDF a plain bordered box, on
the stated belief that Dompdf could honour neither a rotation nor an ink texture — **a belief that
was never tested and is wrong.** Dompdf 3.1.5 renders `transform: rotate()` and renders SVG through
php-svg-lib, both confirmed by rendering a probe PDF and looking at it. ADR 065 now carries the
correction at the top.

**What was built:** `App\Services\Documents\StampRenderer` draws every stamp **once**, as SVG, and
the **same data URI** is embedded by the PDF and handed to the Inertia page. Not two
implementations agreeing by convention — one implementation, so there is nothing to drift. v2.125.0
had a React component and a Blade box and they had already diverged by the time anyone looked at
both.

Three constraints, each **measured against a probe** rather than assumed:

| Measured | Consequence |
|---|---|
| `Helvetica, Arial, sans-serif` → **serif**; `Arial` → serif; `Helvetica` → sans but **not bold** | Only the generic `sans-serif` is used |
| `letter-spacing` **ignored outright** | Tracking is applied by positioning each glyph, so screen and paper track identically |
| No filters, masks or gradients | The wear is plain white rectangles at low opacity |

The distress is **deterministic**, seeded from the stamp's own text: one permit printed twice is one
document, and on a controlled document a difference is something somebody has to explain.

**Evidence:** rendered PDF inspected at 150dpi — ACTIVE slanted green distressed with the double
rule and no date, CLOSED the same in red, APPROVED in blue carrying `Sri Handayani / SPV HSE /
19 Oct 2026 21:34`. On screen at 1440px the identical SVG; at 390px the compact pill. Pinned by
`DocumentStampRenderingTest` (10 tests), which asserts the negatives: no stamp for any status that
is not a standing, no action stamp without an actor, no placeholder for a missing title, nothing
Dompdf cannot render, and determinism.

**Two defects of my own, caught by looking at the render:** the icon overlapped the first letter of
the word (fixed by centring the mark and the word as one lockup), and the wear struck through
"MANAGER SHIPYARD" and half of "HSE" (fixed by thinning the speckle below the rule — wear must not
eat the evidence it exists to present).

**Remaining, and not claimed:** the `verified` (green) variant exists and **renders nowhere**,
because PTW has one approval gate and no verification action record to render it from. That is the
honest outcome, not an oversight — and inventing a stage to give the design somewhere to put green
is what the brief forbids. A monochrome print was not tested; the PDF was generated and inspected
as a file, not printed.

---

## I and L — Testing, release, verification and completeness

**Asked:** real fixes rather than audits; automated and regression tests; permissions, tenancy,
quotas, flow and error handling; desktop and mobile; suite and build per the project workflow; the
checklist updated from actual results; commit and push; and **no claim that production is fixed
before deployment and verification.**

**Status:** **Menunggu deployment** `#status/implemented`

**v2.126.0:**

- **Suite:** `vendor/bin/phpunit` — **1521 tests, 7797 assertions, green**. 44 tests are new
  (`PpeImportScreeningTest` 32, `DocumentStampRenderingTest` 10, plus two on either work-clothing
  type satisfying the group and on the PDF letterhead chain).
- **Build:** `npm run build` clean.
- **Migrations:** both applied on MySQL 8 — `Work Uniform` into the shared catalogue, and
  `employee_ppe.size`.
- **Browser / render:** the permit PDF rendered and inspected at 150dpi; the document view and the
  detail page at 1440px; the document view at 390px; and the whole PPE import flow driven through
  the real dialog with a database read-back.
- **Four of my own defects were found this way and not by the suite:** a `ReferenceError` that
  blanked the document view, a `->format()` on a string that broke the screening, the stamp icon
  overlapping the word, and the ink wear striking through the approver's title.

**v2.125.0:**

- **Suite:** `vendor/bin/phpunit` — **1476 tests, 7612 assertions, green**, on SQLite (the suite's
  own connection). 31 tests are new (`PtwFieldRevisionTest` 23, `FlexibleImportSheetAndMappingTest`
  8).
- **Build:** `npm run build` clean; both new arbitrary utilities (`field-sizing:content`,
  `max-h-[min(16rem,42vh)]`) confirmed present in the emitted CSS.
- **Migration:** applied on a **fresh MySQL 8 database**, full history, no failure;
  `activity_logs.actor_title` confirmed as `varchar(255) NULL`.
- **Browser:** MySQL 8, four seeded plan tenants, a My Work account created through the real
  service, at **390px and 1440px**. What was driven is listed per row above.
- **Production:** **not deployed and not verified**, for either release. Per ADR 057 the only honest
  claim after a deploy is "the two signatures match", and no deploy has happened.
  [[Verification Status]] records this.

---

See also: **ADR 064** (`docs/ADR/064-a-notification-is-a-destination-not-a-sentence.md`) ·
**ADR 065** (`docs/ADR/065-a-stamp-is-an-action-record-and-its-title-belongs-to-the-company.md`) ·
[[Operational Workflows]] · [[UX and Design Principles]] · [[Security Decisions and Lessons]] ·
[[Decision Register]] · [[Verification Status]] · [[Requirements Register]] · `docs/MODULES.md` ·
`docs/ADR/061-an-offer-is-bound-to-what-the-server-allows.md`
