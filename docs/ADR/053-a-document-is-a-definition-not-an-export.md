# ADR 053 — A document is a definition, not an export

- **Status:** Accepted
- **Date:** 2026-10-12
- **Release:** v2.110.0
- **Supersedes:** nothing. Extends ADR 052 (a suggested mapping must be the one that runs).

## Context

By v2.109.0 IOMS could produce documents, but only the way it had grown them: one module at a
time. The Employee Register had an `EmployeeExport` class, a `pdf.employee-register` Blade view and
two controller methods. Material Request, Purchase Order, Goods Receipt, Permit to Work and the
rest each had their own. Every one of them was correct, and no two of them were built the same way.

Two concrete consequences, both observed in this repository rather than imagined:

1. **The Excel export and the PDF of the same list were written months apart from two separate
   queries.** Nothing made them agree; they agreed because nobody had changed either one yet.
2. **HSE Inspection — the record a client auditor is most likely to ask for on paper — had no
   printable output at all**, because nobody had written its one-off yet.

The reading side was worse. Exactly one input format was accepted (`.xlsx`/`.xls`), the heading row
was assumed to be row 1, and only the first sheet was read. A customer whose workforce list was a
Word table, a CSV export from a payroll system, or a PDF from another vendor had to retype it. A
spreadsheet with a company name and a report title above the table — which is what a real roster
looks like — was rejected as having no headings.

## Decision

### 1. A document is described once and rendered three ways

A `DocumentDefinition` says **what** a document is: its columns (with a type), its rows (already
tenant-scoped), the reference lines that state which filters are in force, and who may read it. It
says nothing about XLSX, DOCX or PDF.

`DocumentRenderer` turns one definition into any of the three formats from **one** materialised set
of rows. The three outputs are different presentations of one query. They cannot disagree, because
there is nothing for them to disagree about.

`DocumentLibrary` is the registry. Adding a document is one class and one line — no route, no
controller, no export class, no Blade view.

### 2. The registry is code, not configuration

A definition carries a tenant-scoped **query** and an **authorization rule**. Expressing those in a
config array would mean either serialised closures or a home-grown query language, and a mistake in
either is a cross-tenant read. A class is also the thing a reviewer can actually read.

### 3. Tenant scoping and authorization belong to the definition

The renderer never filters and never re-checks. Every definition anchors its query on
`Company::query()`, which `CompanyScope` has already scoped to the current tenant. A second gate in
the renderer or the controller would be the same rule in two places, and a rule in two places is a
rule that will eventually disagree with itself.

`DocumentLibrary::resolveFor()` fails closed on both the key and the user, and an unauthorized key
answers **404, identically to an unknown key**, so the library cannot be used to enumerate the
documents other roles can see.

### 4. Reading is a pipeline of replaceable extractors, and it never guesses silently

- Format is detected by **content** (magic bytes), not by file extension or by the browser's
  claimed MIME type.
- **Macro-enabled containers are refused outright**, never opened.
- Every sheet and every table is extracted, not just the first.
- The heading row is **located** by scoring candidate rows, not assumed to be row 1.
- A PDF table is reconstructed from `getDataTm()` glyph positions, reported at a confidence **below
  certainty**, with a note saying where the structure came from.
- A PDF with no text layer is reported as **scanned**, never returned as an empty success.

### 5. Every input format reaches the importer that already exists

A CSV, a Word table or a PDF table is normalised by `TabularBridge` into a clean, minimal `.xlsx`
— headings on row 1, values beneath, every cell written as an explicit string — and that file is
handed to `EmployeesImport`, unchanged.

This is the single most important decision in the change. The alternative was a second import path
for non-spreadsheet formats, which would have meant a second copy of the day-first date parser, the
NIK registry, the employment-type aliases and the row-accurate error reporting. One set of
validation rules, four input formats.

### 6. Pure PHP, because of the host, not because of taste

Carbone, Docxtemplater, pdfmake, SheetJS, Apache Tika and OCRmyPDF were each evaluated against the
production host and each ruled out: they require Node.js, a JVM, LibreOffice or system binaries,
and the deployment target is cPanel shared hosting with none of those and no root (ADR 027/028).

What is used instead: PhpSpreadsheet (MIT), PhpWord (LGPL-3.0), dompdf (LGPL-2.1) and
smalot/pdfparser (LGPL-3.0) — all pure PHP, all needing only extensions the host already has, all
free, all self-hosted. No paid API, no per-document fee, no SaaS dependency, and no customer
document leaves the server.

### 7. OCR is an adapter that tells the truth

`OcrExtractor` detects `tesseract` and `pdftoppm` at runtime and is disabled by default. When it is
unavailable it says so, **with the reason**, and the UI prints that reason. It explicitly refuses
cloud OCR: a customer's confidential document is not sent to a third party to be read.

## Amendment (v2.111.0) — the bridge is a fallback, not a front door

The original rule was "a `.xlsx`/`.xls` is passed through untouched; everything else is bridged".
Running the flow through the UI showed that it split the wrong way.

**What was wrong.** `inspect` located the heading row, but `preview` and `import` handed the raw
workbook to a reader that assumes row 1. A customer could be shown a correct mapping read from row
5 and then import **nothing** — a preview that promises rows and an import that writes none, which
is a worse failure than a refusal.

**What it is now.** The decision is made per FILE, not per format:

1. A spreadsheet whose located tables all start at row 1 → **passed through untouched**. This keeps
   two properties the bridge cannot: Maatwebsite reads every sheet (the bridge carries one table),
   and a real Excel date cell stays a date cell.
2. A spreadsheet whose table starts further down → **bridged**, but only when the located headings
   map to at least two real IOMS fields. A heuristic may improve a path; it must not become a new
   way to lose it. The locator proved it can be wrong — a twelve-column template whose rows fill
   five columns made it pick the first data row as the headings — so the fallback is not optional.
3. CSV, Word, PDF → **always bridged**. There is no older path to fall back to.

**And the row numbers moved with it.** `EmployeesImport` gained a `rowOffset` that shifts only what
is REPORTED, so a skipped row names a line in the file the customer actually has. Without it the
dialog said "Row 5 — Missing Full Name" about a roster whose row 5 is the heading row, directly
beside the words "fix this in your file".

## Consequences

**Good**

- One query backs every format of a document; the drift this layer exists to remove cannot recur.
- Four operational workspaces each gained a register they did not have, for the cost of one class
  each.
- IOMS gained DOCX output, which it had none of — a customer who needs to edit a report before
  sending it no longer retypes it.
- Three real customer-file shapes now import: a header block above the table, data on a second
  sheet, and a roster that only exists as a Word document or a PDF.

**Costs, accepted deliberately**

- A document definition is a class, so adding one is a code change and a deployment, not a settings
  screen. That is the price of putting a tenant-scoped query behind a reviewable type.
- A PDF table is a reconstruction. It is offered with its confidence and its note; it is not, and
  should never be presented as, a structured data source.
- The existing per-module one-offs (`pdf.permit-to-work`, `pdf.material-request`, the Employee
  Register's own two routes) were **not** migrated. They are purpose-built forms, not registers, and
  rewriting working documents to prove a point would have risked them for no gain. The library is
  where new registers go; it is not a mandate to rebuild the old forms.
- Scanned documents remain unreadable on the production host, and the product says so rather than
  returning an empty table.
