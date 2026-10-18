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
            /*
             * v2.108.1 -- the two real Employee columns the import could
             * not reach. `nik` is the NATIONAL ID (KTP) and is unique per
             * company; `employment_type` is Workforce Type, which the
             * manual form has always required and the import silently
             * defaulted to `pkwtt` for every row it created.
             *
             * Import side only. The export catalogue is deliberately left
             * alone: adding a column there changes the shape of every
             * existing tenant's spreadsheet without being asked to.
             */
            /*
             * LABELLED "NIK (KTP)", NOT "NIK", AND THAT IS THE WHOLE POINT.
             *
             * A bare "NIK" heading in an Indonesian workforce list means
             * Nomor Induk KARYAWAN -- the staff number -- and
             * `import_aliases` has mapped it to `employee_id` since
             * v2.102.0, pinned by SmartImportHeaderMatchingTest. Labelling
             * this field "NIK" made the matcher's EXACT stage (which runs
             * before aliases) hand that heading to the national ID
             * instead, quietly moving every customer's staff number into
             * the wrong column. Naming KTP here keeps the two apart.
             */
            'nik' => 'NIK (KTP)',
            'employment_type' => 'Workforce Type',
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

    /*
     * v2.120.0 -- BULK PPE ISSUANCE.
     *
     * A deliberately small catalogue: this import attaches equipment to
     * people who already exist, so it needs the person, the kind of
     * equipment, and when it was handed over. Anything else about the
     * employee belongs to the employee importer, and anything else about
     * the PPE type belongs to PPE Master.
     *
     * No `export` half. The PPE registers are already published by the
     * Document Library (ADR 053), and adding a second definition of the
     * same columns is what that ADR exists to prevent.
     */
    'employee_ppe' => [
        'import' => [
            'employee_id' => 'Employee ID',
            'ppe_type' => 'PPE Type',
            'issued_date' => 'Issued Date',
            'expiry_date' => 'Expiry Date',
            'remarks' => 'Remarks',
        ],
    ],
];
