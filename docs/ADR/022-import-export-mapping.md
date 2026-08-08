# 022 — Import/Export Column Mapping (Milestone 3, Task #67)

## Status

Accepted.

## Decision

**`field_mappings` table**: `tenant_id` required + `company_id` nullable (tenant-wide only from this
UI, same scope decision as Approval Flow/Documents), `module_key`, `direction` (`import`/`export`),
`field_key`, `column_label`, `sort_order`, `is_enabled`. No DB unique constraint -- MySQL treats
`NULL company_id` as distinct per row, so it wouldn't actually enforce uniqueness for the
tenant-wide rows this feature exclusively writes; uniqueness is instead app-enforced via
`updateOrCreate` in `FieldMappingService::upsert()`.

**`config/mapping_fields.php`** is the field catalog -- what fields a module's import/export
supports and their default label, mirroring `config/analytics.php`'s "config entry is the whole
integration point" pattern. Only `employees` is registered today (the only real
importer/exporter in the app -- `EmployeesImport`/`EmployeeExport`).

**`App\Services\FieldMappingService`** resolves a tenant's configured mapping, falling back to the
catalog's default label when nothing's configured -- every import/export keeps working unchanged
until an admin visits Settings > Import/Export Mapping.
- `importColumnKeys()`: target field -> actual heading-row key to read from `EmployeesImport`'s
  `$data` array.
- `exportFields()`: ordered, enabled `[field_key => label]` for `EmployeeExport` to build
  `headings()`/`map()` from.

**`EmployeesImport`** gained an optional `$columnKeys` constructor param and a `col($data, $field)`
helper (`$data[$columnKeys[$field] ?? $field] ?? $data[$field] ?? null`) -- every existing
`$data['field'] ?? ''` read in `onRow()` now goes through it. Null/missing mapping falls back to the
original field name, so an unconfigured tenant's import is byte-for-byte unaffected.

**`EmployeeExport`** gained an optional `$fields` constructor param (`field_key => label`, in
export order); `headings()`/`map()` build from it when present, else the original fixed 8-column
layout.

**Settings > Import/Export Mapping**: per module, two side-by-side forms (Import / Export). Import
rows are always "included" (an importer looks for every field regardless); Export rows get an
enabled checkbox to omit a column entirely, plus reordering by the catalog's declared order
today (drag-to-reorder not built -- out of scope, "form-based" was the stated requirement, not a
sortable list).

## Bug caught during verification

First implementation of `importColumnKeys()` slugified a configured column label with
`Str::snake()`. Live test: mapped `full_name` to a custom header "Nama Karyawan", built a real
`.xlsx` file with that header, and imported it through the actual `EmployeesImport` class -- the
mapped field resolved correctly, but a side-by-side check of `employee_id`'s own (unmapped,
default-label "Employee ID") resolved key exposed the bug: `Str::snake('Employee ID')` produces
`employee_i_d` (treating "ID" as two separate capitalized words), not `employee_id`. It only
happened to work in that first test because `col()`'s fallback to the raw field name masked it for
every *unmapped* field -- a real custom label containing a run of capitals (e.g. mapping something
to "ID Karyawan") would have silently failed to match.

Root cause: `config/excel.php` sets `'heading_row' => ['formatter' => 'slug']`, and
`Maatwebsite\Excel\Imports\HeadingRowFormatter`'s slug formatter is `Str::slug($value, '_')`, a
different algorithm from `Str::snake()`. Fixed to call `Str::slug($label, '_')`, matching
Maatwebsite's own normalization exactly. Re-verified: `employee_id` resolves to `employee_id` (not
`employee_i_d`), and a fresh end-to-end import with the "Nama Karyawan" mapping produced the correct
`full_name` and correctly resolved `department` via master-data lookup.

## Consequences

- Only Employees is wired. A future importer/exporter (Incident, Leave, Task, ...) needs a
  `mapping_fields.php` catalog entry plus the same `col()`/`$fields`-param treatment in its own
  Import/Export class -- not automatic, since each importer's row-processing loop is its own class
  (per `EmployeesImport`'s own doc comment on why there's no shared base class yet).
- Export column reordering is catalog-order only from the UI (no drag-to-reorder) -- schema/service
  already support arbitrary `sort_order`, just no UI control for it yet.
