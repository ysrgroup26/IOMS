<?php

/**
 * Milestone 3 (Import/Export Mapping, Task #67). The field catalog
 * `App\Services\FieldMappingService` and Settings > Import/Export
 * Mapping both read -- what fields a module's import/export actually
 * supports, and their built-in default column label (what the field
 * behaves as when a tenant has never configured a mapping at all, so
 * every existing import/export keeps working byte-for-byte unchanged
 * until an admin visits this settings tab).
 *
 * Adding a field here is the entire integration point for a future
 * importer/exporter to become mapping-aware -- no new controller/UI
 * code needed, only reading the mapping in that Import/Export class
 * (see EmployeesImport::mappedField() / EmployeeExport's constructor
 * for the reference implementation).
 */
return [
    'employees' => [
        'import' => [
            'employee_id' => 'Employee ID',
            'full_name' => 'Full Name',
            'department' => 'Department',
            'position' => 'Position',
            'join_date' => 'Join Date',
            'employment_status' => 'Employment Status',
            'phone' => 'Phone',
            'email' => 'Email',
            'address' => 'Address',
            'emergency_contact_name' => 'Emergency Contact Name',
            'emergency_contact_phone' => 'Emergency Contact Phone',
            'project' => 'Project',
        ],
        'export' => [
            'employee_id' => 'Employee ID',
            'full_name' => 'Full Name',
            'company' => 'Company',
            'department' => 'Department',
            'position' => 'Position',
            'status' => 'Status',
            'join_date' => 'Join Date',
            'phone' => 'Phone',
        ],
    ],
];
