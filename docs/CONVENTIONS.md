# Conventions & Known Pitfalls

House style, and a deliberately honest list of mistakes that have actually happened in this
codebase's history — kept here so they don't get repeated in a slightly different shape.

## Known Pitfall (v2.113.0): an INNER JOIN in an ORDERING scope is a FILTER nobody reads as one

`Employee::scopeOrderedForDisplay()` INNER JOINed `departments` to sort by the department's display
order. `department_id` is nullable on purpose -- "Unassigned" is a supported state, which is why
`profile_status` reports `needs_completion` -- so that join silently dropped every employee who did
not have one.

It read as an ordering concern, so nobody audited it as a visibility concern. **Nine call sites**
used it: the Employees list, the Excel export, the printed register, the Document Library register,
KPI input, Man-Hour, Projects and PPE twice. The symptom reached a customer as *"the import says it
worked and the list is empty"* -- the rows were in the database throughout.

> [!important] The rule
> A scope whose job is ORDERING must only ever `leftJoin`. If you need an inner join to sort, the
> rows you are excluding are a product decision, and it belongs in a scope with a name that says so.

Order NULLs deliberately rather than by accident: `orderByRaw('CASE WHEN departments.id IS NULL THEN
1 ELSE 0 END')` puts unassigned people last on both MySQL and SQLite, because NULL sorts first on
both and a roster that opens with everybody missing a department is a different kind of wrong.

Pinned by `tests/Feature/UnassignedEmployeeVisibilityTest.php`, which checks the list, the search,
the profile filter, both exports and the library register separately -- the scope is shared, so one
surface passing proves nothing about the others.

## Known Pitfall (v2.113.0): the THIRD unqualified column in the same class

`scopeInDepartment()` was fixed for this in v1.3.2 and `scopeInCompany()` in v2.2.0, each with a
comment explaining that `positions.department_id` and `departments.company_id` exist and make an
unqualified column ambiguous once `scopeOrderedForDisplay()` joins those tables. `scopeProfileStatus()`
and `scopeSearch()` were never fixed, so filtering the Employees list by profile status was a hard
500 on every use, on MySQL and SQLite alike.

When you fix an ambiguous column, **grep the whole class** for the others. Two fixes and two
comments did not stop the third instance shipping.

## Known Pitfall (v2.113.0): a spreadsheet import must read RAW values, not displayed ones

`Row::toArray()` defaults to `$formatData = true`, which runs every cell through
`NumberFormat::toFormattedString()` -- it hands over **what Excel would display**, not what the cell
holds.

Excel stores a 16-digit NIK typed into a General column as a float. Its display is
`3.20101010194E+15`, and PHP's default `precision=14` renders the next NIK -- differing only in the
last digit -- as *the identical string*. So the value was stored as scientific notation **and** the
second person was rejected as a "Duplicate NIK" against somebody they share no digits with past the
eleventh. The precision was gone before the importer saw it, so no amount of normalising downstream
could recover it.

Read with `toArray(null, false, false)` and normalise in PHP, where the precision is yours to
control: `sprintf('%.0F', $value)` writes an integral float out in full, locale-independently. Date
cells then arrive as Excel serials, which `SpreadsheetDate` already handles and which are more
reliable than re-parsing somebody's display format.

## Known Pitfall (v2.113.0): an uploaded file has no extension, so the reader type must be passed

A file arrives at `/tmp/phpXXXXXX` with no suffix, so Maatwebsite cannot infer the format. Passing a
hardcoded `Excel::XLSX` makes a genuine `.xls` (BIFF -- not a zip at all) fail with *"Could not find
zip member #_rels/.rels"*: a 500 with nothing in it a customer could act on. Derive the reader from
the format the content sniffer detected, never from the path and never from a constant.

## Known Pitfall (v2.112.0): forcing a cluster count down welds two real columns together

`PdfExtractor` reconstructs columns from glyph x positions. v2.110.0 added a merge step so that a
**centred heading** and its left-aligned data would not count as two columns, and it merged by
distance until the cluster count matched the **modal fragment count**.

That count can be an underestimate — one row where two neighbouring cells' glyphs came out as a
single fragment is enough — and then the merge destroys a genuine column. On a landscape roster
printed by this very application it produced one cell reading `Produksi Welder`, after which the
heading row was mistaken for data and the import wrote nothing.

The exact test is **co-occupancy**: two clusters that some single row uses at the same time are two
columns, and no distance makes them one. A heading at one x and its data at another are never both
present in one row, so the original fix still applies; two adjacent columns every row fills together
now cannot be merged. When no pair is eligible the merge stops ABOVE the target rather than forcing
it — a stray empty `Kolom N` is visible and ignorable, a welded column is silent corruption.

Pinned by `DocumentImportBridgeTest::test_a_landscape_pdf_with_a_title_block_keeps_its_columns_apart`.

## Known Pitfall (v2.112.0): an image dompdf cannot decode disappears without an error

A tenant logo that `getimagesize()` parses but GD cannot load is dropped from the PDF silently —
no exception, no placeholder, just a document that looks like it never had a logo. This cost half an
hour of chasing the renderer before the fixture turned out to be the problem.

Two consequences worth keeping:

- **Generate image fixtures with GD, never paste a hand-written base64 blob.** A blob that survives
  `getimagesize()` is not proof of a decodable image.
- **Assert on the OUTPUT, not the input.** The release gate checks the produced PDF for an
  `/Subtype /Image` object and the XLSX/DOCX for a `media/` entry, which is the only evidence that
  the logo actually reached the page.

## Convention (v2.112.0): a document renders the tenant logo from DISK, never from a URL

`DocumentEngine::identity()` returns both `logo_url` (for on-screen chrome) and `logo_path` (an
absolute local path, null unless the file is really there and readable). Document generators use
`logo_path`.

A URL makes dompdf fetch over HTTP from our own public host **while rendering** — slow on shared
hosting, and silently blank when the host is unreachable or `APP_URL` is wrong. The three formats
also used to resolve the logo independently, which is how the spreadsheet ended up with none at all.
One source, one existence check, three documents that agree.

## Known Pitfall (v2.111.0): a heading row is often FULLER than the rows beneath it, and a
## width-based "support" signal reads that as a data row

`TableLocator` scored a candidate heading row partly on **support**: how many of the rows beneath
it were about as wide. On the commonest template shape of all — twelve declared columns of which a
customer fills five — the real heading row scored **zero** support, and the first DATA row, whose
neighbours are exactly as sparse as it is, scored full support and won. The located "headings" were
then `EMP-1 | budi santoso | Mechanic | Kolom 4 | …`, and the import wrote nothing.

The relationship that actually defines a heading row is **containment, not width**: data sits in the
columns the headings named. A row supports the candidate when the columns it fills are a *subset* of
the candidate's and it fills at least two of them.

> [!warning] Two green tests went red, and that was the only warning
> This shipped inside a change whose own 13 tests all passed. What caught it was the FULL suite —
> `EmployeeImportProductionDefectsTest`, written for a different release entirely. Run the whole
> suite before believing a parser change is safe; the file you are editing is not where the damage
> shows up.

Pinned by `DocumentImportBridgeTest::test_a_wide_template_with_sparse_rows_is_not_broken_by_the_locator`.

## Convention (v2.111.0): a heuristic may improve a path, never become a new way to lose it

The locator is a measurement, but it is still a heuristic, and when it is wrong about a spreadsheet
the cost is total: the file imported fine before, and the normalisation built from a misread heading
row imports nothing.

So `EmployeeController::withImportSpreadsheet()` takes the bridge for a **spreadsheet** only when
the headings it located map to at least two real IOMS fields (`mappableHeadings()`). Headings that
map to nothing cannot produce a usable import anyway, so the original file is handed over and the
path that worked yesterday still works. CSV, Word and PDF get no such fallback, because for them
there is no older path to fall back *to*.

Generalise it: when you put a heuristic in front of something that already worked, give it a
**fail-open** condition expressed in terms of what the heuristic was FOR.

## Convention (v2.111.0): a reported row number must name a line in the file the user has

`EmployeesImport` reports skipped rows and date warnings by row number, and the dialog prints them
beside "perbaiki pada berkas Anda". Once the Document Platform started normalising a source into a
temporary spreadsheet, those numbers counted rows in a file the customer has never seen — on a
roster whose headings are on row 5, "Row 5 — Missing Full Name" pointed at the heading row.

`EmployeesImport` therefore takes a `rowOffset` that shifts only what is **reported**; nothing about
reading, validating or writing a row depends on it. Any future normalisation must set it.

A wrong line reference beside the words "fix this in your file" is worse than no line reference.

## Known Pitfall (v2.110.0): a PDF's heading row is often CENTRED, and a distance-based column
## clustering reads that as twice as many columns

`PdfExtractor` reconstructs a table from `getDataTm()` glyph positions: fragments sharing a y are a
row, and the x values rows agree on are the columns. That worked on a fixture whose cells were all
left aligned, and produced **twelve columns for a six-column table** the first time it met a real
document — because the `<th>` text was centred inside its cell while the data beneath it was left
aligned, so each column contributed *two* x clusters, one for its heading and one for its data. The
extra ones came back as `Kolom 1`, `Kolom 3`, `Kolom 5`…, every one of them empty.

The fix is not a bigger tolerance, which would merge genuinely adjacent columns on a dense table.
It is to work out **how many columns the rows themselves agree on** (the modal fragment count) and
then agglomeratively merge the closest adjacent clusters down to that number. It never merges below
the expected count and never splits.

> [!warning] A fixture you generated is not a document you received
> This was invisible until the fixture was produced by dompdf — the same engine IOMS itself prints
> with — instead of being hand-built to be easy to parse. If you are testing an extractor, generate
> the fixture the way a real document is produced.

Pinned by `tests/Feature/DocumentImportBridgeTest::test_a_pdf_table_is_recovered_and_reported_as_a_reconstruction`.

## Convention (v2.110.0): a document's rows are materialised ONCE before rendering

`DocumentRenderer::render()` walks `$definition->rows()` into an array before handing it to any
format. A definition is allowed to `yield` — all four shipped ones do, so a large register never
loads whole models into memory at once — and **a generator cannot be walked twice**. The PDF path
iterates the rows for the table and again for the row count; on a generator the second pass yields
nothing and the document prints an empty table under a header that says how many rows it has.

If you add a format to the renderer, take the rows it is given. Do not re-call `rows()`.

## Convention (v2.110.0): a new input format is an extractor, never a second import path

The employee importer holds the day-first date parser, the NIK registry, the employment-type
aliases, the master-data detection and the row-accurate error reporting. There is exactly one of it.

When a format needs importing, write an extractor that produces an `ExtractedTable`, and let
`TabularBridge` normalise it into a spreadsheet the existing importer reads. Writing a second
import path would mean a second copy of every validation rule, and the two would drift — which is
the failure this file already records twice in other shapes. See
ADR `053-a-document-is-a-definition-not-an-export.md`.

## Known Pitfall (v2.110.0): an "unavailable" message is help text, and help text is Indonesian

`OcrExtractor::unavailableReason()` was written in English because it reads like a deployment note.
It is not one: it is rendered on the Document Library and in the import dialog, to the storeman who
just uploaded a scan. The language hierarchy below puts help text, hints and empty-state guidance in
Indonesian, and a capability-reporting string is all three. It also has to say **what to do
instead**, not only what is missing.

The same applies to every `unavailableReason()` and every extractor `warning`.

## Convention (v2.53.0, written down properly in v2.68.0): THE LANGUAGE HIERARCHY

**IOMS is bilingual on purpose, and the split is by SLOT, not by page.**

| Slot | Language |
|---|---|
| Module and feature names, navigation labels, page titles (`<Head title>`, `DashboardShell title`) | **English** |
| Column headers, status labels, stat/KPI labels, action labels and buttons, accessible names | **English** |
| Subtitles, card descriptions, help text, hints, empty-state guidance, `whats_new` | **Indonesian** |
| Legal documents (terms, privacy, refunds) | **Indonesian**, deliberately — an Indonesian legal audience |

Established technical terms are never translated in either direction: HSE, PPE, JSA, HIRADC, PTW,
LOTO, CAPA, TBM, NCR, RFQ, KPI, Gas Test, Man-Hour, Work Order, Goods Receipt, Purchase Order, and
the Indonesian document names FPB and SPK.

**Why the English half is not negotiable.** v2.53.0 states the test: a user must be able to say
*"open Regulations & Standards"* out loud and have it match what is on screen. A menu item and the
page it opens must be recognisably the same thing. Translating that layer breaks the connection
between the two.

**Why this entry exists at all.** This rule was real from v2.53.0 onward but lived only inside
`config/ioms.php`'s `version_history` summaries — a place nobody reads before editing a page. It was
therefore violated three separate times (see the pitfall below). A rule that only exists in a
changelog is a rule that will be broken. It is pinned by `tests/Feature/LanguageHierarchyTest.php`.

## Known Pitfall (v2.68.0): every attempt to hold a language line was scoped to the file its author
## happened to have open — three times, across sixteen releases

This is the same shape as the v2.63.0 isolation gap and the v2.64.0 landing-copy gap, and it kept
recurring because each fix looked complete from where it was written:

- **v1.11.7** translated navigation and the five department Overview dashboards into Indonesian under
  the then-current "standardize on Bahasa Indonesia" policy.
- **v2.53.0** replaced that policy with the hierarchy above and corrected the pages it was looking
  at. **Nothing re-checked the five Overview dashboards.** They kept running the superseded policy
  for fifteen releases, so the sidebar said `CAPA` while the card beneath it said
  `Tindakan Perbaikan`, and clicking `Observasi Keselamatan` landed on a page titled
  `Safety Observation`.
- **v2.61.0** made the landing page English and pinned it with a test that read `Welcome.jsx` — so
  the copy arriving as a *server prop* stayed Indonesian for three more releases (already recorded
  as its own v2.64.0 pitfall).

**Two rules come out of this:**

1. **A label can come from two places — the JSX literal and the server prop — so a test that claims
   to cover "the page" has to read both.** `LanguageHierarchyTest` scans label positions in every
   `.jsx` *and* asserts against the rendered Inertia props of every public route.
2. **When a policy is replaced, the replacement has to name what the OLD policy touched.** v2.53.0
   corrected the pages in front of it and never listed v1.11.7's five dashboards as outstanding.

`resources/js/lib/id.js`, the v1.11.7 terminology map, was deleted in v2.68.0 — it had been imported
by nothing for many releases (its strings were absent from the compiled bundle entirely). A
dictionary encoding a superseded policy is how that policy comes back; a test now asserts it stays
gone.

## Convention (v2.68.0): a stat card may clip its VALUE, never its LABEL

`truncate` on a label is almost always a bug. The value is a number and the card links to the record
behind it, so clipping it costs little. The label is the only thing that says *what the number
counts* — clipping it makes the tile unreadable rather than merely abbreviated.

Measured on the Dashboard at 1440px before this was fixed: the KPI strip was `lg:grid-cols-8`, which
left **33px** for the label, so `Fatality` — the most severe category in an HSE product — rendered as
`FAT...`, and the "needs attention" row showed `PENDING PROCUREM...` and `MAINTENANCE DUE (...`.

`StatCard`, `KpiSummaryCard` and `ModuleCard` therefore wrap their label (`line-clamp-3`) instead of
truncating it. Two consequences worth knowing:

- **Wrapping is cheaper than it looks**: a label that already fits is unaffected, so this only ever
  costs height on the cards that were previously broken.
- **Wrapping cannot save a single word wider than its column.** At 320px, `OBSERVATIONS` alone
  exceeds the 64px column in a 2-up grid and still clips. That is a known, accepted limit at 320px
  only — clean from 375px up.

## Convention (v2.68.0): colour in a chart must not borrow a meaning the app already assigned to it

`CHART_COLORS` is a *categorical* palette and contains `#ef4444` and `#22c55e` — the same red and
green `StatCard` uses semantically for "open incidents" and "healthy". Using it to colour categories
means a department, a warehouse or a vendor can be drawn in the colour this product uses for danger.

- **Prefer one colour** when the chart has a single series (a ranked bar chart answers "who is
  biggest?" without colour doing any work).
- **When a category already owns a colour, use that one.** KPI categories carry an admin-configured
  `effective_color` that their cards and sparklines use; the Dashboard trend chart used to colour by
  array position instead, so `Fatality` was red on its card and blue on the line directly beneath it.
  Both payloads carry `code`, so they are joined in the page — presentation, not query.
- **Past ~6 categories, stop using a pie.** "Employees by Department" was fourteen slices from a
  nine-colour palette; nobody can rank two similar wedges, which is the only question that card is
  asked.

## Known Pitfall (v2.68.0): a guard written for the SPA made the friendly error page unreachable
## from a browser address bar — the one place it was needed

`bootstrap/app.php` renders `Errors/Show` for expected statuses so an error stays inside the product
with a link back. Its first guard was:

```php
if (! $request->header('X-Inertia') && ! $request->wantsJson()) {
    return $response;   // "non-Inertia, non-JSON request (e.g. a raw asset 404)"
}
```

`X-Inertia` is present **only on an XHR navigation made from inside the already-running SPA**. So
every *full-page* request — a typed URL, a stale bookmark, a link pasted from a chat, a refresh after
the session expired, a deep link into a department the user cannot open — fell through to Laravel's
default and rendered a bare `404 NOT FOUND` with no navigation at all. Those are precisely the
moments someone needs a way back, and the component, the handler and the route table each looked
correct read on their own.

**The rule: "is this request part of the SPA?" is not the same question as "is this request a page?"**
The intent was to skip *asset* 404s. Test for that directly (a file extension on the path) rather
than using the SPA header as a proxy for it.

**And the consequence to check whenever a renderer starts reaching more requests:** what it renders
now reaches more readers. Forwarding `$exception->getMessage()` verbatim was survivable while only
in-app navigation saw it; broadening the guard meant a framework string like
`No query results for model [App\Models\Employee] 99999` became the entire explanation of a 404.
`App\Support\ErrorMessagePresenter` now suppresses framework-generated text while preserving the
three 403 messages this codebase writes on purpose. Widening *reach* and tightening *content* belong
in the same change.

## Known Pitfall (v2.69.0): `transitionTo()` in a loop is an N+1, because a status change notifies
## somebody — and the relation it reads is not loaded

`HasWorkflow::transitionTo()` ends by notifying the record's owner, and
`notificationRecipient()` resolves that by reading a `requester`/`reporter`/`creator` relation. On a
single transition from a controller action that is invisible. In a **loop** it is one query per
model:

```php
foreach ($requests as $materialRequest) {              // N models
    $materialRequest->transitionTo('processing', ...); // -> lazy-loads `requester` N times
}
```

Demand consolidation makes this worse by definition — the whole point is attaching many requests at
once, so the loop is never length 1 in the case that matters. The fix is one word at the query:

```php
$requests = MaterialRequest::whereIn('id', $ids)->with('requester')->get();
```

**Caught by a test, not in production**, because Laravel's strict lazy-loading is on in this suite
and throws where production would have silently issued the extra queries. That is the same reason
`Model::preventLazyLoading()` is worth keeping on: it converts a performance bug into a failing
test.

**The general rule: before transitioning models in a loop, eager-load whatever the transition's own
side effects read.** A trait that does something helpful on your behalf still does it once per call.

## Known Pitfall (v2.69.0): a conventionally-named pivot table can exceed MySQL's 64-character
## identifier limit before you have added a single column

Same limit as the v2.51.0 entry below, reached a different way. Laravel derives constraint names as
`{table}_{column}_{type}`, and a pivot's conventional name is both tables joined:

```
material_request_purchase_requisition                              (37 chars)
material_request_purchase_requisition_material_request_id_foreign  (65 — fails)
```

`$table->foreignId(...)->constrained()` therefore fails on MySQL for any pivot between two tables
with longish names, and it fails at migrate time on a fresh database rather than in review. Name the
constraints explicitly:

```php
$table->foreign('material_request_id', 'mr_pr_material_request_fk')
    ->references('id')->on('material_requests')->cascadeOnDelete();
```

**Worth knowing because the failure is invisible until someone actually runs the migration** — which
is the argument for running it rather than reasoning about it.

## Known Pitfall (v2.60.0): a data migration makes every test's fixtures a duplicate

The four-tier pricing catalogue ships in a migration, not only in the seeder — deliberately, because
`db:seed` does not re-run on a live deployment (see the v2.51.0 entry below). The consequence nobody
predicted: `RefreshDatabase` runs migrations, so **every test now starts with those rows already in
the table**. Twenty-nine tests failed at once with `UNIQUE constraint failed: packages.slug`, purely
because each had been building its own `Package::create(['slug' => 'starter', ...])`.

**Rules.**

- A test that needs a catalogue row should **read it** (`Package::where('slug', …)->firstOrFail()`),
  not create it. That also makes the test assert what a deployment actually ends up with.
- A test that needs *some* plan, and does not care which, uses `firstOrCreate(['slug' => …], [...])`.
- A test that deliberately reconstructs an older state (to run an old migration over it) uses
  `updateOrCreate` — it is writing *over* the migrated row, which is the point.
- Before adding data to a migration, grep the test suite for that table.

---
## Known Pitfall (v2.60.0): the same approved figure typed into three test files

The prices were pinned in `PricingConsistencyTest`, `ProductRevisionV252Test` and
`PublicSaasJourneyTest`. Repricing broke all three at once and each had to be found and corrected by
hand — which is the exact failure mode those tests exist to prevent, reproduced inside the tests.

`Tests\Support\ApprovedCatalogue` is now the single place the commercial model is written down, and
the three files read from it.

It is deliberately **not** derived from `config/plans.php` or from the migration. A test that reads
its expectation from the code under test only asserts that the code equals itself. These numbers are
transcribed from the approved commercial decision, and their whole job is to fail when the code stops
matching it.

---
## Known Pitfall (v2.60.0): a catalogue UPDATE path that forgets a column the INSERT path sets

`2026_09_26_100270_establish_four_tier_pricing` sets `trial_days` on insert and, in its first
version, not on update. Every *existing* tier takes the update path — so Professional kept
`trial_days = 14` from an older catalogue and the Plans page went on advertising a free trial IOMS
does not sell. The whole test suite was green; it was found by looking at the running page.

**Rule.** In an upsert migration, build ONE `$attributes` array and use it for both branches. If a
column belongs in the insert, justify out loud why it does not belong in the update. And when a
migration changes what a customer sees, open the page.

---
## Known Pitfall (v2.60.0): a backfill that reads the table an earlier migration just changed

`…100271_snapshot_agreed_subscription_price` backfills each subscription with the price its customer
agreed to. The obvious source is the `packages` table. It is the wrong one: migrations run in
filename order, `…100270` reprices the catalogue **first**, so reading `packages` would have stamped
every existing customer with the NEW price — the precise outcome the snapshot columns exist to
prevent, written into the data permanently.

The backfill therefore carries a **literal table of the pre-v2.60 prices**, and only touches rows
where both snapshot columns are still null.

**Rule.** A backfill of "what was true before" must never read state that a migration in the same
deploy has already rewritten. Write the historical values down in the migration.

---
## Known Pitfall (v2.59.0): a scroll reveal that starts hidden can strand content permanently

The obvious way to build one is "render at `opacity-0`, transition to visible when an
IntersectionObserver reports the element". It has a failure mode that only appears in a real
browser: **IntersectionObserver reports threshold CROSSINGS, not positions.** An element that moves
from below the viewport to above it inside a single frame — a programmatic jump, an in-page anchor,
the browser restoring scroll position on reload — was never intersecting at either sample, so no
callback ever fires and it stays at `opacity: 0` forever.

Verified live: one `window.scrollTo()` past a heading left it invisible. Patching it with scroll
listeners only narrows the window, and the browser pane could not fire scroll events at all, so the
patch was unverifiable there too.

**Rule.** Motion must be purely ADDITIVE. Render the element visible and let entering the viewport
add a one-shot keyframe (`animate-reveal`); never let it remove one. Then a failed, skipped or
unsupported observer costs the animation and nothing else. There must be no code path that can hide
content — a reveal that can strand content is a content bug wearing an animation.

Gate every such class behind `motion-safe:` AND short-circuit in the hook when the visitor prefers
reduced motion. Two independent guards, because one of them is a CSS variant somebody can delete.

---
## Known Pitfall (v2.59.0): "dead" code that lint says is alive

Removing the landing page's placeholder mockups looked safe — `MockupFieldHome` appeared unreferenced
in the section being replaced. `npm run lint` caught it immediately: it was rendered by a *different*
section further up the file.

That is the second real defect the narrow ESLint config (added v2.54.0) has caught. **Run it before
committing frontend changes**, especially deletions.

---## CRITICAL — Known Pitfall (v2.58.0): two layers answering the same question, differently

A self-service customer could sign in, reach the Dashboard, and see a navy sidebar with **nothing in
it**. The cause was not one bug but two rules that contradicted each other:

- `EntitlementService::tenantCanUseWorkspace()` treats *"this tenant has no grant rows"* as
  **unrestricted** — deliberate and documented, so a tenant provisioned before grants existed is
  never locked out of its own product.
- `HandleInertiaRequests` independently plucked the same pivot and forced every workspace not in the
  list to `is_active: false`. An empty list therefore meant **nothing granted**.

So the route layer let the customer through and the navigation layer showed them no way to get
there. Neither piece of code was wrong on its own; they had simply never been asked to agree.

Compounding it: `reports` and `administration` carry `tier: 'global'` — Settings, Users, Audit Logs,
Report Center — and the sidebar's global state (the state you are in **on the Dashboard**) is built
from exactly those two. `Package::defaultWorkspaceKeys()` listed departments only, so no plan
granted them and every Starter and Professional tenant lost the entire sidebar. The same keys appear
in `config('departments')`, so those tenants were also **403'd out of Reports and Analytics**.

**Rules.**

1. When two layers gate the same thing, one of them owns the answer and the other calls it.
   `grantedWorkspaceKeys()` / `grantedModuleKeys()` are now that owner. Do not re-derive a grant by
   plucking a pivot.
2. **Not everything in a catalog is sellable.** `tier` had encoded that distinction from the start
   and the entitlement layer ignored it. Ask `Workspace::isGlobalKey()`; never hardcode the pair.
3. A `match` on a plan slug with `default => []` means "an unrecognised plan gets no product".
   Fail open to the same rule the rest of the codebase uses, or the first custom plan is an outage.
4. **Verify navigation as a customer, on a customer's plan.** Every prior pass was verified on the
   development tenant, which is on Enterprise — the one plan that received every workspace and
   therefore the only one where this bug was invisible.

---## Known Pitfall (v2.57.0): `MAIL_MAILER=log` hides every mail defect until the day it doesn't

Development ran on the `log` mailer, which writes messages to `storage/logs` instead of sending
them. Nobody opens those files, so for the whole life of the project two things were wrong and
invisible:

- The **password reset email** was Laravel's stock `ResetPassword` notification — scaffolding
  wording, no IOMS letterhead, no Reply-To, and a From address taken from `MAIL_FROM_ADDRESS`
  rather than the IOMS mailbox configuration every other IOMS email uses. It is the message a SaaS
  sends most often, and it would have become live the instant SMTP credentials were entered.
- `MAIL_FROM_ADDRESS` in the local `.env` was still `hse@shipyard.local` — **two product renames
  old**. A From address the sending domain does not authorise is the ordinary reason transactional
  mail lands in spam.

Both were found by a test asserting envelopes, not by reading code.

**Rules.**

1. Assert the ENVELOPE of every Mailable — From, Reply-To, subject. It needs no transport, no
   credential and no network, so there is no excuse not to.
2. Anything that sends mail without an explicit From inherits `config('mail.from')`. That value now
   chains to `IOMS_NOREPLY_EMAIL` (see `config/mail.php`) so configuring the four IOMS mailboxes is
   enough; do not reintroduce a second place to set the sender.
3. `MAIL_ENCRYPTION` does not exist in Laravel 11+. Setting it does nothing. Implicit TLS comes from
   `MAIL_SCHEME` (or is inferred from port 465). Documenting the dead variable is worse than
   documenting nothing, because it reads as configured.

---## Convention (v2.55.0): never render a legal or identity fact the deployment does not have

The public Terms, Privacy and Refund pages state who operates IOMS, from what address, and under
which law. Those are legally meaningful and IOMS does not have them yet.

The mechanism, in `App\Support\LegalDocuments`: clauses are written with `:token` markers, and a
paragraph containing a token with no configured value is **discarded whole**. `PublicController`
then drops any section left with no paragraphs. The result is a document that is shorter than it
will eventually be, and true at every stage — rather than one containing "operated by (  )".

**Rule.** When copy depends on a fact from configuration, omit the sentence when the fact is
missing. Never emit a placeholder into user-facing text, and never invent the fact to fill the gap.

**And keep merchant KYC out of the codebase.** The identity a payment provider verifies — personal
name, residential address, tax number, ID documents — is not website content and has no config key
here. `IOMS_LEGAL_ADDRESS` exists for an address the operator intends to PUBLISH, which is not the
same address.

---## CRITICAL — Known Pitfall (v2.54.0): the "grep for `unique(`" lesson was already written down, and
## the next release still shipped with two of them — this time on the POST-PAYMENT path

v2.40.0 wrote the rule (below): *"grep for `unique(` in old migrations whenever you add a new scoping
dimension."* v2.53.0 applied it to fourteen document-number columns. Neither pass looked at
`companies` itself, where `name` and `code` have been globally `unique()` since the table was created
in a single-company world.

So two different organizations could not both have an operating unit called "Maintenance" — while
`SettingsController::storeCompanyEntity()` validated per tenant and its own comment asserted they
could. The database refused.

**It landed somewhere worse than data entry.** `TenantProvisioningService::activate()` runs from a
verified payment webhook and creates the organization's first operating unit named after the
registration, with a code taken from the first six characters of the slugged name:

- two customers called "PT Maju Jaya" collide on `companies_name_unique`;
- "PT Bahari Nusantara" and "PT Bahari Sejahtera" both give `PTBAHA` and collide on
  `companies_code_unique`;
- any name that slugs to nothing hits the literal `'COMP'` fallback, so the second one collides
  outright.

In every case the insert throws **inside provisioning, after the payment is confirmed**: invoice
issued, no tenant, no login. A duplicate key is not transient, so nothing retried.

**Rule.** A grep is not a sweep. When you apply this lesson, enumerate EVERY table that gained a
scope column and check each one — including the table the scope column lives on. Inspect with
`Schema::getIndexes()`, never a raw `information_schema` query (see v2.51.0 below).

---
## Known Pitfall (v2.54.0): `npm run lint` was in package.json for a year and never once ran

There was no `eslint.config.js`, so on ESLint 9 (flat config) the script just failed. Nothing was
ever linted, and the cost was a whole dead tab: `Settings/Index.jsx` read `canFieldAccess` inside a
module-scope function where it was never a prop, so opening **Settings → Users** threw a
`ReferenceError` and blanked the page. No test caught it — Radix only mounts the active tab's
content, so nothing else on the page even rendered the broken component.

`eslint.config.js` is now deliberately narrow: undefined identifiers, unreachable code, duplicate
keys, JSX referencing an undefined component. Style rules and `exhaustive-deps` are **off on
purpose** — a lint run that reports thousands of findings across a codebase this size is a lint run
nobody reads, which is worse than none.

**Rule.** Run `npm run lint` before committing frontend changes. If you add a rule, add one that
would have caught a bug that actually happened.

---
## Known Pitfall (v2.54.0): a Tailwind grid with no breakpoint prefix applies at 320px too

Every data-entry form in IOMS paired its fields with a bare `grid-cols-2` / `grid-cols-3`. That is
not "two columns on desktop" — it is two columns *always*, including on the phone a field worker
actually files a permit from, where each half lands at roughly 150px. Fifty sites across nineteen
forms, every one of them written by someone picturing a laptop.

**Rule.** A grid that pairs FORM FIELDS starts at `grid-cols-1` and pairs from `sm:` up. A grid of
short toggle buttons (Pass / Fail / N/A) is the deliberate exception and stays fixed — stacking those
makes a checklist three times longer for no gain. This is the same class as the v2.16.0 pitfall below
(a row with no `overflow-x-auto`): mobile breakage that is invisible unless you actually narrow the
viewport.

---## CRITICAL — Known Pitfall (v2.53.0): a UNIQUE index and a per-tenant counter are a contradiction,
## and it stays invisible until the second tenant exists

Counters became per-tenant in v2.41.0. Fourteen document-number columns kept a **global** `unique()`
from before multi-tenancy. Both cannot be true: every tenant's counter starts at 1, so every tenant's
first permit is `PTW-YYYY-00001`, and a global index means only the first tenant in the database can
ever have it.

The consequence was not cosmetic. **The second customer to sign up could not create their first
Permit To Work, Material Request, Purchase Order or Task at all** — the insert failed on a duplicate
key. IOMS now sells self-service signup, so customer number two would have hit it on day one.

It survived because every environment had exactly one tenant with real data. It surfaced the instant
a second tenant seeded a permit. v2.41.0's own release note asserted the opposite ("confirmed no
module number column carries a global unique constraint") — an assertion nobody could falsify with a
single-tenant database.

**Rule.** When a value is generated per tenant or per company, its uniqueness constraint must be
scoped the same way — `unique(['company_id', 'number'])`, never `unique('number')`. And when a
release note claims "confirmed no X exists", confirm it with a query against the schema, not by
reading migrations: this is exactly the class of fact that is cheap to check and expensive to assume.

```sql
-- the check that would have caught it
SELECT table_name, index_name, GROUP_CONCAT(column_name)
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND non_unique = 0 AND index_name <> 'PRIMARY'
GROUP BY table_name, index_name;
```

**Related trap, same release.** A global scope guarded on `app()->runningInConsole()` to skip queue
and scheduler context. PHPUnit also runs in console, so the scope silently did nothing under test and
the feature would have shipped unverified. Guard on the actual condition (here, the absence of an
authenticated user), never on "am I in console".

---## CRITICAL — Known Pitfall (v2.52.0): giving two different business concepts ONE name is a data
## model bug, and it hides because the code keeps working

v2.51.0 shipped a document titled "Goods Receipt / Berita Acara Serah Terima Barang". Nothing broke.
The PDF rendered, the tests passed, the data was correct. But a Goods Receipt is a warehouse
transaction whose consequence is a stock level, and a BAST is a formal handover between two named
parties whose consequence is contractual. Collapsing them meant a customer either could not produce a
real BAST when a client asked for one, or started treating routine receiving as contractual
acceptance.

The same mistake had already been made twice more in this codebase, in the same shape:

- **My Work** (a workspace) inferred from **department_key**, and treated as interchangeable with
  **PTW Access** (a permission).
- **Project identity** and **work location** sharing one field, so a permit for real work printed
  "No Project".

**Rule.** Before reusing a name, ask what each concept's *consequence* is. If two things have
different consequences — stock moves vs a contract is accepted; where you land vs what you may do;
what the job is vs where it happens — they are different concepts and need different fields, even
when one is "obviously" a special case of the other. A shared name costs nothing on the day it is
introduced and is expensive to unpick later, because by then users have learned the wrong model.

**Corollary for enums and quotas.** `null` is not a neutral default. `max_ptw_users = null` meant
UNLIMITED to the entitlement layer, so every plan was silently granting unlimited PTW seats. When a
column expresses a limit, decide explicitly what null means and enforce the relationship between
related limits (`max_ptw_users <= max_users`) in a migration, not in a comment.

---## CRITICAL — Known Pitfall (v2.51.0): a SEEDER is not the runtime source of truth. Changing seed
## data does not change a deployment that has already been seeded — and the drift is invisible in
## code review because the file you are reading is correct

v2.50.0 standardized plan pricing by editing `database/seeders/PackageSeeder.php`, updated every
pricing surface, wrote a test asserting the new figures against `PackageSeeder`, and shipped. The
test passed (it seeds a fresh database). Production kept rendering "Gratis / Rp490 / Rp1.490" for a
whole release, because `db:seed` had never been re-run there and the application reads the
`packages` **table**, not the seeder.

The failure mode is nasty specifically because everything *looks* right: the seeder is correct, the
service is correct, the page is correct, the test is green. Only the row is stale.

**Rule.** Seed data that the application reads at runtime and that must change on an EXISTING
deployment belongs in a migration, not only in a seeder. A migration runs exactly once per
environment on deploy, whether or not anyone remembers to seed. Keep the seeder in step so a fresh
install and an upgraded install converge — but the migration is what actually fixes production.
See `2026_09_17_100220_standardize_launch_plan_pricing.php`, and the test that re-runs that
migration against deliberately-stale placeholder rows.

**Corollary, and this is the part that bit hardest.** Once you find drift in one column, assume the
whole row drifted. Verifying that same pricing fix in a browser showed `max_ptw_users` was NULL on
every plan — and null means *unlimited* to the entitlement layer, so every tier had been silently
granting unlimited PTW Access seats. That was an entitlement defect hiding behind a cosmetic one,
and only a real page render against the real database surfaced it.

---
## Known Pitfall (v2.51.0): MySQL 8.4 returns `information_schema` column names in UPPER CASE, and
## Laravel's auto-generated index names can exceed MySQL's 64-character identifier limit

Two separate defects in one shipped migration, both of which aborted `php artisan migrate` outright
on a fresh MySQL 8.4 server — so the app could not be installed at all, while every existing
environment was fine because the migration had already run there.

1. `DB::selectOne('select engine from information_schema.tables ...')->engine` is an "Undefined
   property" fatal on 8.4: the property is `ENGINE`. **Alias the column** (`select engine as
   table_engine`) so the property name is yours, not the server's.
2. `$table->index(['safety_equipment_id', 'inspection_date'])` on `safety_equipment_inspections`
   generated a 69-character index name. **Name long composite indexes explicitly** rather than
   shortening a column to work around a naming problem.

**Rule.** A migration is only proven by running it against an empty database on the target engine.
"It ran once on my machine two versions ago" is not the same test.

---## CRITICAL — Known Pitfall (v2.40.0): a `unique()` constraint written before multi-tenancy is not
## just a constraint, it is an architectural assertion that only ONE of the thing can exist — and it
## keeps enforcing that after tenancy makes it false

`company_settings` was created in 2024 as a "single-row key/value settings table" with
`$table->string('key')->unique()`. Correct for a single-instance app. Milestone 2 added tenancy and
nobody revisited it, so for two years the table physically COULD NOT hold more than one row per key
for the whole platform. The result, proven empirically before it was fixed:

- Tenant B read Tenant A's company name, logo path, address, phone, email and brand colour.
- Tenant B saving branding DESTROYED Tenant A's, because `updateOrCreate(['key' => ...])` matched
  the single shared row. Not a leak — data loss.
- It reached artifacts that LEAVE the system: `DocumentEngine` (every PDF letterhead),
  `EmployeeExport` / `KpiReportExport` metadata, Report Center, Analytics.

Three lessons, in order of how easily each is missed:

1. **Grep for `unique(` in old migrations whenever you add a new scoping dimension.** A unique index
   is the single most durable way a pre-tenancy assumption survives into a tenant world, because it
   is invisible from the model and silently *works* — it just works globally.
2. **Fixing the accessor is not enough.** Settings were read through TWO paths: `CompanySetting::get()`
   and five raw `CompanySetting::where('key', ...)->value('value')` bypasses (added deliberately, for
   a documented caching reason). Patching only the accessor would have left every bypass reading
   across tenants. The fix is a **global scope**, which covers paths you did not write and paths added
   later; `CompanySettingScope` fail-closes to "your own bucket only".
3. **A cache key is part of the isolation boundary.** `Cache::rememberForever("company_setting:{$key}")`
   would have served Tenant A's value to Tenant B even after the query was correctly scoped. Any cache
   over tenant-owned data must include the tenant in its key. (v1.6.8 already taught that the cache key
   must be reconstructible by `set()`'s `forget()`; v2.40.0 adds that it must also be tenant-qualified.)

The same shape is still OPEN elsewhere and is documented rather than half-fixed: `numbering_sequences`
is still global-scope, so tenants share one counter and each sees gaps in its own document numbers.
See docs/ADR/025 for why a naive fix (which would re-issue existing numbers) is worse than the gap.

---
## Convention (v2.22.0): IOMS is the product name — treat it like SAP/Workday/ServiceNow, not its own
## full expansion

`config/app.php`'s `'name'` fallback and `resources/views/app.blade.php`'s `<title>` fallback both
previously defaulted to the full "Integrated Operations Management System" — and this local
environment's own `.env` (untracked by git, so this fix doesn't travel with the repo — the
equivalent `APP_NAME` change has to be made by hand wherever else this app is deployed) had
`APP_NAME="Shipyard Management System"`, a stale pre-rebrand name (see this file's own CLAUDE.md
history) that had been silently showing in the browser tab this entire time. Fixed both the code-level
fallback (now `'IOMS'`) and this environment's own `.env`. Going forward: **"IOMS" is the product
name everywhere it appears as a headline/title/tab-title/primary brand mark** (sidebar wordmark
subtitle, login page, public site hero/footer, browser tab); the full expansion stays appropriate
only in genuinely explanatory contexts (About dialog, an FAQ literally answering "what does IOMS
stand for", internal docs) — never as the thing competing with "IOMS" itself for primary billing.

## New reusable component (v2.20.0): `Components/shared/PersonChip.jsx` — the one place IOMS shows a
## real Employee/User reference

Built for PTW's PIC/Workforce display (`PermitsToWork/Document.jsx`, `Show.jsx`) — an initials-avatar
+ name (+ optional subtitle) chip, read-only, no interaction. Deliberately does NOT replace
`PermitsToWork/Form.jsx`'s own removable workforce chips (`Badge` + an `X` remove button, built while
composing a PTW) — that's a different, already-correct editing pattern. `PersonChip` is specifically
for DISPLAYING an already-saved record's People data, where there's nothing to remove. No avatar image
(IOMS has no employee-photo field wired into this flow) — initials-on-a-tinted-circle only, matching
this codebase's "never fabricate data" rule (no placeholder/stock photo). **Any future module that
displays a real Employee/User reference for reading (not editing) should reuse this component** rather
than a fifth copy of the same initials-circle markup — PTW is the first consumer, not the only intended
one.

## Known Pitfall (v2.19.0): a route-level middleware group can be STRICTER than its own controller's
## authorization check, silently making the controller's broader check unreachable

`settings.users.ptw-access` was added in v2.17.0 with a controller-level `canManageHse()` check
(Super Admin OR HSE) — correct, and documented as intentionally broader than the route group it was
placed in "as defense-in-depth for if this route is ever also exposed to HSE." That framing was
backwards: the route sat inside `role:super_admin`-only, which is STRICTER than the controller's own
check, so the controller's HSE branch could never actually be reached — Laravel's `role:` middleware
rejects the request before the controller runs at all. The bug wasn't caught for two passes because
`php -l`/`route:list`/`config:cache` all pass regardless (this is an authorization-scope mistake, not
a syntax one) — it only surfaces by comparing the route's own middleware list against what the
controller assumes, or by an end-to-end test as an actual HSE user, neither of which happened until
this pass. Fixed by moving the route into its own dedicated `role:super_admin,hse` group. **When a
controller method's own authorization check names a set of roles, the route's own middleware group
must never be narrower than that set** — grep for the controller's `abort_unless(...->can...())` /
`FormRequest::authorize()` calls and cross-check them against the route's actual `middleware` array in
`route:list --json` output, don't assume the surrounding group is already correct just because the
code compiles and other routes in the same file work.

## Known Pitfall (v2.16.0): a tab/chip row with no `overflow-x-auto` and no `flex-wrap` drags the
## WHOLE PAGE sideways on mobile, not just itself -- `<main>` has no `overflow-x-hidden` anywhere

`ModuleTabNav.jsx` (the shared component behind PPE's tab bar, and any future module using it) was
`flex gap-1` with neither wrap nor scroll containment. With enough tabs/long-enough labels (PPE's
"Replacement Requests" was the trigger), the row's natural width exceeded the viewport -- and because
`AuthenticatedLayout.jsx`'s `<main>` has no `overflow-x-hidden` (correctly, per this codebase's own
"never solve overflow by blindly hiding it" convention), that width difference became page-level
horizontal scroll, dragging every other element on the page sideways with it, not just the tab row.
Fixed by making the SCROLL belong to the component (`overflow-x-auto` on the row itself, `shrink-0
whitespace-nowrap` on each tab so a tab compresses/wraps its own text before the row would) rather
than by hiding overflow anywhere upstream -- `PermitsToWork/MyIndex.jsx`'s status-chip row already did
this correctly and was used as the reference. **When adding any horizontal row of items (tabs, chips,
filter pills) whose count/label length isn't tightly bounded, default to `overflow-x-auto` +
`shrink-0` on the row from the start** -- `flex-wrap` is an acceptable alternative ONLY when a second
line is visually fine (e.g. `Hse/Master.jsx`'s own tab bar already wraps correctly), never assume a
row "probably fits."

Same root cause, smaller blast radius, found in two PPE list rows (`Ppe/Employees.jsx`,
`Ppe/ReplacementDue.jsx`): several `shrink-0` fixed-pixel-width columns (`w-40`/`w-32`/`w-28`, no
`flex-wrap`) summed to more than a 320-375px viewport before the flexible/truncating column even got
space. `shrink-0` prevents a column from compressing, which is correct for short fixed content -- but
without either `flex-wrap` or hiding a lower-priority column below `sm`, multiple `shrink-0` columns
together can still force the row wider than the viewport. Fixed per-row (hide the least essential
column below `sm`, or let secondary columns wrap to a second line) rather than in a shared component,
since each row's actual priority ordering (which field matters most when scanning) is page-specific.

## Convention (v2.15.0): a shared-component fix reaches every caller for free, but only the callers
## that actually use the shared component -- 8+ pages hand-roll their own status/badge colors instead

Auditing `StatusBadge` usage for the UI/UX Finalization pass found the mapping itself was fine, but
roughly 8 pages (`Calendar/Index.jsx`, `Employees/Index.jsx`, `Employees/Profile.jsx`,
`Platform/TenantDetail.jsx`, `Projects/Show.jsx`, `Settings/Index.jsx`, `Subscription/Plans.jsx`, and
notably `Dashboard/Index.jsx` in ONE place while correctly using `StatusBadge` elsewhere in that same
file) still hand-roll their own `variant="success"|"destructive"|...` status color logic. This is the
same lesson as `PageHeader`'s standalone hand-rolled duplicate in `PermitsToWork/Index.jsx` (found in
an earlier pass) in a new shape: a shared component only fixes what actually calls it. When adding a
new status/priority display anywhere, reach for `<StatusBadge value={...} />` first and check
`StatusBadge.jsx`'s `STATUS_MAP` before inventing a new inline color mapping — extend the shared map
if a status is missing from it, don't duplicate the pattern locally. The known hand-rolled instances
above were left as-is this pass (retrofitting every one is a separate, lower-urgency task, same
disposition as `StatusBadge`'s own original doc comment already took toward pre-existing per-page
maps) except `Dashboard/Index.jsx`'s pending-tasks list, fixed as this pattern's own demonstration.

## Known Pitfall (v2.14.0): adding one route inside an existing `Route::get(...)->name('settings.index')`
## block visually looks safe but can silently land inside a role-restricted sub-group a few lines later

While building the tenant-facing Plans page this pass, a new `subscription.plans` route was first
added directly after `Route::get('/settings', ...)` (routes/web.php) — which reads as a safe,
general spot, but that line is actually the FIRST line inside `Route::middleware('role:super_admin,
hse')->group(...)`, a restrictive sub-group that doesn't close for another ~120 lines. The route was
built specifically to be open to every authenticated tenant user (Part 8's "not privileged
information"), and it silently would not have been — caught only because `php artisan route:list
--json` was actually run and its `middleware` array inspected, not assumed from the surrounding code's
indentation. This is the same failure class CLAUDE.md's own "verify before building" section already
warns about (Material Request/PPE Replacement Request once being accidentally nested inside
`role:super_admin`) — the lesson repeats because visual proximity to a `Route::get('/x', ...)` line is
not evidence of which `Route::middleware(...)->group()` it's actually inside; only tracing the actual
`Route::middleware(...)->group(function () { ... })` open/close pairs (or running `route:list` and
reading the `middleware` column) tells you.

## Convention (v2.13.0): flipping an enforcement flag on safely needs a "zero grants = allowed"
## default PLUS a real top-up tool — not just careful reasoning about the one tenant you can see

`config('saas.enforce_workspace_entitlement')` sat at `false` since v2.1.0 because the reasoning for
enabling it ("`TenantGrantSeeder` grants the default tenant everything, so it's *very likely* safe")
was never actually verified — this environment has no database access in any session so far, so
"very likely" could never become "confirmed." SaaS Phase 1 (v2.13.0) resolved this without ever
touching the DB, by making the flag's activation safe **by construction** instead of by inspection:
`EntitlementService::tenantCanUseModule()`/`tenantCanUseWorkspace()` now treat a tenant with zero
grant rows as fully allowed (not fully denied), and a new `php artisan tenants:sync-grants --dry-run`
command gives a real, additive, auditable way to close the gap for a tenant that has *some* but
possibly-stale grants. The lesson: when a security flag can't be verified safe against live data,
look for a way to make it *structurally* safe instead of deferring the decision indefinitely —
"we can't verify it so we'll leave it off" can sit unresolved for phases at a time (it did, for four
version bumps), while a one-time design change can make the flag's own worst case bounded and
recoverable (a trivial `.env` revert) rather than a silent full lockout.

## CRITICAL — Known Pitfall (v2.12.0): a `scopeVisibleTo()`/access-control method written BEFORE
## multi-tenancy existed can silently become a cross-tenant leak once multi-tenancy is added, if
## nobody goes back and updates it

Found during the Product Finalization pass's own security audit, in **three separate places at
once**, all the same root cause: `MaterialRequest::scopeVisibleTo()`, `PpeReplacementRequest::
scopeVisibleTo()`, and `KpiRecordController::index()`'s company-scoping join were all written during
Milestone 3 (Material Request / PPE Replacement / KPI Records), **before** Milestone 2's Tenancy
Foundation existed. At the time, "Super Admin sees every row" and "no company filter selected shows
every row" were both correct — there was only ever one company universe to see. When multi-tenancy
was added later, nobody revisited these three call sites, so they kept doing exactly what they always
did — except "every row" now silently meant **every tenant's** rows, not just the current tenant's.

Concretely, before this pass's fix:
- `MaterialRequestController`: **every** method that received a route-bound `MaterialRequest`
  (`show`/`edit`/`update`/`destroy`/`process`/`complete`/`reopen`/`cancel`/`pdf`) had *no* tenant
  check of any kind — a user could view, edit, or download the PDF of another tenant's Material
  Request purely by changing the `{materialRequest}` ID in the URL. `MaterialRequest` carries no
  `TenantScope`.
- `PpeController::showReplacementRequest()`/`replacementRequestPdf()`: identical gap, identical fix.
- `KpiRecordController::index()`: the tenant-scoping `departments` join was applied only inside
  `when($companyId, ...)` — on the default landing state (no `?company_id=` selected, i.e. every
  normal page load), it returned every tenant's KPI records.

**The general lesson**: `Company::query()->pluck('id')` (already `TenantScope`-filtered) is the one
correct tenant boundary in this codebase — every access-control method needs it as an *unconditional*
floor, never only inside a `when($optionalFilter, ...)` closure, and never bypassed by a "sees
everything" role check without first re-establishing where "everything" ends. When multi-tenancy is
retrofitted onto an existing feature, grep for every existing `scopeVisibleTo()`/`isSuperAdmin()`-
bypass/`when($companyId, ...)`-only pattern in the codebase and re-audit each one individually — the
tenant boundary does not appear automatically just because `TenantScope` was added to `Company`.

## CRITICAL — Known Pitfall (v2.2.0): a `scopeXyz()` that joins a second table can make an
## already-correct, already-working caller's unqualified column references retroactively ambiguous

`Employee::scopeOrderedForDisplay()` (`app/Models/Employee.php`) INNER JOINs `departments` and LEFT
JOINs `positions`, then `select('employees.*')`. Every column both those tables also happen to have
(`id`, `company_id`, `department_id`) becomes genuinely ambiguous SQL the moment this scope is
chained onto a query that referenced any of those columns unqualified elsewhere in the same chain —
regardless of whether that unqualified reference was written before or after `orderedForDisplay()`
in the fluent chain, since MySQL evaluates the final assembled statement as a whole. This is exactly
what caused the real, confirmed Man-Hour HTTP 500: `ManHourController::index()`'s
`Employee::whereIn('company_id', ...)->...->get(['id', ..., 'company_id'])` call had been written
correctly (schema-valid, no typo) — it only became broken retroactively, the day someone added
`company_id`/`sort_order` columns to `departments`/`positions` (2026-07-16 / 2026-08-11) that this
Employee query itself never touched. Confirmed by static SQL-ambiguity analysis, then cross-checked
against `ProjectController::show()`'s `$availableEmployees` query, which had *already* hit and fixed
this exact hazard for itself and left a comment explaining why every column must be qualified
(`employees.id` not `id`) once `orderedForDisplay()` is in the chain — `PpeController::employees()`
follows the same discipline. `ManHourController` was simply the one call site nobody had gone back
to fix once the hazard became live.

**The general lesson**: when a shared query scope adds a `join()`, EVERY existing caller of that
scope is a live ambiguity risk the moment the joined table gains a same-named column — not just new
callers written after the join was added. Grep every caller of a scope before adding a `join()` to
it (or before adding a column to a table already joined by an existing scope), and qualify every
column reference (`table.column`) in any query that uses `orderedForDisplay()` or a similar
join-adding scope, even if the query "looks" unambiguous today.

## CRITICAL — Known Pitfall (v2.2.0): `WorkCenterService`'s original approvals/PPE-alert queries had
## no tenant boundary at all — a null `company_id` silently meant "show it to everyone, every tenant"

Two real, confirmed cross-tenant leaks existed in `app/Services/WorkCenterService.php` before this
pass, both with the same root shape: a query intended to be "global across companies within one
tenant" was actually global across *every tenant*, because nothing in the query ever consulted
`Company::query()->pluck('id')` (the `TenantScope`-filtered set every other tenant-safe query in this
app resolves via `DashboardStatsService::resolveCompanyIds()`).

- `pendingApprovalsFor()`: only ever narrowed by `$user->company_id === $approvable->company_id`, and
  only when `$user->company_id` was set — but "most internal staff (managers, HSE, Super Admin) have
  a null `company_id` by design" (this method's own pre-existing doc comment), so for the common case
  the check was skipped entirely and every tenant's pending approvals were returned.
- `ppeAlertCount()`: no scoping of any kind — summed expiring/expired PPE across every tenant into
  the topbar bell badge and Work Center's own alert count.

**The general lesson**: a per-user or per-company narrowing check (`if ($user->company_id) {...}`)
is not a substitute for a tenant boundary, and a check that's conditionally skipped for the *common*
case (most users have a null field) is really no check at all for that case. Any query aggregating
across "the current company/companies" — not just search, not just one controller — needs
`resolveCompanyIds()` (or the equivalent relation-based scope) applied unconditionally, checked
first, before any narrower per-user filter runs on top of it. See also the Global Search fix in this
same pass (`GlobalSearchController`), which had an identical class of gap across every one of its
original 8 categories.

## CRITICAL — Known Pitfall (v2.1.0): a fully-built enforcement mechanism with zero call sites is
## indistinguishable from "working" until someone greps for its callers, not just its definition

A full SaaS Package/Subscription/Module/Workspace entitlement system existed end-to-end at the data
and service layer — `Package::hasFeature()`, `EntitlementService::tenantCanUseModule()` /
`tenantCanUseWorkspace()`, `Tenant::modules()`/`workspaces()` pivot relations, and a working Platform
Admin "Tenant Grants" UI to edit them — and looked, from reading any single one of those files in
isolation, like a real, enforced feature. It was not: a whole-codebase grep found **zero callers** of
`hasFeature()`/`tenantCanUseModule()`/`tenantCanUseWorkspace()` anywhere outside their own
definitions and seeders. `EnforceTenantEntitlement` (the one middleware positioned to use them) only
ever checked subscription status (`tenantIsUsable()`), never per-tenant grants. Separately,
`PlatformController::storeTenant()` created a `Subscription` row pointing at a chosen `Package` but
never translated that choice into any `Module`/`Workspace` grant at all — so a brand-new tenant's
Package selection had **no effect whatsoever** on what it could access; access was gated only by role
(`isHse()`/`isHrd()`/etc.), identically regardless of plan.

**Why this matters beyond this one instance**: "the model/service/UI for X exists" is not evidence
that X is enforced. The only way to confirm a check is real is to find where it's actually called in
the live request path (a middleware, a policy, a controller gate) — not to find where it's defined.
Static reading that stops at "the function exists and looks correct" will confidently report a
feature as working when it is architecturally inert.

**Fix shape used**: (1) added a canonical `Package::defaultWorkspaceKeys()`/`defaultModuleKeys()`
mapping (Starter=HSE, Professional=HSE+HRD, Enterprise=all); (2) applied it in `storeTenant()` so
new tenants get real grants matching what they bought; (3) wired the actual per-workspace check into
`EnforceTenantEntitlement`, but behind a **new, separate, off-by-default** config flag
(`saas.enforce_workspace_entitlement`) rather than turning it on immediately — because this
environment had no way to verify the one live production tenant's existing grant rows actually cover
what it currently uses, and flipping on new enforcement blind risks locking out the only real tenant
this system serves. See [[ARCHITECTURE|ARCHITECTURE.md § SaaS Entitlement chain]] for the related "must not require a
whole paid module for shared core data" rule this same audit pass also found and fixed
(`User::canManageManHour()` incorrectly required HRD for Man-Hour, which is genuinely shared HSE
data too — see `App\Models\User::canManageManHour()`'s own doc comment).

## Known Pitfall (v1.11.3): a new shared component must be adopted by existing callers in the SAME
## pass it's introduced, not deferred as a "separate follow-up" — deferred adoption reliably never
## happens

`StatCard`'s own doc comment (written in the Milestone 4 foundation pass) explicitly said
consolidating the Dashboard/Home/PPE Dashboard's three separate stat-card implementations onto the new
shared component was "a separate follow-up, not bundled here." That follow-up never happened — by the
v1.11.3 Global Dashboard/Overview UX Rework (several major passes later), Main Dashboard still had its
own local `PrimaryCard` and PPE Dashboard still had its own local `StatCard`, both nearly identical to
the shared one, both never migrated. Same pattern with `DepartmentCalendarWidget`: built specifically
so the Main Dashboard's Management Calendar and every department's Department Calendar would share one
component, but Main Dashboard's own usage was hand-duplicated inline instead — the "shared" component
was actually only adopted by 5 of its 6 intended callers.

**The lesson**: when introducing or meaningfully changing a shared component, adopt it into every
existing page that has the pattern it replaces in the SAME commit/pass — "swap the old ones over
later" is not a real plan, it's how the codebase ends up with 2-3 near-identical implementations of
the same card for months. If adoption is genuinely out of scope for the current task, say so
explicitly and flag it as follow-up work in the PR/commit description (not just a code comment), so
it's visible rather than silently forgotten.

## CRITICAL — Known Pitfall (v1.11.2, production incident #4): this codebase has TWO equally-valid
## tenant company-ID patterns (`Collection` and plain `array`) — a shared service consumed by both
## must accept `Collection|array`, not whichever one its first caller happened to use

`CalendarService` (extracted from `CalendarController`, which resolves tenant company IDs via
`Company::query()->pluck('id')` — a `Collection`) was typed `Collection $companyIds` throughout. Every
dashboard controller (`DashboardController` and all five department dashboard controllers) resolves
company IDs via `DashboardStatsService::resolveCompanyIds()` instead, which returns a plain `array`
(`Company::query()->pluck('id')->all()`, or `[$companyId]` when one company is selected) — and is
already used correctly by ~90 other `whereIn('company_id', $companyIds)` queries across those same
controllers, since Eloquent's `whereIn()` accepts either type natively. When `CalendarService` gained
a second and third caller group (the Main Dashboard's Management Calendar widget, then five
department Calendar widgets) that all use the array-returning pattern, every one of those six call
sites threw `TypeError: Argument #1 ($companyIds) must be of type Collection, array given` in
production — confirmed from the actual stack trace, not guessed.

**Fix**: widened every `$companyIds` parameter in `CalendarService` (public and private methods alike
— PHP throws at the first parameter type mismatch it reaches, so an inner private method must accept
the same union as the public method that forwards its argument unchanged) to `Collection|array`.
Verified safe with zero internal behavior change: every use of `$companyIds` inside that class is
`whereIn('company_id', $companyIds)` — never a `Collection`-only method called directly on the
parameter — confirmed by inspection before widening the type, not assumed.

**The lesson**: before giving a shared service a strict single-type parameter for something as
common as "a list of tenant-scoped IDs," check whether this project already has more than one
established way to produce that value (it does: `Company::query()->pluck('id')` and
`DashboardStatsService::resolveCompanyIds()` are both correct, both used dozens of times, and return
different types). A new shared service sitting downstream of both needs to accept both, not silently
assume whichever pattern its first caller happened to use.

## Known Pitfall (v2.64.0) — an assertion that reads the COMPONENT FILE cannot see copy that arrives
## as a server prop, and will pass for years while the page says something else

v2.61.0 made the landing page English and added a test to pin it:

```php
$welcome = file_get_contents(base_path('resources/js/Pages/Public/Welcome.jsx'));
$this->assertStringNotContainsString('Untuk tim yang', $welcome);
```

It read the JSX file. The "How It Works" bodies and all seventeen FAQ answers come from
`PublicController`'s own constants and arrive as Inertia props, so the test never saw them — and both
were still Indonesian **three releases later**, on a page whose test suite said it was English.

Worse, the FAQ was also **factually stale**: it described three plans four releases after the
four-tier model shipped, naming Professional as adding "Management visibility", never mentioning
Business, and quoting seat capacities for a catalogue that no longer existed. A public FAQ
contradicting the pricing table two sections above it.

**Assert against what the page RENDERS, not against where you happened to author it:**

```php
$props = $this->get(route('home'))->viewData('page')['props'];
$copy  = json_encode($props['steps'] ?? [], JSON_UNESCAPED_UNICODE);
```

This is the same blind spot shape as the v2.63.0 isolation gap — a coverage test scoped to the place
its author was looking rather than to the thing it claims to cover. When you write an assertion about
"the page", ask what parts of the page your source of truth cannot reach.

## Known Pitfall (v2.64.0) — observer-driven state that gates APPEARANCE, not just animation, needs a
## deadman switch

`Reveal`/`useReveal` can afford a silent IntersectionObserver because it only ever ADDS a keyframe:
the content is visible either way (that inversion is itself a v2.59.0 pitfall entry). It is easy to
reuse the same pattern for something that is **not** animation-only and reintroduce the bug in a new
shape.

`OperatingLoop` lights each stage as it scrolls into view. That state drives **colour**, so an
observer that never reports leaves every station pale — the section looks broken rather than
un-animated. This is not hypothetical: it was found during verification on a page whose animation
clock had frozen (a `CSSTransition` stuck in `playState: "running"`, and every existing `Reveal` on
the page reporting zero animated elements).

**An IntersectionObserver always delivers an initial callback for each observed target.** So if
nothing has arrived shortly after mount, the mechanism is not running, and the honest resting state
is "fully lit":

```js
let reported = false;
const observer = new IntersectionObserver((entries) => { reported = true; /* ... */ });
const deadman = window.setTimeout(() => { if (! reported) setLit(steps.length); }, 1200);
```

Costs the choreography, never the design. The rule: **if observer state decides how something LOOKS
at rest, the resting state must be reachable without the observer.**

### Two smaller traps from the same component

- **`vectorEffect="non-scaling-stroke"` makes `strokeDasharray` resolve in PIXELS**, not in the
  normalised `pathLength` units. A "one dash travels the line" effect became fourteen dashes across
  an 870px rail. If you normalise with `pathLength`, do not also ask for non-scaling strokes.
- **Two responsive layouts of the same list are BOTH in the DOM** (`hidden lg:block` / `lg:hidden`).
  They need separate ref arrays: one shared array is overwritten by whichever renders last, leaving
  the observer watching `display:none` elements that can never intersect.
## CRITICAL — Known Pitfall (v2.63.0): a coverage test that only inspects models WITH `company_id`
## passes vacuously for every model without one — and those are owned too

v2.62.0 made ownership structural for every table carrying a `company_id`, and asserted it with a
test that walked *models carrying a `company_id`*. The tables that have none were never examined,
so the test was green while three of them leaked. Found by the follow-up audit:

| Table | Owned through | What it exposed |
|---|---|---|
| `kpi_records` | `employee` | `KpiInputController::create()` ran `KpiRecord::latest()->limit(10)->get()` — the ten most recent KPI records **in the installation**. 426 foreign rows were readable in development, including free-text `remarks`. |
| `daily_reports` | `project` | `DailyReportPolicy` checked only a role capability and `show()` had no guard at all: `GET /daily-reports/{id}` returned any tenant's report, and `PUT` could modify it. A cross-tenant **write**. |
| `approvals` | polymorphic `approvable` | `ApprovalEngine::authorize()` asked "does this person hold the deciding role", never "whose record is this". A Super Admin of one tenant could approve or reject another tenant's material request, purchase order or permit. |

**The rule.** A table with no `company_id` is not unowned — it is owned one join away. Give it
`BelongsToCompanyThrough` and name the relation:

```php
use BelongsToCompanyThrough;

protected string $companyOwnerRelation = 'employee';
```

**The coverage test now inspects EVERY model**, and a model is only allowed to have no isolation
scope if it is named in `$declaredGlobal` with a reason. A new model that is neither fails the
suite, which is the property the first version of this test was supposed to have.

**Two things that are easy to get wrong when scoping through a relation:**

- **Soft deletion is not an ownership question.** A plain `whereHas('employee')` also hides the
  records of every soft-deleted employee — which would silently change historical HSE totals as a
  side effect of a security fix. The trait applies `withTrashed()` to the parent; only
  `CompanyOwnedScope` is doing the isolation.
- **Some tables have several possible parents.** A goods receipt can arrive against a purchase
  order, a material request, or straight into a warehouse, and any of the three may be null. It
  gets an explicit OR-shaped scope of its own rather than being forced through a trait that walks
  exactly one relation.

## Known Pitfall (v2.63.0) — a relation from a SHARED table into a SCOPED one silently becomes a
## per-tenant question

`ppe_types` is deliberately installation-wide reference data. `employee_ppe` became tenant-scoped in
v2.62.0. So this delete guard quietly changed meaning:

```php
if ($ppeType->assignments()->exists()) {          // "is MY tenant using it"
    return back()->with('error', 'Cannot delete ...');
}
```

It now answers "is *my* tenant using this type", which would let one customer delete a PPE type
another customer is actively issuing, orphaning their records. The installation-wide question has to
say so:

```php
if ($ppeType->assignments()->withoutGlobalScopes()->exists()) {   // "is anyone, anywhere"
```

Whenever you add a scope to a table, check what relations point INTO it from unscoped tables. The
scope is correct; the caller's question changed underneath it.
## CRITICAL — Known Pitfall (v2.62.0, production incident #4): "safe transitively" is a property of
## the DATA MODEL, not of the QUERIES — a company-owned table needs its own global scope

**The incident.** A newly provisioned Starter tenant opened Reports and saw an existing customer's
departments and employee names. Read leakage, not data duplication — the new tenant owned no
foreign rows; it could simply see them.

**The reasoning that produced it** was written down in ARCHITECTURE and sounded right:

> `TenantScope` is applied to `Company` only. Everything beneath a Company (departments, positions,
> employees, ...) is safe *transitively*, because it can only ever reference a Company this scope
> already filtered.

Every clause of that is true about the DATA. None of it is true about a QUERY. A row's `company_id`
does point at exactly one Company; nothing forces a query to go looking at Company at all.
Transitive safety only held for queries that voluntarily started from
`Company::query()->pluck('id')`, which made isolation a convention across 66 models.

**The shape that broke it** — and it is an idiom, not a typo, which is why it spread:

```php
// The filter is OPTIONAL. When no company is chosen -- the default -- when()
// is a no-op and this returns every department in the installation.
Department::where('is_active', true)
    ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
```

A static sweep found **72 sites** of that shape across controllers and services. `Employee::find($id)`
reached another tenant's employee outright, so the leak was not limited to lists.

**The fix, and the rule going forward.** Ownership is structural now: `BelongsToCompany` +
`CompanyOwnedScope` on every model with a `company_id`, `UserTenantScope` on `users`. A forgotten
`where` clause is no longer a breach.

- **Adding a company-owned table?** `company_id` NOT NULL, `use BelongsToCompany;`, done. A test
  asserts every model with a `company_id` carries an isolation scope, so you cannot forget.
- **Never** "fix" a cross-tenant leak by filtering in the frontend, in a Blade view, or in a
  collection `->filter()` after the query. The query is the boundary.
- **Never** use `when($companyId, ...)` as the only company constraint. It means "filter if the
  user picked one", which is a UI convenience, not a security control.
- **Crossing tenants on purpose?** Say `withoutGlobalScope(CompanyOwnedScope::class)` or
  `withoutGlobalScopes()`, on that statement, with a comment saying why. Provisioning, the Platform
  Super Admin surface and `DispatchScheduledReports` legitimately do. "I mean across tenants" is
  greppable; "I forgot a where clause" is not.
- **A null owner is not a shared row.** Null `company_id` means unowned, and unowned means visible
  to nobody. If null genuinely means "the built-in default everyone starts from", override
  `companyScopeAllowsGlobalRows()` — only `kpi_categories`, `numbering_formats` and
  `numbering_sequences` qualify. If the row is owned through a different column, write that scope
  explicitly (see `ActivityLog`, `Task`).

## CRITICAL — Known Pitfall (v2.62.0): `ResolveTenant` must run BEFORE `SubstituteBindings`, and
## `web(append:)` does not do that

`bootstrap/app.php` carried this comment for four milestones:

> ResolveTenant runs FIRST -- everything after it needs the tenant context already resolved.

It did not. `$middleware->web(append: [...])` puts middleware at the **end** of the `web` group,
which is *after* Laravel's own `SubstituteBindings`. So every implicit route-model binding in the
application — `show(Task $task)`, `show(Employee $employee)`, all of them — resolved its record
with **no tenant in context**.

That was invisible while company-owned models had no global scope: an unscoped binding query finds
the row, and the controller's `assertInCurrentTenant()` then rejects a foreign one. The moment
`CompanyOwnedScope` existed, the same binding ran against `TenantScope`'s fail-closed
`tenant_id = -1` and returned **404 for a record the user legitimately owns**.

The fix is to remove `SubstituteBindings` from the group and re-add it after `ResolveTenant`:

```php
$middleware->web(remove: [SubstituteBindings::class], append: [
    ResolveTenant::class,
    SubstituteBindings::class,
    // ...
]);
```

Three positions matter and all three are now asserted by `TenantIsolationTest`:

| Order | Why |
|---|---|
| `StartSession` first | `ResolveTenant` reads `$request->user()`, which needs the session. |
| `ResolveTenant` next | so a tenant exists... |
| `SubstituteBindings` after | ...before any record is looked up by id. |

**How to check the real order** — do not trust the comment, print it:

```bash
php artisan tinker --execute="\$k=app(Illuminate\Contracts\Http\Kernel::class); \$r=new ReflectionClass(\$k); \$p=\$r->getProperty('middlewareGroups'); \$p->setAccessible(true); foreach(\$p->getValue(\$k)['web'] as \$i=>\$m) echo \$i.' '.\$m.PHP_EOL;"
```

## Known Pitfall (v2.62.0) — a global scope on a model turns an assertion about the DATABASE into an
## assertion about the CURRENT TENANT

Two existing tests broke on the scope change, and neither was a bug in the fix:

- `PermitToWork::where('ptw_number', 'PTW-2026-00001')->count()` was asserting that two companies
  may hold the same document number. Post-scope it returns 1, because it now answers "how many can
  *I* see". A test about what the database HOLDS needs `withoutGlobalScopes()`; a test about what a
  user SEES does not.
- A cross-tenant update went from **403 to 404**, because route model binding stopped resolving the
  foreign record at all. That is an improvement — 403 confirms the record exists to somebody with no
  right to know — so the expectation was updated rather than the code.
## Known Pitfall (v2.61.0) — a grid or flex item defaults to `min-width: auto`, so ONE `<select>` can
## make a whole page scroll sideways on a phone

`/get-started` scrolled horizontally at 375px. The form is a CSS grid; on mobile it is a single
column. That column's track computed to **445px inside a 367px viewport**, and every element in the
page inherited the over-wide column, so the whole acquisition form — the page a paying customer
fills in — could be dragged sideways on a phone.

Nothing in the markup was too wide. The cause is a spec default that is easy to forget:

> A grid item (and a flex item) has `min-width: auto`, which means **it will not shrink below its
> own min-content width**, regardless of the container.

The min-content width here came from the **Industry `<select>`**: a select's intrinsic minimum is
its longest `<option>`. One dropdown, and the track could not shrink.

**The fix is `min-w-0` on the grid/flex CHILD**, not on the container and not `overflow-hidden` on
an ancestor (which hides the symptom and clips content):

```jsx
<form className="grid gap-6 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
    <div className="min-w-0 space-y-6"> ... </div>   {/* not just space-y-6 */}
</form>
```

`minmax(0, …)` on the explicit `lg:` columns was already doing this job at desktop — which is why
the page looked correct on every desktop check. The *implicit* single column at mobile had no such
guard.

**How to check this, because a viewport-emulation screenshot will not tell you.** Emulated resizes
can report `window.innerWidth` and `documentElement.clientWidth` disagreeing while the resize is in
flight, which produces both false positives and false negatives. Measure inside a fixed-width
iframe instead — it is deterministic and reproducible:

```js
const f = document.createElement('iframe');
f.style.cssText = 'position:fixed;left:-9999px;width:375px;height:812px;border:0';
document.body.appendChild(f); f.src = '/get-started';
// after load:
f.contentDocument.documentElement.scrollWidth >
f.contentDocument.documentElement.clientWidth   // true = horizontal overflow
```

Run it over every public route, not the one you just edited: this defect had shipped and survived
several passes that each checked only the page they were working on.
## CRITICAL — Known Pitfall (v1.11.2, production incident #3): a global middleware's `handle()` can
## only ever receive `(Request $request, Closure $next)` — a type-hinted service as a third
## parameter is silently unreachable and crashes every request

`EnforceTenantEntitlement` was registered globally on the `web` middleware group in `bootstrap/app.php`
(`$middleware->web(append: [EnforceTenantEntitlement::class, ...])`) but declared
`handle(Request $request, Closure $next, EntitlementService $entitlements)`. Laravel's Pipeline always
invokes `handle($request, $next)` — exactly 2 arguments — for middleware registered as a bare class
string. A third `handle()` parameter is populated ONLY for route middleware referenced with an
explicit `:parameter` string (e.g. `'role' => CheckRole::class` used as `role:admin`, where Laravel
splits the string after `:` and appends the pieces after `$next`) — it is never resolved via the
container just because it's type-hinted, the way constructor parameters are. The result: every single
web request, including guest `/login` before authentication even runs, threw `ArgumentCountError: Too
few arguments to function ...::handle(), 2 passed ... and exactly 3 expected` — a full site outage,
confirmed from the production stack trace.

**Fix**: constructor-inject the service instead (`public function __construct(private readonly
EntitlementService $entitlements) {}`), matching the pattern this codebase's own
`HandleInertiaRequests` middleware already uses for `WorkCenterService`. `handle()` now matches
Laravel's real global-middleware contract exactly: `handle(Request $request, Closure $next): Response`.

**The lesson**: before adding any parameter to a middleware's `handle()` beyond `$request`/`$next`,
check how that middleware is registered in `bootstrap/app.php` first. If it's `$middleware->web(...)`,
`$middleware->append(...)`, or any bare-class-string registration, ANY dependency it needs must be
constructor-injected, never a `handle()` parameter — that pattern is reserved for `$middleware->alias()`
entries that routes reference with an explicit `name:param` string.

## CRITICAL — Known Pitfall (v1.11.2, production incident #2): `Schema::create()`'s foreign keys run
## as SEPARATE statements AFTER the table exists — a failed FK leaves a partially-created table, and
## `Schema::createIfMissing()` will then silently skip completing it on retry

Production `php artisan migrate` failed on
`2026_08_25_100112_create_hse_equipment_types_and_inspections_table` with MySQL errno 150 ("foreign
key constraint is incorrectly formed") on `safety_equipment_inspections_safety_equipment_id_foreign`.
Confirmed from Laravel's own source
(`vendor/laravel/framework/.../Schema/Grammars/MySqlGrammar.php`: `compileCreateTable()` builds only
columns + engine/encoding; `compileForeign()` — inherited from the base `Grammar` — compiles every
`->constrained()`/`->foreign()` call as its own subsequent `alter table ... add constraint ...
foreign key` statement) — NOT guessed: **`CREATE TABLE` always fully succeeds first, unconstrained;
each foreign key is added by a separate statement afterward.** When one of those ALTER statements
fails, the table already exists with every column but is missing that FK and every FK queued after
it. `Schema::createIfMissing()` (this project's own retry-safety macro, see the pitfall above it)
checks `Schema::hasTable()` — true once the CREATE succeeded — so a naive retry after any fix would
silently skip re-creating the table and never re-attempt the missing FKs, "succeeding" while leaving
the table permanently unconstrained.

Column types were verified, not assumed, to already match (`$table->id()` and
`$table->foreignId(...)` both compile to `bigint(20) unsigned` — checked against
`vendor/laravel/framework/.../Schema/Blueprint.php`), and `git log --follow` confirmed neither
`safety_equipment`'s nor this migration's definition ever used a legacy `increments()`/int type. With
no live database access to confirm the exact production anomaly (a stale storage engine on
`safety_equipment` — not InnoDB — is the most likely real-world cause of errno 150 when types
genuinely match, e.g. from a moment when `config('database.connections.mysql.engine')` wasn't yet set
to `InnoDB`), the fix makes the migration **self-verifying and retry-safe** instead of guessing at a
one-shot repair:
- Table creation and FK creation are decoupled — the table's columns are created via
  `Schema::createIfMissing()` as plain `unsignedBigInteger` columns (no inline `->constrained()`),
  then each FK is added via a small `addForeignKeyIfMissing()` helper that checks
  `information_schema.table_constraints` for that exact constraint name before adding it — safe to
  re-run against a table that's missing, partially created, or already fully constrained.
- `ensureInnoDb()` reads `information_schema.tables.engine` for `safety_equipment` and converts it to
  InnoDB in place ONLY if it isn't already — a standard, non-destructive MySQL operation that never
  touches column definitions, the primary key, or data.

**The lesson**: any migration using `Schema::createIfMissing()` alongside inline
`->foreignId()->constrained()` calls has a hidden retry hazard — if the FK step ever fails partway,
the retry-safety macro that's supposed to make the migration safe to re-run instead makes it silently
incomplete. New migrations combining table creation with foreign keys on a table that isn't a
long-established, already-proven-safe reference (like `companies`/`users`, used successfully by
dozens of migrations) should decouple creation from FK-addition and guard each FK individually, the
same way this fix does.

## CRITICAL — Known Pitfall (v1.11.2, production incident #1): a new migration's filename timestamp
## must be LATER than every existing migration in the repo, never the real wall-clock date

This project's migration filenames are a **fictional forward-dated sequence** (already stamped past
`2026_08_25` at the time of writing, months ahead of any real calendar date) — not real creation
dates. `2026_08_15_100113_add_is_management_event_to_calendar_events_table.php` and
`2026_08_15_100114_create_hse_checklist_templates_table.php` were added using the actual current
date (`2026-08-15`), which put them BEFORE `2026_08_24_100111_create_calendar_events_table.php` in
filename sort order — the exact migration the first of the two alters. Laravel runs migrations in
filename order, so on the very first production `php artisan migrate` this failed hard:
`SQLSTATE[42S02]: Base table or view not found: calendar_events doesn't exist`, and because
`migrate` stops on the first exception, it also silently blocked every migration after it in sort
order (the ~20 migrations from `2026_08_16` through `2026_08_25`, none of which had run yet either).

**Fix**: renamed both files to `2026_08_26_100113`/`2026_08_26_100114` (after the latest existing
migration), confirmed safe because production had never successfully run the failing migration (it
was the one erroring, and `calendar_events` was confirmed not to exist) — no migration batch/`ran`
history needed reconciling. A full repo-wide audit script (`Schema::create`/`createIfMissing` vs.
`Schema::table`/`->constrained()`, cross-referenced against filename sort order) confirmed zero other
ordering violations across all 134 migration files.

**The lesson, going forward**: before adding a new migration, check `ls database/migrations | sort |
tail -5` (or equivalent) and pick a timestamp *after* the latest existing file — never `date +%Y_%m_%d`
verbatim, since this repo's fictional sequence has consistently run ahead of the real calendar. This
is now the required first step whenever creating a new migration file in this project.

## Known Pitfall (v1.11.2) — a new route prefix must be added to config/departments.php in the SAME
## commit it's registered, not a follow-up

`HseEquipmentTypeController`'s routes (`hse-equipment-types.*`) were added in v1.11.1, but
`config/departments.php`'s `hse` prefix list was never updated to include `hse-equipment-types`.
Because `RestrictDepartmentAccess` fails CLOSED for any prefix not present in that map (a deliberate
v1.10.5 change — see that middleware's own doc comment), an HSE Department User (`department_key =
'hse'`) would have gotten a 403 on a page that should have been theirs. Found and fixed in v1.11.2
while wiring the frontend for those same routes. **The lesson**: `routes/web.php` and
`config/departments.php` are two halves of one fact ("this route belongs to department X") — adding
a route without its prefix entry doesn't fail loudly at write time, it fails quietly later as a real
user getting blocked from a page that's actually theirs. When adding any new `Route::post/put/delete`
inside an HSE/HR/etc.-gated group, add its prefix to `config/departments.php` (or
`RestrictDepartmentAccess::UNIVERSAL_PREFIXES` if it's genuinely cross-department) in the same change,
and grep for the new prefix in that file as a verification step before considering the route "done".

## Known Pitfall (v1.11.2) — a new department Dashboard controller must be scoped from creation, not
## assumed safe by analogy

`HrDashboardController` had zero `company_id` scoping on every query (`Employee::count()`,
`LeaveRequest::where(...)`, etc.) — a full cross-tenant data leak on the HR Overview page. This is
the exact bug class already fixed in `HseDashboardController`/`ProjectManagementDashboardController`/
`LogisticsDashboardController`/`ProcurementDashboardController` (each carries its own doc comment
explaining the same fix), but `HrDashboardController` was written earlier and never got the same
audit pass. **The lesson**: "this bug class was already fixed in module X" is not evidence it was
fixed everywhere it appears — when auditing for a known leak pattern, check every sibling controller
by name, not just the ones a task happens to touch. `DashboardStatsService::resolveCompanyIds()` is
the one reusable fix; grep for controllers extending `Controller` under `Dashboard` that construct
queries without it before assuming a department dashboard is tenant-safe.

## Convention update (v1.11.1) — resolve, don't just gate, an unsafe default when the fix is real

`EnforceTenantEntitlement` (v1.11.0) was shipped gated behind `config('saas.enforce_entitlement')`,
default `false`, because its "usable" check conflated "explicitly suspended by an admin" with
"expired by a possibly-stale seeded date" — flipping it on blind risked bricking the production
tenant. v1.11.1 resolved the ROOT problem instead of leaving the gate off forever:
`Subscription::isBlocked()` now hard-blocks ONLY on an explicit `suspended`/`cancelled` status
(always a deliberate Platform Admin action); an expired-by-date or completely missing Subscription
row is `isDegraded()` instead — a warning, never a block. This made it safe to flip the config default
to `true`. **The lesson**: a default-off safety gate on a new enforcement layer is the right move when
you can't verify production data from a coding session, but it's a temporary fix, not the permanent
one — the permanent fix is making the enforcement logic itself provably safe regardless of what the
data says (only react to explicit admin actions, never to an unattended date), so the gate can come
off default-on rather than staying off indefinitely. Still overridable per-install via
`SAAS_ENFORCE_ENTITLEMENT=false` for an operator who wants it fully off regardless.

## Operational runbook — diagnosing "a Department User can still access other departments"

`RestrictDepartmentAccess` (registered globally in `bootstrap/app.php`, re-verified there directly
during the v1.10.8 pass, not assumed) has exactly ONE bypass condition: `! $user->department_key`
(line 60). There is no role-based bypass anywhere in it. So if a user can reach another department's
pages, `department_key` is not actually set to a value on that account — full stop, nothing else in
the chain can cause this. Diagnose via `php artisan tinker` on the production server:

```
$u = \App\Models\User::where('email', 'the-users-email@example.com')->first();
$u->only(['id', 'name', 'email', 'role', 'department_key', 'tenant_id']);
```

- If `department_key` is `null`: the account was created/last edited before v1.10.7 (which added the
  Department Restriction field to Settings → Users), or was edited since but "None" was left
  selected. Fix via the UI: Settings → Users → Edit → set Department Restriction → Save. This is
  NOT retroactive — every account created before v1.10.7 needs this done explicitly, one at a time;
  nothing does it automatically, by design (changing an existing account's access scope should never
  happen silently).
- If a same-session emergency fix is needed before someone can reach Settings, the safe one-line
  tinker equivalent of that same UI action (assign a value already present in `config('departments')`,
  never touch any other field) is: `$u->update(['department_key' => 'hse']);`
- **v1.10.9**: a safer, no-tinker-required alternative now exists —
  `php artisan users:assign-department {email} {department}` (`AssignUserDepartment` command).
  Validates the department key against `config('departments')`, refuses to touch a
  `super_admin`/`platform_admin` account unless `--force` is passed (protects against accidentally
  locking out an administrator), and only ever touches the one named account. Prefer this over raw
  tinker going forward — same effect, less room for a typo'd field name or an accidentally-broad
  query to touch the wrong row.
- If `department_key` is already correctly set and the symptom persists, the next thing to check is
  NOT the RBAC code — it's `git log -1 --format=%H -- public/build/manifest.json` on the server vs.
  `git log -1 --format=%H` (are they from the same deploy?), per the stale-`public/build` pitfall
  documented separately below.

## CRITICAL — `department_key` had no admin-facing UI at all, found during the v1.10.7 RBAC audit

Reported in production: a user assigned "department HSE" could still open every other department. Root
cause traced through the full chain (User → tenant → department_key → RestrictDepartmentAccess →
config/departments.php → routes) and found NOT in any of those enforcement layers — they were already
correct as of v1.10.5's fail-closed fix. The actual bug: **`SettingsController::storeUser()`/
`updateUser()` only ever accepted and validated `role` (`super_admin/hse/hrd/manager/warehouse`) —
there was no `department_key` field anywhere in the form, its validation, or the Users tab's UI at
all.** `role` (what actions a user may perform) and `department_key` (which department's navigation/
routes a user may even reach) are two separate, orthogonal mechanisms — a tenant admin picking "HSE"
as the role reasonably expected that alone to restrict the account to HSE's navigation, and it never
did; every user ever created through the real UI silently stayed `department_key = null`, i.e. a full
Administrator for navigation-restriction purposes regardless of role. Fixed by adding the field to
both the validation (`Rule::in()` against a new `assignableDepartmentKeys()` helper, itself derived
from `config('departments')` minus the two non-assignable 'reports'/'administration' entries) and the
Users tab UI — which also had no Edit dialog at all (`settings.users.update` existed and was routed,
but nothing in the frontend ever called it), added alongside it since fixing Create alone would do
nothing for an already-existing account. **Lesson: a backend mechanism (migration + model column +
middleware enforcement) being fully built does not mean it's actually usable — always check whether
the admin-facing control surface for it exists at all**, the same class of gap as `public/build`
below (a piece of the chain that's easy to verify individually and easy to never notice is completely
missing end-to-end).

## CRITICAL — confirmed stale `public/build`, found during the v1.10.6 department audit

This project deliberately commits its compiled Vite frontend to git (`.gitignore` has
`# /public/build`, intentionally commented out — see that line's own git history, commit
`e52779e "Add production build assets"`), because `docs/ADR/027-deployment-architecture-redesign.md`'s
documented flow is `git pull → composer install → npm run build → deploy → cache → ready`, run via
`deploy.sh`/`php artisan app:deploy`.

**`public/build/manifest.json`'s last commit is `873f65f` ("Milestone 4, Workstream A3: Shift &
Roster Management")** — verified directly via `git log --oneline -1 -- public/build/manifest.json`,
not assumed. Every commit since then (all of HSE Workstream B, Procurement Workstream C, and the
entire Acceleration Mode — dozens of commits, the majority of this project's Milestone 4 work) has
touched `resources/js/*` without a single corresponding rebuild of `public/build/*`.

**This fully explains a real production symptom reported after the previous two audit passes**:
Warehouse, Asset Management, Maintenance, Quality Control, and Procurement all appeared to show only
"Overview" / the generic "This department is on the IOMS roadmap but hasn't been built yet." Coming
Soon page in production, and HSE appeared to have several modules "locked" — even though the actual
source code (verified repeatedly, across two prior audit passes) has real, working pages for all of
it. **None of that was a code bug.** The production server is very likely still serving a frontend
bundle compiled before any of that work existed, regardless of what commit `HEAD` actually points to.
No amount of further editing in `resources/js/` will change what's served until the bundle is
actually rebuilt.

**No code fix is possible for this from within a coding session** — a bundle rebuild is an
operational/deploy action, not a source change, and no `npm`/`node` binary has ever been available in
this project's own AI-coding-session environment (confirmed repeatedly). The fix is to actually run
the documented deploy flow (`deploy.sh` or, at minimum, `npm run build && git add public/build && git
commit` followed by pulling that commit to production) against the current `main` branch. Until that
happens, treat any "X isn't showing up in production" report with this as the FIRST hypothesis to
rule out — check `git log -1 -- public/build/manifest.json` before assuming a code-level navigation/
RBAC/entitlement bug, the way the two prior audit passes (reasonably, but incorrectly in hindsight)
did.

## Migrations

- **Additive, never destructive, for anything that might already hold real data.** When a column's
  shape needs to change (e.g. widening an enum, making a foreign key nullable), write a migration
  that adds/alters — never one that drops and recreates a table as routine logic. See "Known
  Pitfall #1" below for exactly why this matters, not just as a style preference.
- **Backfill existing rows explicitly, don't leave them null-and-hope.** When adding a required
  column to a table that already has rows (e.g. `company_id` on `departments`, then `positions`),
  the migration backfills every existing row to a sensible value *before* enforcing `NOT NULL` —
  usually the most accurate value derivable from existing relationships (a position's own
  department's company, for example), falling back to a documented default company only when
  nothing more specific is derivable.
- **Widening a real MySQL `ENUM`** is done via raw `DB::statement("ALTER TABLE ... MODIFY COLUMN
  ... ENUM(...)")`, not a schema-builder enum helper — Laravel's schema builder has no first-class
  "add one more allowed value" operation. Existing rows with already-valid values are unaffected;
  this only widens what's *allowed* going forward.
- **Verify the actual table name before writing a foreign key.** `->constrained()` without an
  explicit argument guesses the referenced table by stripping `_id` and naively pluralizing the
  column name. Most of the time that's correct (`company_id` → `companies`). It is **not** correct
  for `employee_ppe` (deliberately singular — see `MODULES.md`'s PPE section) or any other
  intentionally non-standard table name. When in doubt, check the model's `protected $table`
  property or the table's original creation migration directly — don't assume standard
  pluralization holds.
- **The same naive-pluralization risk applies to the *model*, not just its foreign keys — verified
  the hard way in production.** `PermitToWork` had no `protected $table` at all, so Eloquent
  inferred one from the class name: snake_case (`permit_to_work`) then pluralize only the last word
  (`work` → `works`) → `permit_to_works`. The actual migration
  (`2026_08_20_100067_create_permits_to_work_table`) — and the FKs in `gas_test_records`/
  `loto_records` that correctly `->constrained('permits_to_work')` — all use `permits_to_work`
  (pluralized as a whole compound noun, matching how a person would actually say it). The mismatch
  shipped silently because nothing exercised `PermitToWork::query()` in any environment that would
  have surfaced it before a real HSE Dashboard widget (`HseDashboardController`'s `openPermitsCount`)
  hit it in production: `SQLSTATE[42S02]: Base table 'permit_to_works' doesn't exist`. Fixed with an
  explicit `protected $table = 'permits_to_work';` — the existing, already-migrated production table
  is the source of truth; the model was wrong, not the schema. **Whenever a model's class name is a
  multi-word compound noun (`XToY`, `XOfY`, or similar), don't trust Eloquent's default table-name
  inference — check it against the model's own creation migration explicitly**, the same "verify,
  don't assume" instinct as the `->constrained()` pitfall directly above.
- **Double-check new routes land in the right middleware group**, especially when adding routes
  near an existing `role:super_admin`-restricted block. This has happened for real: an entire new
  module's routes were once accidentally nested inside a Super-Admin-only group, silently locking
  out the actual intended users. After adding routes, check indentation/nesting against the
  surrounding `Route::middleware(...)->group(...)` structure, not just that the route exists.
- **Use `Schema::createIfMissing($table, $callback)`, not `Schema::create()`, for every new table.**
  A drop-in replacement (registered as a macro in `AppServiceProvider`) that's a no-op if the table
  already exists. This exists because a deploy interrupted between a `CREATE TABLE` succeeding and
  Laravel recording the migration as run (a real, verified risk on shared hosting's aggressive
  script-timeout kills — see `docs/ADR/027`) leaves the table behind with no migration record;
  retrying then fails with "table already exists," blocking every migration after it. This has
  happened three times in this codebase's history already (`docs/ADR/025`, `026`, `027`) before
  becoming a standing convention instead of a one-off fix each time. If a table might already exist
  in an *old, incompatible* shape rather than just "already correctly created" (e.g. a migration
  that also adds a column or an index in a separate step), don't rely on the macro alone — guard
  each step individually and reconcile the old shape explicitly, the way
  `2026_08_17_100050_create_numbering_engine_tables.php` does for `numbering_sequences`.

## Status / lifecycle enums

- Prefer a single `status` column with an explicit, small enum over multiple boolean flags, for
  anything with more than two meaningful states.
- **Don't create a stored status value that duplicates a status that already exists elsewhere in a
  related record.** The concrete example: Material Request does *not* have a `pending_approval`
  database value, even though the feature spec that requested the workflow described one — it's
  the *label* applied to `submitted` while the associated `Approval` record's own `status` is
  `pending`. Two tables agreeing to always be in lockstep is duplication, not a real distinction.
  Before adding a new status value, check whether a related model already represents the same fact.
- Reuse the shared `StatusBadge` component's canonical color mapping
  (`resources/js/Components/shared/StatusBadge.jsx`) rather than building a new status-to-color map
  per module.
- **A status the requester has to decode is only half a status** (v2.71.0). `approved`,
  `consolidating` and `processing` are Procurement's accurate vocabulary, and a requester chasing
  their own order should not have to learn the fulfilment workflow to find out whether anything is
  happening. Where a record has an audience beyond the team that operates it, state in one sentence:
  what has happened, who holds it now, and what happens next — see `RequestStage` in
  `Pages/MaterialRequests/Show.jsx`. A deliberate hold gets the same calm tone as any other
  in-progress stage; colouring it as a problem recreates the confusion the state was introduced to
  remove.
- When a model's own status needs guarded transitions (not just any value settable at any time),
  use the `HasWorkflow` trait rather than hand-rolling `if ($old === 'x' && $new === 'y')` checks
  inline in a controller — see `ARCHITECTURE.md`.
- **A status value that only TIME can cause must be derived, never stored** (v2.70.0). Two
  `Subscription` statuses — `expired` and `grace_period` — existed as constants that *nothing in the
  codebase ever wrote*, so a subscription four months past its end date still reported itself as
  `active`, and every surface reading `status` repeated that. The test for this is simple and worth
  applying to any new status value:

  > If the only thing that can move a record into this state is the clock, it does not belong in a
  > column. Derive it from the date on every read.

  A stored time state is correct on the day a job writes it and wrong the moment the underlying fact
  changes, *and* it needs that job to keep running. A derived one cannot drift and needs nothing
  running — which in the subscription case is what makes a stopped cron a delayed invoice rather
  than a locked-out paying customer. `Subscription::status` now carries only the deliberate axis
  (what a human or a verified payment decided); `lifecycleState()` derives the rest. Same rule
  already applied to `Employee::profile_status`, `PurchaseOrderItem::delivered_quantity` and
  `currentDisciplinaryStanding()`. ADR 033.
- **Before adding a status constant, grep for a writer.** All five of the dead values found in this
  codebase (`Subscription::STATUS_EXPIRED`, `STATUS_GRACE_PERIOD`, `Tenant::STATUS_EXPIRED`, and the
  two UI colour-map keys that shadowed them) were readable, mapped in the frontend, and written by
  nothing. `grep -rn "STATUS_X" app/` taking under a second would have caught every one.

## Roles & permissions

- `users.role` is a plain `VARCHAR`, not a real database `ENUM` — adding a new role (e.g.
  `warehouse`) needs zero migration. Do add it to: the `User` model's `ROLE_*` constants and
  `isX()`/`roleLabel()` methods, the relevant Form Request validation `in:` list, and the Settings
  role dropdown — all four, not just one, or the new role will be creatable in the database but
  invisible or unselectable somewhere in the UI.
- For workflow-style actions (approve/process/override), read from `config/workflow.php`'s role
  lists rather than hardcoding a role check inline — see `ARCHITECTURE.md`'s Authorization section
  for why this file exists and what it's preparing for.
- No RBAC package exists (see `ARCHITECTURE.md`). Don't introduce one without first re-reading
  `ADR/006-material-request-workflow.md`'s reasoning for why it was deliberately deferred, and
  confirming the actual need has changed since.

## Navigation

- **Adding a nav item** (v1.7.0+, workspace-based nav — see `ARCHITECTURE.md`'s Navigation
  Architecture section and `ADR/007-workspace-navigation.md`): add it to the right workspace's
  `items` array in `resources/js/lib/workspaces.js`. If it's a new toggleable module, also add its
  key/label to `config/modules.php`'s `available` registry — that's the whole change; the
  workspace switcher, the sidebar filtering, and the Settings → Modules toggle UI are all generic
  and pick it up automatically. Don't add a new item to `AuthenticatedLayout.jsx` directly — that
  file no longer holds the nav item list, only the rendering.
- **Adding a new workspace**: only do this for a domain that has at least one real, built module —
  don't add an empty workspace as a placeholder (a workspace with zero visible items is hidden by
  `getVisibleWorkspaces()` automatically, so an empty one would just be dead code, not a preview of
  something coming). Check `workspaces.js`'s "FUTURE WORKSPACES" comment block first — the intended
  workspace for most planned domains is already decided there.
- **Don't URL-namespace by workspace.** Existing route names/URLs are the single source of truth
  for what a route is; workspace is purely a frontend grouping over them (see `ADR/007`). A route's
  workspace is derived by matching its name's prefix (the part before the first `.`) against each
  workspace item's own prefix — so if a module ever gets multiple route-name prefixes (unusual, but
  possible for a fast-growing module), each prefix needs its own entry pointing at the same
  workspace, or pages on that second prefix will silently fail to auto-select the workspace.

## Caching

- **Be genuinely cautious with `Cache::rememberForever()` for anything whose default value is
  derived from mutable `config()`.** `CompanySetting::get()` originally cached
  `enabled_modules`'s computed default this way; the very first time it ran (before a since-added
  module existed in config) it permanently cached the old list, with nothing to invalidate it when
  the config file itself changed later. Fixing that introduced a *second* bug (the cache key
  changed but the corresponding `set()`'s cache-`forget()` call wasn't updated to match, so saving
  a setting stopped actually invalidating anything). The eventual fix: for a small, rarely-changed,
  cheap-to-query setting, just read the database directly rather than cache it at all — not every
  read needs `rememberForever`, and the settings most likely to need one (something read on every
  single request, like enabled modules) are exactly the ones where getting the invalidation subtly
  wrong is most damaging.
- If you do cache something, make sure whatever busts the cache is actually reachable from every
  code path that changes the underlying value — not just the one you're thinking about right now.

## Company scoping

- The convention, everywhere in this app, is a per-request query parameter (`?company_id=`) — not
  a persistent session/"active company" concept, because none exists. Match this convention for
  any new filterable list rather than inventing a different mechanism.
- A model that's genuinely company-specific master data (departments, positions, and similar)
  should have a required, direct `company_id` — even if it's also reachable transitively through
  another relationship (a position's department, for instance) — because a direct column avoids a
  join for the extremely common "show me X for this company" query, and because it lets the record
  exist correctly even before the more specific relationship is chosen.

## Tenancy scoping (Milestone 2)

- **Every `User::create()` call site must set `tenant_id` explicitly.** It has no safe default —
  `null` is a real, permanent state (Platform Super Admin, see `User::isPlatformAdmin()` and
  `docs/ADR/008-tenancy-foundation.md`), not a data gap that "just happens" to get backfilled. This
  bug was made and fixed twice in the same milestone: `UserSeeder` (new accounts created during
  `db:seed` on a fresh install, since the migration's own backfill only covers users that already
  existed *at migration time*, not ones a later seeder creates) and
  `SettingsController::storeUser()` (new accounts created from Settings → Users in the running app).
  Both silently created accounts that were accidentally Platform Super Admins, which then failed
  every Company-scoped query closed for themselves (`App\Models\Scopes\TenantScope`'s fail-closed
  behavior). If you add another place that creates a `User`, set `tenant_id` there too — don't
  assume a migration backfill will cover it.
- **`TenantScope` (on `Company`) fails closed outside an HTTP request.** `php artisan tinker`, a
  queued job, or a seeder that runs before `DatabaseSeeder` binds a tenant will all see
  `Company::count()` return 0 even when rows exist — this is correct, not a bug, but easy to
  mistake for one while debugging. Bind a tenant manually first:
  `app(App\Support\CurrentTenant::class)->set($tenant)`.
- **`Company`'s global scope does not automatically protect every table beneath it.** Only queries
  that actually go through a scoped `Company` relationship are protected. A controller/dashboard
  widget that queries an operational table directly (KPI records, incidents, etc.) without joining
  through `Company` is not tenant-filtered. This stopped being a theoretical/single-tenant-only
  concern once real multiple tenants existed in production (Master → Tenant Management,
  Milestone 4) — see the growing list of real instances below, each found the same way: reading the
  controller carefully while building or extending something nearby, not by a dedicated audit pass.
  `DashboardStatsService::resolveCompanyIds(?int $companyId): array` (`Company::query()->pluck('id')`,
  falling back to the current tenant's full company list when no specific one is selected — critically,
  an empty list for a company-less tenant is a real "match zero rows" `whereIn`, not "match everything")
  is the one reusable, TenantScope-safe helper every new instance should call, not reimplement.
  Found and fixed: `DashboardStatsService` itself (a brand-new, company-less tenant's Dashboard
  showing another tenant's KPI/employee data), `HseDashboardController` (Milestone 4, Workstream
  B1 — every widget query, found while adding the Safety Observation widget, then again B16 for the
  Open Permits/Overdue Equipment/Overdue P3K/Open CAPA widgets), `IncidentController` (Workstream
  B14 — found and fixed while extending it for Investigation/CAPA), `GoodsReceiptController`
  (Workstream C5 — found and fixed while extending it for Purchase Order integration; this one had
  no `company_id` of its own at all, so the fix derives ownership through whichever parent record —
  `MaterialRequest` or `PurchaseOrder` — the receipt is linked to, via `whereHas()` for the list
  query and a dedicated `assertInCurrentTenant()` for the route-model-bound actions). Flagged, not yet fixed
  (background task, to avoid silently expanding an unrelated change's scope): `PpeController` (the
  KpiCategory-class leak). See `docs/ADR/008-tenancy-foundation.md`'s Consequences section for
  the underlying design tradeoff.

- **v1.10.5 Final Integration Pass — the per-instance ownership guard flagged above was fixed** for
  `CompetencyTypeController`/`ShiftController`/`RosterPatternController` (`update()`/`destroy()` now
  call a private `assertInCurrentTenant()` before touching the route-bound record, matching every
  other Milestone 4 controller).

- **v1.10.5 — `EmployeeController` had the SAME missing-ownership-guard bug, at larger scope and
  higher severity, in the app's original pre-Milestone-4 resource.** `show()`/`edit()`/`update()`/
  `destroy()` had no `assertInCurrentTenant()` at all — only a role check (`authorize('update'|
  'delete', ...)`, i.e. `isAdmin()`), never a check that the route-bound `$employee` actually belongs
  to the current tenant. Any admin from Tenant A could view, edit, or delete any employee record in
  the system by guessing/incrementing the id. Fixed with the same guard used everywhere else.
  **Separately, and more severe: `EmployeeController::index()` and `EmployeeExport` had no BASE
  tenant filter on the underlying query at all** (`Employee::inCompany($companyId)` is a no-op when
  `$companyId` is null, and `Employee` carries no `TenantScope` of its own — only `Company` does).
  Omitting the optional `?company_id=` filter — the default landing state of both the Employee list
  page and the Export button — returned/exported **every tenant's entire employee roster**, not a
  single-record IDOR but a full cross-tenant data leak. Fixed by adding an unconditional
  `whereIn('employees.company_id', Company::query()->pluck('id'))` as the base of both queries, with
  the optional `$companyId` filter still narrowing further within that set (a `$companyId` from
  another tenant now combines to zero rows rather than leaking that tenant's data, so it needs no
  separate validation). `StoreEmployeeRequest`/`UpdateEmployeeRequest`'s raw
  `exists:companies,id`/`exists:departments,id`/`exists:positions,id` were also replaced with
  tenant-scoped `Rule::in()`, matching every Milestone 4 FormRequest. **Flagged, not yet fixed**
  (background task): the same raw-`exists:` pattern is still present in roughly 20 other
  pre-Milestone-4 FormRequests/controllers (Project, MaterialRequest, KpiCategory, KpiRecord, Task,
  DailyReport, LeaveRequest, Milestone, PPE, Settings' Department/Position creation) — large enough
  in surface area that fixing all of it in the same pass that found it would have meaningfully raised
  regression risk with no test runner available to catch mistakes; deliberately scoped as a separate
  follow-up instead of silently expanded here.

- **v1.10.5 — `App\Http\Middleware\RestrictDepartmentAccess` flipped from fail-open to fail-closed.**
  It used to allow any route-name prefix not found in `config/departments.php` ("the map is a curated
  allow-list, not exhaustive, so an unmapped route is more likely an oversight than something to lock
  down"). In practice this meant `config/departments.php`'s `hse` array had gone stale (still only
  `ppe`/`incidents`/`kpi-input`/`kpi-records`/`hse`, predating Workstream B entirely) and every HSE
  route Workstream B actually added was reachable by direct URL from a Department User assigned to
  *any other* department — "unmapped" was silently meaning "unrestricted," discovered only by
  deliberately auditing for it, not by a bug report. `config/departments.php` is now treated as
  exhaustive (cross-checked against every route-name prefix in `routes/web.php` directly), and the
  middleware denies by default; a route genuinely needed by every department regardless of assignment
  goes in the middleware's own small `UNIVERSAL_PREFIXES` list instead of being left out of the map by
  omission. Administrators (`department_key = null`) are entirely unaffected either way — this
  middleware only ever runs for the opt-in Department User mechanism.

- **A previously-flagged "route-name collision" re-checked and found NOT to be real (v1.10.5).**
  This entry used to claim `routes/web.php` registered the name `dashboard` twice -- once for tenant
  users, once under the Platform Super Admin `/platform` group. Re-auditing the actual route
  definitions directly (not just the route-name strings written at each `->name(...)` call site)
  shows the platform group is declared as `Route::middleware(['auth', 'role:platform_admin'])
  ->prefix('platform')->name('platform.')->group(...)` -- the group's own `->name('platform.')`
  prefixes every child route's name, so `Route::get('/', ...)->name('dashboard')` inside it actually
  resolves to `platform.dashboard`, not `dashboard`. There is no collision: `route('dashboard')`
  always resolves to the one tenant-facing route. The earlier note either predates that group
  gaining its `->name('platform.')` prefix, or was written by reading the literal `->name(...)`
  argument text rather than the fully-resolved route name -- worth remembering as its own lesson:
  **when auditing for route-name collisions, always account for group-level `->name()` prefixing,
  not just the string literal passed to each individual route.**

- **Any place that sets Spatie's permission team id must use the same `0` sentinel for a Platform
  Super Admin (`tenant_id` null) that `RolePermissionSeeder` used when assigning their role** — the
  `model_has_roles`/`model_has_permissions` pivot tables require a non-null team id in their own
  primary key, so passing `null` through (the naive `$tenant?->id` translation of
  `User::isPlatformAdmin()`'s convention) makes `->hasRole()`/`->can()` silently return false for
  every Platform Super Admin at runtime, even though the seeder's own `tinker` check looked correct.
  This was caught and fixed in `App\Http\Middleware\ResolveTenant` during the same milestone that
  introduced it — see `docs/ADR/008-tenancy-foundation.md`.

- **Milestone 4, Acceleration Mode — three more tenant-scoping leaks found and fixed live:**
  `LogisticsDashboardController` and `ProjectManagementDashboardController` (found while adding new
  Warehouse/Project widgets — both had ZERO company filtering anywhere, fixed via
  `DashboardStatsService::resolveCompanyIds(null)`), and `GoodsReceiptController` a second time (found
  while adding `warehouse_id`/`item_id` handling for Warehouse integration — same parent-derived
  `assertInCurrentTenant()` pattern as its Workstream C5 fix above, just exercised through a new code
  path). Also **`NcrController::store()`**: derived `company_id` via `Company::query()->value('id')`
  (picks whichever company happens to be first) instead of a validated field — fixed by adding
  `'company_id' => ['required', Rule::in($tenantCompanyIds)]`. Same lesson each time: a leak doesn't
  announce itself until a controller is genuinely reopened to extend it — audit the query scoping of
  any controller you're about to touch, even one that looks finished.

- **Stored-vs-computed balance convention** (established Acceleration Part 1B, `Stock` vs.
  `PurchaseOrderItem::delivered_quantity`): a value gets a REAL stored column, updated atomically
  inside `DB::transaction()` + `lockForUpdate()`, when it's touched by an unboundedly large number of
  events over the record's lifetime (a warehouse `Stock` balance — movements never stop). A value
  stays *computed live* on every read when it sums a small, bounded set of rows scoped to one parent
  (`PurchaseOrderItem::getDeliveredQuantityAttribute()` sums that PO line's own GRN rows only). Don't
  default to "always store" or "always compute" — pick based on which side of that boundary the value
  actually falls on, and say so in a doc comment either way (both examples above do).

- **`StockMovement` always-positive-quantity convention** (Acceleration Part 1B): the `quantity`
  column on a movement row is ALWAYS positive; direction is entirely determined by `type`, checked via
  `StockMovement::isInbound()` against a const `INBOUND_TYPES` array — never a signed quantity that
  has to independently agree with the type. This class of bug is real: Stock Opname's variance can be
  negative (shrinkage) or positive (overage), and the first draft of `StockTransactionController::
  opname()` passed that signed variance straight through as `quantity` under a type that was
  (incorrectly) listed as always-inbound — caught in review before shipping, fixed by recording
  opname variances as real `ADJUSTMENT_IN`/`ADJUSTMENT_OUT` with `abs($variance)` instead of a
  dedicated opname type. If you add a new movement type, ask whether its underlying quantity can ever
  be negative before deciding whether it needs its own type or should map onto an existing
  `ADJUSTMENT_IN`/`OUT` pair like opname does.

## Verification discipline (and its real limits)

- **Update, v1.10.7**: `npm`/`node` became available in the AI coding session environment for the
  first time this pass (confirmed via `node -v`/`npm -v`, not assumed — `node_modules` was already
  installed) — `npm run build` was actually run and succeeded (1897 modules transformed, valid
  `manifest.json`/hashed assets produced), a REAL compile-time check, not the static balance-checking
  substitute described below. `php`/`composer` remain unavailable in this same environment, so
  `php artisan migrate`/`route:list`/PHPUnit still cannot be run from here — PHP-side verification
  for this pass is still the static kind described below. Don't assume either capability going into
  a future session without checking again; environment availability isn't stable across sessions.
- Prior to the above, this project's development had, in practice, never included actually running `php artisan
  migrate`, `npm run build`, or the application in a browser — verification has been static:
  balance/brace checking on every edited file, cross-referencing every `route()` call against
  `routes/web.php`, and matching every Inertia page's destructured props against exactly what its
  controller passes. This has caught real, would-have-shipped bugs, but it is fundamentally not the
  same as a runtime test, and treating "carefully verified statically" as "confirmed working" would
  be overclaiming. When reporting on work like this, say so plainly rather than implying more
  certainty than the verification method actually supports.
- Two genuinely severe bugs were caught this way that a build-and-move-on approach might have
  shipped: the Super-Admin-only route-group mistake, and the settings cache staleness. Both are
  detailed above specifically so the pattern is recognizable next time, not just the individual
  fixes.

## CRITICAL — Known Pitfall (v2.71.0, found three times): a route prefix filed under ONE department when its capability spans several makes both the routing layer AND the entitlement layer stricter than the permission they defer to

IOMS answers "may this person use this module" in three independent places:

| Source | Question |
|---|---|
| `User::canManageX()` | The CAPABILITY. The real gate. |
| `config/departments.php` | Which DEPARTMENT owns the route prefix. |
| `config/plans.php` | Which WORKSPACE a plan grants. |

When the first two disagree, `RestrictDepartmentAccess` 403s a user the permission allows. And it
gets worse than that, because **`EnforceTenantEntitlement` resolves a route's owning workspace
through the same department map** — so a module filed under a department the customer's plan does
not include becomes unreachable for *everyone on that plan*.

Found three times, in three modules:

| Prefix | Capability | Filed under | Fixed |
|---|---|---|---|
| `permits-to-work` | `canCreatePtw()` — HSE **or** an individually granted `ptw_access` | `hse` | v2.42.0 |
| `man-hour` | `canManageManHour()` — HR **and** HSE | `hr` | v1.11.15 |
| `material-requests` | `canManageMaterialRequests()` — Super Admin **or** HSE | `logistics` | v2.71.0 |

The third is the one that shows how expensive this is. Starter sells `['hse']` and Professional
`['hse','hr']`; neither grants `logistics`. So on **the two plans that sell HSE**, the HSE team could
not open the module their own permission says they own — a feature unreachable for exactly the
customers it was sold to, refused by a layer nobody would think to look at.

**The rule.** Before filing a route prefix in `config/departments.php`, read the controller's own
permission method. If it names more than one department — or is a union with an individually granted
flag — the prefix belongs in `RestrictDepartmentAccess::UNIVERSAL_PREFIXES`, not in one department's
list. The map supports exactly one owner per prefix, and the universal list is what "owned by none of
them" looks like.

This does **not** weaken anything: the controller's own gates and the model's `scopeVisibleTo()` are
untouched, and they were already stricter than the routing layer. `DepartmentCapabilityReachTest`
asserts the invariant, so the fourth instance fails in the suite instead of in production.

Note which way round this goes: **raising** a request is cross-department; **fulfilling** it is not.
`purchase-requisitions`, `rfqs`, `purchase-orders` and `goods-receipts` stay department-owned, and a
test asserts they stay that way.

## Master data vs operational data: the page says which it is (v2.71.0)

Reported as not being able to tell which screens configure the system and which record daily work.
`PageHeader` carries an optional `kind`:

| kind | Means | Chip |
|---|---|---|
| `master` | Definitions the system is configured with | **Master Data** |
| `operational` | Records of daily work | **none** — the default |
| `monitoring` | Reading, not writing | **Monitoring** |
| `administration` | Users, roles, settings | **Administration** |

Three rules that matter when extending this:

- **Do not label `operational`.** It is most of the product and the state a user is right to assume;
  badging everything makes the badge furniture. The absence of a chip means ordinary work.
- **Do not reach for red.** Red already means destructive-or-wrong in this codebase (`text-danger`, a
  rejected status, a validation error, Delete). Spending it on "this is configuration" teaches two
  meanings for one colour and makes a real error harder to notice. Master data is *consequential*,
  not dangerous — say the consequence in the subtitle instead.
- **On a page that holds both, the chip follows the open tab**, not the page. Safety Equipment &
  Compliance is the reference implementation: its tab strip is grouped into *Registers* and
  *Reference Data*, and the header follows the selection. Labelling such a page as entirely one kind
  would mislabel half of it.

The chip is a LABEL (English); the consequence sentence is PROSE (Indonesian). See ADR 034.

## CRITICAL — Known Pitfall (v2.114.0) — a soft-deleted row is invisible to the LIST and visible to the IMPORT, and the customer sees both answers at once

Reported from production, and the two halves of the report read like they cannot both be true:

> I imported 75 employees, every row came back **"Duplicate Employee ID"**, and the Employees page
> says **"Belum ada data karyawan"**.

Both were accurate. `Employee` uses `SoftDeletes`, so deleting a mis-imported roster sets
`deleted_at` and leaves the rows in the table. The Employees list applies the soft-delete scope and
showed nothing. `EmployeesImport` built its duplicate registry with `Employee::withTrashed()` and saw
all 75. One screen was answering "who works here", the other "which employee numbers are taken", and
nothing on screen told the customer those were different questions.

**The rule.** Whenever you check "does this already exist" against a soft-deleting model, decide
explicitly which of the three cases you mean, and say which one in the code:

| State | What it means | What a duplicate check should do |
|---|---|---|
| live row | the record is in use | refuse, and name the live record |
| trashed row | the identifier is reserved, the record is not in use | **do not refuse** — restore, or say the row is deleted |
| no row | free | proceed |

**Why dropping `withTrashed()` is NOT the fix, and would have been worse.** The unique indexes
`employees_company_employee_id_unique` and `employees_company_nik_unique` are plain SQL indexes: they
know nothing about `deleted_at`, so the trashed row still occupies the number. Querying only live
rows would have turned a readable per-row skip into a `UniqueConstraintViolationException` that rolls
the whole transaction back — the same failure mode as the v2.105.0 pitfall below, and one the
customer cannot act on.

**What the import does instead: it RESTORES IN PLACE.** A trashed employee with that number is
`restore()`d and then filled from the file, so the record keeps **its own primary key**. That is the
point, not a convenience: KPI records, man-hour logs, PPE issues, approvals and `ActivityLog` entries
all reference `employees.id`, which is the entire reason this model soft-deletes in the first place.
Creating a second row for the same person would strand every one of those against a record nobody
can find.

A restoration is counted and reported SEPARATELY from an import, in the preview, in the result
summary and in the audit trail, because "we brought 75 records back" and "we created 75 records" are
different events and the customer has to be able to tell which one happened. `EmployeesImport`
carries `$restored` alongside `$imported` for exactly that reason; a counter that merged them would
make the fix untestable from the outside.

Pinned by `tests/Feature/EmployeeReimportAfterDeletionTest.php`, which walks the reported sequence
(import 75 → delete all → re-import the same file) and asserts the restored record keeps its id, that
a LIVE duplicate is still refused, and that another tenant's deleted employee is never restored.

## CRITICAL — Known Pitfall (v2.114.0) — a column default chosen for a BACKFILL is a fabricated value when an import reaches it

`employees.employment_type` was added with `->default('pkwtt')`, and the migration says why: every
employee already in the system when workforce classification arrived was being managed as ordinary
permanent staff, so PKWTT preserved their behaviour exactly. That reasoning is sound **for rows that
already existed**.

It is wrong the moment a new row arrives without the value. A roster whose file has no Workforce Type
column, or a type IOMS does not recognise, had every row stamped `pkwtt` — and under Indonesian
employment law PKWTT is a legal classification, not a display preference. The import was not
defaulting, it was **asserting something about each person that nothing in the file said**.

**The rule.** A column default is a statement about rows the schema creates. An importer is not the
schema: when the source is silent or unreadable, the importer must write **nothing** and say so on
the row, never the default.

Two things make this safe to do without disturbing anything else:

- The migration `2026_12_14_100800_allow_an_unknown_workforce_type` makes the column **nullable and
  keeps the default**. Every path that does not mention the column (the forms, `EmployeeFactory`,
  every seeder) is unaffected and still produces `pkwtt`; only an explicit `null` from the importer
  means "the file did not say".
- The row is not refused over it. A missing classification is a gap to complete later, not a reason
  to reject a person's record, so the import warns and continues. The same applies to an
  unrecognised Employment Status: it is reported, not silently coerced.

The general shape, and the thing to look for elsewhere: **a default that encodes "what was true of
the data we already had" becomes a lie the first time a new source writes through it.** Ask of every
`->default()` whether an importer, an API or a webhook can reach that column without supplying a
value, and if it can, whether the default is a fact or a guess.

## CRITICAL — Known Pitfall (v2.94.0) — a commercial term that VARIES BY PLAN must never be written as a calendar unit

`TenantProvisioningService` ended a yearly subscription with `now()->addYear()`.

That is correct for Starter and Professional, which both pay for twelve months and receive twelve.
It is **wrong for Business**, which pays for twelve months and receives **fourteen** — the two extra
months are the entire Business annual offer, since that tier has no price discount at all.

So a Business customer buying annually got twelve months on their first term. Then
`SubscriptionLifecycleService` renewed them by `$package->annualMonths()` — fourteen — and every
term afterwards was correct. The deficit did not accumulate and did not announce itself: it simply
shifted every future date two months early, and the only way to notice was comparing a renewal date
against the offer page a year later.

**Why nothing caught it.** Every existing test asserted the dates the code wrote, or asserted the
PRICES (which were right). None asserted the TERM against the approved commercial model, so twelve
months looked like a correct answer to "what does a yearly cycle mean".

**The rule.** When a commercial figure differs per plan, read it from the plan:

| Wrong | Right |
|---|---|
| `now()->addYear()` | `now()->addMonths($package->annualMonths())` |
| "a yearly cycle is 12 months" | "a yearly cycle is whatever this plan sells" |

And assert it against the transcribed model (`Tests\Support\ApprovedCatalogue`), never against
what the code produced. `PricingChainIntegrityTest` now walks catalogue → quote → registration →
invoice → payment session → the integer on the wire → signed callback → settlement → subscription →
agreed-price snapshot → **service term**, for every sold plan on both cycles, which is what turned a
silent two-month shortfall into a failing assertion.

Audited on production before release: **zero subscriptions affected**, so the fix is forward-only.

## CRITICAL — Known Pitfall (v2.96.0) — a CHOICE the UI offers is an ENTITLEMENT, and must be bounded by the plan

Settings built its "Department Restriction" dropdown from `config('departments')` on the server and
from the hardcoded `WORKSPACES` registry in the browser. Both list every department the
**application** has. Neither asked what the **organization** bought.

So a Starter tenant, entitled to HSE alone, could create a user assigned to People, Warehouse
Logistics or Management. The assignment validated, saved, and then the routes refused that user
every time they signed in. A dropdown had handed out an entitlement nobody paid for, to somebody who
could not use it.

**The rule.** Anything the UI offers as a choice is part of the access model, not decoration. Bound
it with the same service the route gate reads:

| Question | Ask |
|---|---|
| Which workspaces may this ORGANIZATION use? | `EntitlementService::tenantDepartmentKeys($tenant)` |
| Which may THIS PERSON work in? | `EntitlementService::userCanUseWorkspace($user, $key)` |
| Which may this person be ASSIGNED to? | `tenantDepartmentKeys()` — never the person's own, they are not assigning themselves |

The browser copy of a list is the part that rots. `assignableDepartments` is now a server prop for
exactly that reason: the client cannot offer what the request would reject, because it is not the
one deciding.

## CRITICAL — Known Pitfall (v2.96.0) — a sidebar row is a PROMISE, so its gate must match the route's

Three Admin Space rows were gated `adminOnly` — `isAdmin()`, which is Super Admin **or HSE** — while
the things behind them are `role:super_admin`:

| Row | Route / tab | Was | Is |
|---|---|---|---|
| Operating Units | `settings.companies.*`, tab needs `can.manage_system` | `adminOnly` | `tenantAdminOnly` |
| Audit Logs | `activity-center.index` | `adminOnly` | `tenantAdminOnly` |
| Module Management | `settings.modules`, tab needs `can.manage_system` | `adminOnly` | `tenantAdminOnly` |

An HSE user got a rail entry that led to a 403 or to a tab that would not draw.

**And the opposite mistake is just as easy**, which is why it is written down here. Most operational
rows (Employees, PPE, Shift & Roster, Waste, HSE master data) look like they should be gated the
same way, because their WRITE routes are `role:super_admin,hse`. Their **index routes carry no role
middleware on purpose**: a workspace's lists are readable by everyone the plan admits, and each
controller passes `can.manage` so the page renders read-only. Hiding those rows removes reading the
product intends people to do. This pass gated them, a test caught it, and they were restored.

**Before changing a nav gate, read the gate on the route it points at — specifically the one the row
links to, not the group's write routes.** `php artisan route:list --json` and match by exact name;
grouping by prefix attributes a group's strictest middleware to its index and is how this was got
wrong the first time.

## CRITICAL — Known Pitfall (v2.107.0) — deleting a row that 93 foreign keys point at

`users` has no soft deletes, and the delete route called `$user->delete()` with no dependency
check. Both possible outcomes were wrong:

- **27 RESTRICT and 4 NO ACTION keys** mean the delete **throws** for any account that has approved
  something, performed an asset transaction or owns a controlled document — which is every account
  that has been used. The administrator got a 500, not an explanation.
- **59 SET NULL keys** mean that where it *did* succeed, it **silently nulled the actor on 59
  relationships** — erasing exactly the attribution v2.100.0 exists to preserve. An audit trail that
  empties itself when somebody leaves the company is not an audit trail.

`projects` is the same shape: `daily_reports.project_id` RESTRICTs, fifteen other keys SET NULL.

**The rule.** Before adding a delete, ask what points at the table and what the database is
instructed to do about it. Where history exists, the product almost always already has the right
operation — deactivating an account, closing a project — and the delete should refuse and name it.
Reserve deletion for a record with no history, where there is nothing to protect.

A quick way to check: `information_schema.REFERENTIAL_CONSTRAINTS` grouped by `DELETE_RULE` for
the table in question. A mix of RESTRICT and SET NULL on one table means the delete behaves
differently depending on which records happen to exist, which is the worst of both.

## Known Pitfall (v2.107.0) — navigation could not express "this page needs a capability"

The sidebar had five gates: `adminOnly`, `tenantAdminOnly`, `moduleKey`, `globalDashboardOnly`
and `departmentUserOnly`. All of them ask about a **role class** or about the **plan**. None could
say "this page needs `canManageWarehouse()`".

So two pages were offered to everyone in their workspace and answered 403 for most of them:
**Employee Cases** (needs HRD) and **Stock Out / Transfer / Adjustment** (needs Warehouse). This is
the fourth time this codebase has shipped a navigation entry that could only produce a refusal
(v2.98.0 Admin Space, v2.103.0 My Work, v2.103.0 PTW Access in the opposite direction).

`capability` is now a sixth gate naming one of the `can_*` booleans the server already publishes
on `auth.user`, so the sidebar asks the same question the controller asks.

**The rule.** When adding a page whose controller checks a capability, name that capability on its
navigation entry. `PackageRoleNavigationMatrixTest` walks every item across every plan and role and
fails if any offered item answers 403 — it is the sweep that would have caught all four.

## CRITICAL — Known Pitfall (v2.106.0) — shared reference data with no owner has no authorization question to ask

`ppe_types` is installation-wide shared reference data, and v2.63.0 recorded that as deliberate:
six generic rows holding nothing that identifies a customer. Sharing them for READING is fine.

**What was never asked is who may change them.** Create, update and delete are gated by
`role:super_admin` — the **tenant** administrator — and `PpeTypePolicy` only ever checks a role
capability, because on a row with no owner there is nothing else to check. So any customer's
administrator could rename, re-interval or delete a PPE type every other customer was issuing, and
a customer adding their own type put it in everybody's dropdown.

`destroy()` had noticed half of it and refuses to delete a type in use *anywhere*; `update()` had
no guard at all, and a rename is as destructive to the customer reading the other end of it.

**The rule.** Shared reference data that a tenant can MUTATE is a contradiction. Either it is
platform-owned and tenants only read it, or it carries an owner. IOMS already had the pattern for
the middle case — `KpiCategory`, `NumberingFormat`, `NumberingSequence` — a nullable
`company_id` where null means "the built-in default everyone starts from" and a value means "this
customer's own". `PpeType` now joins them: both are visible, only the second is theirs to change.

**How to find the next one:** sweep every route that binds a model with no global scope. Such a
model resolves by primary key alone, so the controller must assert ownership — and if there is no
ownership to assert, the real question is whether that route should exist for a tenant at all.

## Known Pitfall (v2.106.0) — a guard in a shared helper is invisible to a reader checking method bodies

The same sweep flagged `subscription/renew` and both `subscription/plan-change` verbs as having no
role middleware and no guard in the method body, beside five siblings that check
`canManageSystemSettings()` inline. **That reading was wrong.** The guard is in
`currentSubscription()`, which every one of those mutations calls as its first statement.

One guard on the one shared path is better than eight copies. But it is invisible both to a route's
middleware list and to anyone reading a method body, so it will keep being suspected. The answer is
not to duplicate it — it is to pin the BEHAVIOUR from outside (`CommercialAuthorizationTest`) and to
pin that every mutation still resolves through the guarded helper rather than around it.

**The general rule:** when a protection lives somewhere a reader will not look, the test is what
makes it visible. Verify before "fixing" an absence.

## CRITICAL — Known Pitfall (v2.105.0) — a uniqueness constraint narrower than the tenancy around it

`employees.employee_id` carried a **global** unique index from
`2024_01_01_000005`, written when IOMS was one company's internal tool. It was never revisited
when multi-company arrived, and never when multi-tenancy did.

**One customer's `EMP-001` prevented every other customer from ever having one.** EMP-001, 1001
and 00123 are exactly the sequences every yard starts with, so collisions between unrelated
customers were not a remote risk — they were the expected case.

What made it hard to see is that **the preview was right**. Employee Import checks for an existing
number with `Employee::withTrashed()->where('employee_id', $id)->exists()`, which is correctly
scoped by `CompanyOwnedScope` to the current customer's own data. Another tenant's rows are
invisible to it, as they should be. So a 141-row file reported **141 valid rows** — a true statement
about everything that query could see — and the insert then threw against data the customer could
neither see nor do anything about. The whole import ran in one transaction, so all 141 rows and the
departments created with them were rolled back, and the screen said "Import failed".

**The rule.** A uniqueness constraint must be scoped no more widely than the data it protects. An
employee number belongs to a company, so the key is `(company_id, employee_id)`. When a check and
a constraint disagree about scope, the check will always look correct and the constraint will always
win.

Narrowing a unique index is safe on populated tables: every set unique globally is also unique
within each group, so it can only ever permit rows that were previously refused. **The reverse is
not true** — see that migration's `down()`, which restores the global index *before* dropping the
per-company one, so a rollback that legitimately fails leaves the stronger constraint in place
rather than a table with none.

## Known Pitfall (v2.105.0) — a form that silently does nothing when a default is missing

Employee Import's dialog preselected an operating unit only when the account could see exactly one.
`CompanyAuthorizationScope` means two accounts legitimately see different lists, so an
administrator who could see several got **nothing** selected — and `runPreview()` opened with
`if (!file || !companyId) return;`.

The result was two accounts with two behaviours and no message: a user created by the administrator
imported fine, while the administrator picked a file and watched nothing happen. It was reported,
reasonably, as "the Admin account does not detect master data".

**The rule.** A guard clause that returns early on missing input must say which input is missing.
Prefer a sensible default (here, the account's own `company_id`) and treat a disabled control as a
hint, never as the explanation.

## CRITICAL — Known Pitfall (v2.101.0) — three places decided whether two names are "the same", and disagreed

Employee Import turns a Department and a Position column into records. Three pieces of code had to
agree on whether ` HSE ` is the existing **HSE** department, and none of them agreed:

| | Compared with | Result for ` HSE ` |
|---|---|---|
| `MasterDataDetector::classify()` | `mb_strtolower()`, **no trim** | NEW |
| `EmployeesImport` lookup | `where('name', $cell)` — the **database** decides | depends on the connection |
| `createMissingMasterDataAndImport()` | `firstOrCreate(['name' => $raw])` | creates a department **named with a leading space** |

So a spreadsheet that spelled one department three ways produced three departments, and a stray
space — which is exactly what a spreadsheet column collects — produced a record nobody could
distinguish from the real one in a list.

**Worse, matching depended on the database.** `where('name', $name)` is case-insensitive under
MySQL's default collation and case-SENSITIVE under the SQLite the suite runs on. Production quietly
forgave a case difference, the tests could not have caught it either way, and the behaviour was a
property of the connection rather than of the code.

**And the "create missing master data and import" flow could not find what it had just created.**
The controller creates a Position with a company and a name; the import resolved positions with
`where('department_id', ...)`. A freshly created position has a null department, so the lookup never
matched: the one flow whose entire purpose is to let blocked rows land was landing them without a
position. `MasterDataDetector`'s own doc comment says positions resolve "by name within the
company" — which the import *used* to do and no longer did, so the comment had quietly become wrong
about the code beside it.

**The rule.** `MasterDataDetector::normalise()` is what gets **stored and shown** (trimmed, inner
whitespace collapsed); `comparisonKey()` is what gets **compared** (additionally case-folded).
Every path uses them, so the answer is the same in detection, resolution and creation, and the same
on every database. Resolution reads a map built once per import rather than a query per row.

Before adding another importer, reuse these two functions. "Compare in the database" is not a free
choice: it hands the definition of identity to the connection.

## CRITICAL — Known Pitfall (v2.100.0) — an actor resolved through a foreign key is not an audit trail

`activity_logs` stored only `user_id`, and `approvals` only `approved_by`/`requested_by`. Every
actor shown anywhere — a timeline entry, an audit row, an approval stamp, the signature block on a
printed permit — was resolved by following that key to the account's **current** name.

So renaming one person silently rewrote history. Every approval they had ever granted, on every
permit already printed and filed, retroactively carried the new name; deactivating or removing them
emptied the stamp. On a product whose permits and approvals **are** the compliance record, an audit
trail that changes when somebody marries is not an audit trail.

The names are now captured when the action happens: `activity_logs.actor_name`,
`approvals.requester_name` and `approvals.approver_name`. **The foreign key stays and stays
authoritative for "which account"** — the snapshot answers "what did this say at the time", which is
a different question and the only one a historical document can honestly answer.

Nothing was backfilled, deliberately. A historical row's null means "not captured when it happened",
which is true; the read paths fall back to the live relation exactly as before, so every existing
row renders identically. Writing today's names into yesterday's rows would manufacture a historical
fact out of a value that may already have changed.

**The rule.** When a value is displayed as evidence of something that already happened, store it at
that moment. A join is the right way to answer "who is this account"; it is the wrong way to answer
"who signed this".

## Known Pitfall (v2.100.0) — one column was answering two identity questions

`users.name` is the **registered** identity: it arrives as the registration's `contact_name` and
belongs beside the email on an invoice and in support. What a colleague should see in a sidebar or
an approval queue is a **different** question, and with one column an administrator could only
answer the second by destroying the first.

`display_name` is nullable and additive, and `User::displayName()` is the single answer to "what
should this person be called". The shared Inertia prop sends it as `auth.user.name`, so **one
setting reaches every surface at once** and no component has to remember which of the two columns to
prefer.

Keep the two apart when writing anything new: the registered name for records, billing and support;
`displayName()` for anything a person reads.

## The copy rules, and where they are enforced (v2.100.0)

Two typographic rules now have tests, for the same reason the language hierarchy does — they had
been stated and then broken.

| Rule | Instead |
|---|---|
| **No em dash in user-facing text** | a hyphen for a "no value" placeholder; a comma, colon or full stop in prose, chosen per sentence |
| **No stored identifier in user-facing text** | `humanizeToken()` — `under_maintenance` reads "Under Maintenance" |

Both apply to what a customer reads, **not** to comments: this codebase's doc comments use em
dashes heavily and deliberately, and `CopyRulesTest` strips comments before checking. Technical
identifiers in code, config keys and route names are unaffected.

The underscore rule is easy to break without noticing, because the value looks fine in the database.
`StatusBadge` had always humanised its input; the activity feeds and the asset history rendered
`ActivityLog.action` and `previous_status`/`new_status` raw, so real rows printed
"in_progress" and "under_maintenance" at people. `humanizeToken` is now the one definition, shared
rather than re-implemented.

## CRITICAL — Known Pitfall (v2.99.0) — a NULL owner means two different things, and the scope only knew one

`CompanyOwnedScope` (v2.62.0) reads a null `company_id` as "owned by nobody, so visible to
nobody". That is the right default and it is wrong for three tables, because **Approval Flow,
Documents and Import/Export Mapping use a null `company_id` to mean "applies to the whole
tenant"** — not by accident of a nullable column, but by design:
`ApprovalFlowResolver` says "company-specific flows win over tenant-wide defaults" and queries
`whereNull('company_id')` to find them, and `FieldMappingService::resolve()` is documented
"tenant-wide only (company_id null)".

Every write site stores null: `storeApprovalFlow`, `storeDocumentTemplate`,
`FieldMappingService::upsert`. So from v2.62.0 onward the damage was total and completely silent:

- Everything a customer configured **vanished the instant it was saved.** The success message
  appeared, the list came back empty. Creating it again just added another invisible row — there
  is no unique constraint to complain.
- **Route model binding runs through the global scope too**, so `{approvalFlow}`'s own delete and
  save-steps endpoints answered 404 for a row that plainly existed.
- Worst, and visible on no screen at all: the resolver's `whereNull('company_id')` branch became
  **dead code**. `resolve()` returned null for every tenant-wide flow, so every module quietly fell
  back to legacy single-step approval while Settings presented the feature as configured.

Nothing logged, nothing 500'd, no console error. A static read of either file alone looks correct;
the bug only exists in the *combination*, which is why it needs writing down.

**The fix, and the distinction that matters.** These models now use
`IsTenantWideConfiguration`, which implements `companyScopeNullOwnerQuery()` to require
`tenant_id` = the current tenant. Null-company rows are reachable inside their own tenant and
structurally unreachable outside it.

Do **not** reach for `companyScopeAllowsGlobalRows()` here. That hook makes null rows visible to
*everyone*, which is correct only for `NumberingFormat`/`NumberingSequence`/`KpiCategory`, whose
null rows carry **no tenant at all**. These rows do carry a tenant, so "shared with everyone" would
be a cross-tenant configuration leak. Task's hook draws the same line: *"shared with everyone" and
"owned through a different column" are different answers.*

**The rule.** Before adding `BelongsToCompany` to a table whose `company_id` is nullable, decide
what null *means* on it, and say so on the model. A nullable owner column with no stated meaning is
how this happened.

## CRITICAL — Known Pitfall (v2.99.0) — a Radix tab value nobody can see renders NO panel, not a fallback

Settings fed `?tab=` straight into `Tabs.Root`'s `defaultValue`:

    defaultValue={new URLSearchParams(window.location.search).get('tab') || (canSystem ? 'branding' : 'departments')}

The `||` only catches a *missing* parameter. **Radix renders no panel at all when the active value
matches no `Tabs.Content`** — and every panel in Settings is gated by the same
`canSystem`/`canPtwAccess` check as its trigger. So an HSE or PTW-only user opening a bookmarked,
shared or back-button `/settings?tab=approval-flows` got the page chrome, the tab strip, **no
selected tab and an entirely empty body**: the reported "Approval Flow suddenly goes white". Any
stale or misspelled value did the same, and because `defaultValue` is read only on mount it stayed
blank until another tab was clicked.

There is **no error of any kind** — no console message, no failed request, nothing to read. Verified
in the browser: `?tab=does-not-exist` dropped the page body from 1554 characters to 639 with all 16
tabpanels empty and `aria-selected` set on nothing.

**The rule.** A value that selects UI and arrives from a URL must be validated against what this
user actually has, not merely against null. Settings now resolves it through `availableTabs`, whose
`[0]` doubles as the fallback, and `SettingsConfigurationReachabilityTest` pins the list against
the JSX so a tab cannot be added to the strip without being added there too.

Note the amplifier: **there is no ErrorBoundary anywhere in `resources/js`**, so this class of
problem presents as a blank page rather than a message. Worth remembering when a user reports a
white screen.

## CRITICAL — Known Pitfall (v2.98.0) — two middlewares read ONE prefix map and disagreed about what it meant

`config('departments')` maps a route prefix to the department(s) that own it. **Two** middlewares
read it, and since v2.46.0 they have read it differently:

| | Reads the map with | Meaning |
|---|---|---|
| `RestrictDepartmentAccess` | `filter()` → every owner | multi-owner (fixed in v2.46.0) |
| `EnforceTenantEntitlement` | `search()` → **the first owner** | single-owner |

So a prefix listed under two departments was entitlement-checked against **whichever happened to be
listed first**. `employees` sits under `hr`, so a Starter tenant — entitled to HSE and deliberately
**not** to People / HRD — was refused its own workforce register. A customer could not add the
people their PPE issues, incidents and permits are about.

Both now accept **any** owning department. That widens nothing: a tenant holding none of the owners
is still refused, and a single-owner prefix (every other entry) cannot change meaning.

**The rule.** When a capability genuinely spans departments, list it under each and make sure every
reader of the map handles multiple owners. Grep for other readers before assuming two are all there
are — and never infer a route's gate from its prefix group, which is a separate pitfall below.

## CRITICAL — Known Pitfall (v2.108.1) — SQLite ACCEPTS a column that does not exist, and MySQL does not

`->with(['permit:id,ptw_number,title'])` names a column `permits_to_work` has never had. On MySQL
that is a hard error:

```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'title' in 'field list'
```

On SQLite it succeeds. Laravel quotes identifiers with double quotes, and SQLite's documented
double-quoted-string misfeature turns a double-quoted identifier that matches no column into a
STRING LITERAL. So `select "id", "title"` returns the word *title* in a column called `title`, the
relation hydrates, and the page renders.

The whole test suite runs on SQLite. A page that 500s for every production tenant can therefore be
fully green here. This was found by running the suite against MySQL for the first time, and it is
the sharpest available illustration of why **SQLite success is not proof of MySQL compatibility**.

**The rule.** A column list in `with([...])`, `select()` or `get([...])` is a schema claim. When you
add or rename one, check it against the migration, and run anything schema-shaped against MySQL
before believing it. The reverse case is a pitfall of its own, below.

## CRITICAL — Known Pitfall (v2.108.1) — `->after('column')` must name a column that EXISTS by then, and SQLite will not tell you

`2026_09_30_120000` added a column with `->after('additional_users')`. That column is created by
`2026_11_09_100350` — two months later in migration order. On a fresh MySQL database the migration
died; on SQLite everything passed, because SQLite has no column positioning at all and Laravel
silently drops the clause.

The effect is the worst shape a deployment bug can have: **existing** installations were fine (the
migration had already run), so nothing looked broken, while **no new MySQL deployment could be
provisioned at all**.

**The rule.** `after()` is cosmetic — it buys column order in a `DESCRIBE` and nothing else. Prefer
leaving it out. If you do use it, the anchor must be created by an EARLIER migration, and
`MigrationPortabilityTest` now checks exactly that across every migration, so this class of break
fails on SQLite too.

## CRITICAL — Known Pitfall (v2.108.0) — `date('Y-m-d', strtotime($x))` invents 1 January 1970, and it never throws

`strtotime()` returns `false` for anything it cannot read. `date()` casts `false` to the integer
`0`. `0` is the Unix epoch. So this expression, which reads as obviously fine:

```php
$joinDate = date('Y-m-d', strtotime((string) $cell));   // NEVER do this
```

turns every unreadable value — `belum tahu`, `-`, `n/a`, an employee number in the wrong column —
into **1 January 1970**: a real-looking date, in a date column, that nobody typed. It raises no
exception, so a surrounding `try/catch` (the employee import had one, added in v2.105.0 for exactly
this class of problem) never sees it.

It also reads a slashed date **American-first**, which is the wrong way round for this product.
`15/01/2026`, the id-ID Excel default, is month fifteen and so becomes 1970 too; `01/02/2026` —
1 February to every customer here — is silently stored as 2 January. The second one is the
dangerous half, because a plausible wrong date is one nobody goes looking for.

This reached production data through IOMS's **own** Excel export, which writes an empty join date
as `-`: re-importing a file IOMS generated itself turned every blank date into 1970.

**The rule.** Any date read from a spreadsheet, a CSV, or any other source outside the database goes
through `App\Support\SpreadsheetDate::parse()`, which returns `?string` and answers `null` rather
than guessing. Never `strtotime()`. The day-first rule and the reasoning are in
`docs/ADR/050-a-date-out-of-a-spreadsheet.md`. If you add a new importer, this is not optional — the
expression above is the one a reviewer waves through.

**And the smaller one beside it.** `Maatwebsite\Excel\Row::getIndex()` already returns the real
sheet row number (header is row 1, first record is row 2). The import did `getIndex() + 1`, so every
customer told to fix a bad row was sent to the row below it. Do not add the `+ 1`.

## CRITICAL — Known Pitfall (v2.98.0) — ROLE is not the same question as REACHABILITY

`canAccessAdminSpace()` asked only about role: `isTenantAdmin() || canManageOperationalSettings()`.
An HSE user **assigned to the HSE department** answered yes, so the header offered them Admin Space.

Every page inside Admin Space is under the `settings` prefix, which the same map gives to
`administration` **alone** — so `RestrictDepartmentAccess` refused them at the door. The entry could
only ever produce a 403.

**A capability predicate that drives navigation must answer "can this person actually get there",
not "does their role normally allow it".** Where a department assignment can block a route, the
predicate has to ask about that too — through the same map the middleware enforces with, not by
naming the department, so the two cannot drift. An unrestricted administrator
(`department_key === null`) is unaffected, which is the overwhelming majority of accounts and why
this survived so long.

Note what this is **not**: it removes no authorization. It reports the access the backend already
grants, which is the whole job of a navigation gate.

## Known Pitfall (v2.78.2) — a service worker caching STABLE filenames pins them forever

`/build/` is content-hashed by Vite: a new build is a new URL, so cache-first can never be stale.
`/branding/` is not. `ioms-logo-dark.svg` keeps that name while its BYTES change, so caching it
cache-first with no revalidation pinned the old logo and the old favicon in every returning
visitor's browser. Redeploying the file changed nothing for them, and because it was the app's own
Cache Storage rather than HTTP cache, clearing the browser cache did not help either — the only
escape was bumping `CACHE_NAME`, by hand, for every brand change.

**The rule.** Match the policy to the URL, not to the folder:

| URL shape | Policy |
|---|---|
| content-hashed (`app-BQx1.js`) | cache-first; it cannot go stale |
| stable name, changing bytes (`ioms-logo.svg`) | **stale-while-revalidate** |

Bumping the cache name fixes today's stale copies. Only revalidation stops it recurring; v2.78.2 did
both. Before caching anything by a stable name, ask what happens on the day its contents change.

## Known Pitfall (v2.77.0) — the `date` cast STORES a time, and MySQL hides it

A `'work_date' => 'date'` cast serializes through the model's datetime format, so Eloquent writes
`2026-09-30 00:00:00`. A MySQL `DATE` column truncates that silently, which is why production never
showed a problem. Any store that keeps the string (SQLite, which the test suite uses) does not, and
two things broke in `ManHourLog`:

- `whereBetween('work_date', ['2026-09-01', '2026-09-30'])` dropped the 30th, because
  `"2026-09-30 00:00:00"` sorts after `"2026-09-30"`.
- `updateOrCreate(['work_date' => '2026-09-30'])` missed the existing row, then hit the unique index
  and returned a 500 instead of replacing the row.

**The rule:** for a column that is a calendar date, normalise it to `Y-m-d` on write, with a
`set…Attribute` mutator that takes precedence over the cast. Do not ask every query to remember
`whereDate()`. Only the tests caught this; do not rely on MySQL's forgiveness.

## Known Pitfall (v2.77.0) — a total computed from a paginated collection is a total of one page

`$logs->getCollection()->sum(...)` after `paginate(30)` sums **30 rows**, not the period. Aggregate
headline figures in the database, from the same filtered query the table uses (clone it before
paginating). The Man-Hour page reported wrong totals for every period with more than 30 records.

## Known Pitfall (v2.75.0) — `config('x.'.$routeName)` breaks on route names that contain a dot

`config('seo.pages.legal.privacy')` is read as `seo → pages → legal → privacy`, not as the key
`legal.privacy`, and returns null — silently. Every legal page counted as "not public" until a test
rendered one. When a config array is keyed by route names (or anything else that may contain a dot),
read the array and index it: `(config('seo.pages') ?? [])[$routeName] ?? null`.

## Known Pitfall (v2.75.0) — never build an email image URL from `asset()`

`asset()` uses `APP_URL` of whichever host SENDS the mail — `localhost` from a dev machine. Mail
providers fetch images through their own proxy. Use `config('ioms.public_url')`, and use an opaque
image: a light logo on transparency disappears when a client drops the cell background. See ADR 039.

## CRITICAL — Known Pitfall (v2.74.0) — `tenant_id IS NULL` used to MEAN "platform operator", and anything that creates a tenant-less user inherits that meaning

`User::isPlatformAdmin()` was `is_null($this->tenant_id)`. That was correct for as long as the only
way to have no tenant was to be the platform operator.

v2.74.0 introduced accounts that exist before they have an organization — which is to say,
tenant-less users. Under the old definition **every new signup would have been a Platform Super
Admin with cross-tenant reach**, and nothing in the registration code mentions platform admin at
all, so there is no line anybody would have thought to read.

The fix is to ask the question a different way (`role === ROLE_PLATFORM_ADMIN`), and the discipline
around it is the part worth copying:

1. **Check the data first.** Every null-tenant user already carried the role and no tenant user did,
   so the switch was provably behaviour-preserving rather than hopefully so.
2. **Back the invariant with a migration**, so it holds on every deployment and not just the one
   that was inspected.
3. **Pin it with a test** (`AccountRegistrationTest::test_a_new_account_is_not_a_platform_admin`),
   because the old definition is the one somebody will reach for again.

**The general rule.** Before adding a record in a state no record has been in before — a user with
no tenant, an order with no customer, a document with no owner — search for predicates that infer
meaning from the ABSENCE of that relationship. `is_null(...)` in an authorization method is the
shape to grep for.

## Known Pitfall (v2.74.0) — a nullable validated field is ABSENT from `$validated`, not present-and-empty

```php
// WRONG -- "Undefined array key" the moment the field is simply not sent
'company_country' => $validated['company_country'] ?: 'Indonesia',

// RIGHT
'company_country' => ($validated['company_country'] ?? null) ?: 'Indonesia',
```

`?:` reads like a null-safe default and is not one. A `nullable` rule means the key may not be in the
array at all, which is different from being there and empty — and the difference only shows when a
user leaves an optional field blank, which is the normal case, not the edge case.

Found by a test that filled in only the required fields.

## Known Pitfall (v2.74.0) — a column missing from `$fillable` is dropped SILENTLY by `create()`

`User::create([... 'email_verified_at' => now(), 'google_id' => $sub])` produced an account that was
neither verified nor linked, with no error anywhere. Mass assignment discards unknown keys quietly by
design.

The damage was specific: the Google account would then have failed to match on `google_id` at the
next sign-in and created a duplicate, which the unique index on `email` would have rejected — a
sign-in that works once and then breaks forever.

**The rules.**

- After adding a column, decide explicitly whether it is fillable, and say so in the same change.
- **Authentication-bearing columns must NOT be fillable.** `google_id` is written only through
  `linkGoogleIdentity()` and one explicit `forceFill`, because a fillable identity column is one
  careless `update($request->all())` away from account takeover.
- Assert the persisted row in a test, not the call. `create()` returning a model proves nothing
  about what was stored.

## Known Pitfall (v2.74.0) — a per-tenant number series must be counted by SERIES, not by current owner

`Invoice::generateNumber(null)` counted `whereNull('tenant_id')` for the onboarding series. But
`TenantProvisioningService` ATTACHES a paid onboarding invoice to the tenant it just created, so the
invoice leaves the null-tenant bucket, the count falls back to zero, and the next onboarding invoice
regenerates `INV-YYYY-NEW-00001` — colliding with the one that still carries it.

It only bites on the **second** self-service signup of a year, which is exactly why it survived: the
first one always works.

**The rule.** Count a number series by a discriminator that does not change for the life of the
record (`registration_id` here), never by a field the record's own lifecycle rewrites.

## CRITICAL — Known Pitfall (v2.73.0) — Blade compiles directives BEFORE it strips comments, so naming a directive inside a comment executes it

A section of the PTW PDF silently vanished. The template read perfectly; the rendered document
simply did not contain it. The cause was a comment *explaining* the code beneath it:

```blade
{{-- Block form, not the inline @php(...) form, which this Blade version
     compiles to an unterminated PHP open tag. --}}
@php
    $ptwPpe = $ppe ?? null;
@endphp
```

Blade runs `compileStatements()` **before** `compileComments()`. The `@php` *inside the comment* was
therefore compiled into a real PHP open tag, the comment stopped being a comment, and every
directive after it in the file stopped being Blade. No error, no warning — the section was just
absent, and the template still looked correct.

The same trap catches a literal PHP open tag written in a comment, for a different reason: Blade
tokenises the whole template with `token_get_all()`, so the tokenizer opens PHP on it regardless of
the comment around it.

**The rules.**

1. **Never write a directive's name with its leading at-sign inside a Blade comment.** Describe it
   in words — "the inline single-expression form" — or the comment will be compiled.
2. **Never write a literal PHP open tag in a Blade file**, not even inside a comment.
3. **Prefer the block `@php … @endphp` form over the single-expression one.** The inline form
   compiled to `<?php(...` — an unterminated open tag — on this Laravel version, which is the bug
   the comment above was documenting when it caused the same class of failure a second time.

> [!warning] Nothing static catches this
> `php -l` passes, the Blade file looks right, and the page renders — just without that section.
> The only thing that found it was generating the PDF and reading what came out. Compile the
> template and grep the output when a Blade section does not appear.

## CRITICAL — Known Pitfall (v2.73.0) — a new route prefix must be added to `config/departments.php` in the same change, and this has now cost a 403 three times

Registering `/investigations` and opening it as a department-scoped HSE user returned **403**, before
the controller's own `canManageIncidents()` check could run. `RestrictDepartmentAccess` resolves a
route's owning department from `config/departments.php`, and a prefix that is not listed belongs to
nobody.

This is already written up for v1.11.2 and again for v2.71.0. It has now happened a third time,
which says the rule needs restating as a checklist item rather than a story:

**Adding a route prefix is three edits, not one:**

1. `routes/web.php` — the routes themselves.
2. `config/departments.php` — the owning department (or `UNIVERSAL_PREFIXES` when the capability
   genuinely spans departments, as PTW does).
3. `resources/js/lib/workspaces.js` — the sidebar entry.

Miss (2) and the feature 403s for exactly the users it was built for, while working perfectly for a
Super Admin — which is who tests it.

## Known Pitfall (v2.73.0) — MySQL rejects an index name over 64 characters, and SQLite does not

`$table->index(['company_id', 'incident_investigation_id'])` derives the name
`investigation_interviews_company_id_incident_investigation_id_index` — 66 characters. MySQL refuses
it; **SQLite, which the suite runs on, accepts it silently.** So the test suite passed and only a
real migration against MySQL failed.

Worse, the failure left a **partially-created table**: `Schema::create()` runs the table, its foreign
keys and its indexes as separate statements, so the table existed while the migration was recorded
as not run. The retry then failed with "table already exists" — the same shape as the v1.11.2
incident already documented above. Recovery is to drop the orphan and re-run, not to reach for
`createIfMissing()`, which would skip completing it.

**The rule.** Name any composite index explicitly when the derived name would approach 64 characters
— long table names plus long foreign-key columns get there quickly — and run migrations against
**MySQL**, not just the suite, before calling them done. Roll them back and re-apply too: this pass
also found a `down()` that could not drop its own index because InnoDB had adopted it to satisfy a
foreign key.

## Known Pitfall (v2.73.0) — a non-Latin-1 character in a dompdf template embeds a whole font

The PTW PDF used ✓ and ☐ to mark confirmed PPE. Both are outside Latin-1, so dompdf embedded a
DejaVu Sans subset to draw them and **the permit PDF went from 8 KB to 886 KB** — a hundredfold, for
two glyphs, on a document that is generated on demand and emailed.

The fix carries the same information without a font: a small square drawn from CSS borders, filled
when confirmed and outlined when not. It also survives a mono photocopy and a phone photo of a page
taped to a bulkhead better than a tick does, which matters more on a printed permit than on a screen.

**The rule.** In `resources/views/pdf/*`, stay within Latin-1. Where a mark is needed, draw it with
borders and background colour rather than reaching for a symbol character — and **measure the
generated file**, because nothing else reports this.

## CRITICAL — Known Pitfall (v2.72.0) — a large array literal passed inline to a Blade directive is silently TRUNCATED, and `app.blade.php` takes the whole product down with it

Written as:

```blade
{{-- WRONG -- compiles to unbalanced PHP, with no warning at any stage --}}
<script type="application/ld+json">
    @json([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => $brandName,
        'alternateName' => $brandName.' — '.$brandDescriptor,
        ... eight more keys ...
    ], JSON_UNESCAPED_SLASHES)
</script>
```

Blade matches a directive's argument with a **recursive PCRE pattern**. A long enough argument
exhausts it, and the match does not fail loudly — it stops early. The compiled view came out as
`json_encode([ '@context' => '...', '@type' => '...', 'name' => $brandName)`: an unclosed `[`
closed by a `)`.

**The severity is entirely about the file it was in.** Every Inertia page in IOMS renders through
`resources/views/app.blade.php`, so a template that cannot compile is not a broken page — it is a
product that returns nothing at all. The whole suite went from 418 passing to **88 failing**, and
every one of those failures pointed at the assertion in the test rather than at the layout.

**The rule.** Build any non-trivial array in an `@php` block and pass the directive a **variable**:

```blade
@php
    $organizationLd = ['@context' => 'https://schema.org', /* ... */];
@endphp

<script type="application/ld+json">
    @json($organizationLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
</script>
```

A one-token argument cannot hit the limit. This is also easier to read, which is the usual sign that
the workaround was the right structure anyway.

> [!warning] `npm run build` would never have caught this
> It is PHP, not JavaScript. Nothing short of rendering a page — a test, a browser, `view()->render()`
> — sees it at all. Add `php artisan test` to "did my change work", not just the frontend build.

## CRITICAL — Known Pitfall (v2.72.0) — a test that pins ONE of two renderers reports a half-shipped fix as a finished one

v2.37.0 fixed a reported PDF-vs-screen timezone mismatch on a Permit to Work by resolving **both**
renderers against `config('ioms.display_timezone')`. `PermitToWorkDocumentTimezoneTest` pinned the PDF
half, passed, and the release shipped.

The browser half read `usePage().props.display_timezone`. **Nothing in PHP ever shared that prop.**
`Document.jsx`'s own doc comment asserted that it did.

Two things kept it hidden for a year:

- the frontend formatter falls back to the device timezone when the prop is absent, so it never
  failed — it just quietly rendered a *different correct-looking* time;
- everyone who would have checked has a machine set to Asia/Jakarta, which is the configured display
  timezone, so the fallback produced the right answer by coincidence.

It surfaced only in v2.72.0, when the approval stamp put a second rendering of the same instant on a
page next to the PDF's and they disagreed by an hour.

**The rules.**

1. **A fix that spans two renderers needs a test per renderer.** One green test over half a fix is
   worse than no test, because it converts "unverified" into "verified".
2. **Pin the wiring, not just the formatting.** The assertion that was missing is trivial —
   `assertInertia(fn ($page) => $page->where('display_timezone', 'Asia/Makassar'))` — and it is the
   only one that would have failed.
3. **A silent fallback deserves a test more than a hard failure does.** Code that throws when
   mis-wired announces itself. Code that degrades to a plausible default does not, and on a
   controlled safety document "plausible" is the whole problem.

## Known Pitfall (v2.72.0) — `roleLabel()` returns `''`, not an error, for a relation loaded without its `role` column

`PermitToWorkController::show()` eager-loads `'hseApprover:id,name'`. `User::roleLabel()` ends in
`default => ucfirst($this->role)`, which for an unselected column is `ucfirst(null)` — the empty
string. The approval stamp therefore rendered an approver with no role, and nothing anywhere said
why.

**Two lessons, and the second is the general one.**

- When you add a field to a payload, check the **eager-load column list** of every caller. A
  constrained `relation:id,name` is an allow-list, and it is easy to miss because it looks like an
  optimisation rather than a contract.
- `match` with a `default` arm that formats its input **cannot distinguish "unknown value" from "no
  value"**. Where the difference matters, guard the caller on the column
  (`$approver?->role ? $approver->roleLabel() : null`) so a missing fact stays missing instead of
  becoming an empty one. On the approval stamp this is the same rule the whole feature runs on:
  omit, never invent.

## Known Pitfall (v2.71.0) — `requestAnimationFrame` is the wrong tool for persisting anything

The sidebar's scroll memory originally throttled its `sessionStorage` write through
`requestAnimationFrame`, which looks like ordinary scroll-handler hygiene. It silently disabled the
entire feature: **a page that is not painting does not run rAF callbacks**, so a background tab, a
minimised window, or a scroll immediately followed by a navigation stored nothing at all.

It was caught in browser verification, and only because the stored value came back *absent* rather
than *wrong* — a plausible-looking wrong number would have been much easier to explain away.

**The rule.** rAF is for work whose only purpose is the next FRAME (measuring layout, driving an
animation). Persistence has to happen whether or not anything is ever drawn again, so write it
synchronously. A `sessionStorage` write of one short string is cheap; throttling it is optimising the
wrong thing.

## Known Pitfall (v2.70.0) — Inertia's `useForm().transform()` returns void, so chaining `.post()` off it throws before the request is ever made

Written as:

```jsx
// WRONG -- throws "Cannot read properties of undefined (reading 'post')"
form.transform(() => ({ package_id: id, billing_cycle: cycle }))
    .post(route('subscription.plan-change'));
```

In `@inertiajs/react`, `transform()` sets the transformer and returns **nothing**. The chain
therefore calls `.post()` on `undefined`.

What makes this worth recording is the **failure mode**, not the mistake: the exception is thrown
inside a React event handler, so nothing navigates, nothing errors visibly, and the dialog simply
sits there as though the click had not registered. There is no failed request in the network panel
— the request was never made — and no server log line, because the server was never reached. The
only evidence is one line in the browser console.

```jsx
// RIGHT
form.transform(() => ({ package_id: id, billing_cycle: cycle }));
form.post(route('subscription.plan-change'), { onSuccess: () => setConfirming(null) });
```

**Feature tests cannot catch this class of bug.** The plan-change endpoint had full coverage —
upgrade, downgrade, capacity refusal, tenant isolation — and every test passed, because tests POST
to the route directly and never execute the component that builds the request. It was found by
clicking the button in a browser and reading the console, which is exactly what
`LOCAL-VERIFICATION.md` step 4 exists for. A green suite says the endpoint is correct; it says
nothing about whether anything reaches it.

## "Verify first" as a default posture

Before building something that sounds like a new feature, check whether the underlying mechanism
already exists and only the surface is missing. The concrete example: `ActivityLog` (a polymorphic
activity-recording model) already existed and was already used 32+ times across controllers before
anyone built a way to *view* that data — the real gap was a viewing component, not the recording
mechanism, and building a second, parallel logging system would have been pure duplication. This
kind of check is cheap (a few minutes of reading) relative to the cost of maintaining a duplicate
system afterward.
