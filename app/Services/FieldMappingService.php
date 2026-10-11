<?php

namespace App\Services;

use App\Models\FieldMapping;
use App\Support\CurrentTenant;
use Illuminate\Support\Str;

/**
 * Milestone 3 (Import/Export Mapping, Task #67). Resolves this tenant's
 * configured mapping for a module, falling back to
 * config/mapping_fields.php's default labels when nothing's configured
 * -- so every import/export keeps working unchanged until an admin
 * visits Settings > Import/Export Mapping.
 */
class FieldMappingService
{
    public function __construct(private readonly CurrentTenant $tenant) {}

    /**
     * Tenant-wide only (company_id null), same scope decision as
     * Approval Flow/Documents. Returns [field_key => ['label' => ..., 'sort_order' => ..., 'is_enabled' => ...]].
     */
    public function resolve(string $moduleKey, string $direction): array
    {
        $catalog = config("mapping_fields.{$moduleKey}.{$direction}", []);

        $configured = FieldMapping::where('tenant_id', $this->tenant->id())
            ->whereNull('company_id')
            ->where('module_key', $moduleKey)
            ->where('direction', $direction)
            ->get()
            ->keyBy('field_key');

        $order = 0;
        $result = [];
        foreach ($catalog as $fieldKey => $defaultLabel) {
            $row = $configured->get($fieldKey);
            $result[$fieldKey] = [
                'label' => $row->column_label ?? $defaultLabel,
                'sort_order' => $row->sort_order ?? $order,
                'is_enabled' => $row?->is_enabled ?? true,
            ];
            $order++;
        }

        return $result;
    }

    /**
     * For import: given a heading-row-normalized array of column keys
     * ($data from Maatwebsite's WithHeadingRow), returns $targetField =>
     * actual $data key to read from. Slugified with `Str::slug($v, '_')`
     * -- deliberately matching
     * `Maatwebsite\Excel\Imports\HeadingRowFormatter`'s own
     * `FORMATTER_SLUG` implementation byte-for-byte (config/excel.php
     * sets 'formatter' => 'slug'), NOT `Str::snake()`, which produces a
     * different result for words like "ID" (`Str::snake('Employee ID')`
     * = `employee_i_d`, not `employee_id`) -- caught by importing a real
     * file with a custom-mapped header and comparing the actual
     * resolved keys before trusting this method, not assumed.
     */
    public function importColumnKeys(string $moduleKey, array $confirmedHeadings = []): array
    {
        $fields = $this->resolve($moduleKey, FieldMapping::DIRECTION_IMPORT);

        $keys = collect($fields)->map(fn ($f, $fieldKey) => Str::slug(trim($f['label']), '_'))->all();

        /*
         * v2.108.1 -- THE MAPPING THE CUSTOMER WAS SHOWN, APPLIED.
         *
         * `$confirmedHeadings` is [field => the heading in THEIR file],
         * straight from what `/employees/import/inspect` suggested and the
         * dialog displayed for confirmation. Smart Import (v2.102.0) drew
         * that panel and then imported through the configured mapping
         * anyway, so a customer's own file matched on screen and imported
         * nothing.
         *
         * Converted here, not at the call site, because this class owns the
         * one rule that makes a heading into a `$data` key: `Str::slug`,
         * matching Maatwebsite's own slug formatter byte-for-byte -- NOT
         * `Str::snake`, which turns "Employee ID" into `employee_i_d`. A
         * second copy of that rule is a second chance to get it wrong.
         *
         * Only fields the caller actually confirmed are overridden, so an
         * import that sends no mapping behaves exactly as before.
         */
        foreach ($confirmedHeadings as $field => $heading) {
            if (array_key_exists($field, $keys) && is_string($heading) && trim($heading) !== '') {
                $keys[$field] = Str::slug(trim($heading), '_');
            }
        }

        return $keys;
    }

    /** For export: ordered, enabled [field_key => label] (keys preserved through filter/sort), ready to build headings()/map() from -- see EmployeeExport's constructor. */
    public function exportFields(string $moduleKey): array
    {
        $fields = $this->resolve($moduleKey, FieldMapping::DIRECTION_EXPORT);

        return collect($fields)
            ->filter(fn ($f) => $f['is_enabled'])
            ->sortBy('sort_order')
            ->map(fn ($f) => $f['label'])
            ->all();
    }

    public function upsert(string $moduleKey, string $direction, array $rows): void
    {
        $tenantId = $this->tenant->id();

        foreach ($rows as $order => $row) {
            FieldMapping::updateOrCreate(
                ['tenant_id' => $tenantId, 'company_id' => null, 'module_key' => $moduleKey, 'direction' => $direction, 'field_key' => $row['field_key']],
                ['column_label' => $row['column_label'], 'sort_order' => $order, 'is_enabled' => $row['is_enabled'] ?? true]
            );
        }
    }
}
