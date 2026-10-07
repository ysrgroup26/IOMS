<?php

/**
 * v2.105.0 -- WORDS THAT ARE NOT WORDS.
 *
 * `App\Support\NameFormatter` writes a Position or a person's name in
 * Title Case, which would turn "HSE Officer" into "Hse Officer" and
 * "Supervisor GAJ" into "Supervisor Gaj". These are the tokens that stay
 * exactly as they are: initialisms a reader recognises as letters rather
 * than as a word.
 *
 * Matched case-insensitively against each whole word, so a spreadsheet
 * that says "hse officer" still produces "HSE Officer".
 *
 * Departments are stored entirely in upper case (see NameFormatter), so
 * nothing here affects them -- this list exists for the Title Case forms.
 *
 * Adding one: only add a token that is genuinely read as letters. A real
 * word that happens to be short ("PLN" yes, "Site" no) -- getting that
 * wrong makes ordinary words SHOUT in the middle of a sentence.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Module labels
    |--------------------------------------------------------------------------
    |
    | v2.106.0 -- THE PRODUCT'S OWN NAME FOR EACH DOCUMENT TYPE.
    |
    | Settings' Numbering, Approval Flow, Documents and Import/Export Mapping
    | tabs all choose a module, and every one of them rendered the stored key
    | with its underscores swapped for spaces: "material request",
    | "permit to work", "hse inspection". Several then applied the CSS
    | `capitalize` rule on top, which capitalises the first letter of every
    | word and therefore produced "Hse Inspection", "Jsa" and "Loto".
    |
    | A mechanical transformation cannot get these right, which is the whole
    | reason this list exists rather than a clever function:
    |
    |   permit_to_work   -> "Permit to Work", with a LOWER-CASE "to"
    |   hse_inspection   -> "HSE Inspection", not "Hse Inspection"
    |   jsa              -> "JSA", not "Jsa"
    |   ppe_replacement_request -> "PPE Replacement Request"
    |
    | Mirrored in `resources/js/lib/moduleLabels.js` for the pages that need
    | it client-side, and `ProductTerminologyTest` fails if the two lists
    | drift or if a module key gains no label.
    |
    | A key with no entry falls back to Title Case with acronyms preserved,
    | which is right for a new module until somebody names it properly.
    |
    */

    'module_labels' => [
        'material_request' => 'Material Request',
        'incident' => 'Incident',
        'incident_investigation' => 'Incident Investigation',
        'leave_request' => 'Leave Request',
        'employee_case' => 'Employee Case',
        'goods_receipt' => 'Goods Receipt',
        'ppe_replacement_request' => 'PPE Replacement Request',
        'task' => 'Task',
        'milestone' => 'Milestone',
        'safety_observation' => 'Safety Observation',
        'risk_assessment' => 'Risk Assessment',
        'jsa' => 'JSA',
        'permit_to_work' => 'Permit to Work',
        'tbm' => 'TBM',
        'hse_inspection' => 'HSE Inspection',
        'loto' => 'LOTO',
        'waste_record' => 'Waste Record',
        'vendor' => 'Vendor',
        'purchase_requisition' => 'Purchase Requisition',
        'rfq' => 'RFQ',
        'purchase_order' => 'Purchase Order',
        'item' => 'Item',
        'stock_movement' => 'Stock Movement',
        'asset' => 'Asset',
        'maintenance_request' => 'Maintenance Request',
        'work_order' => 'Work Order',
        'inspection_request' => 'Inspection Request',
        'ncr' => 'NCR',
        'contractor' => 'Contractor',
        'visitor' => 'Visitor',
        'controlled_document' => 'Controlled Document',
        'handover_record' => 'Handover Record',
        'regulation_register' => 'Regulation Register',
        'employees' => 'Employees',
    ],

    'acronyms' => [

        // The established IOMS vocabulary, which CLAUDE.md's language
        // hierarchy already says is never translated either way.
        'HSE', 'HSSE', 'SHE', 'PPE', 'JSA', 'HIRADC', 'PTW', 'LOTO',
        'CAPA', 'TBM', 'NCR', 'RFQ', 'FPB', 'SPK', 'K3', 'P3K', 'APD',

        // Organizational units and functions.
        'HRD', 'HR', 'GA', 'IT', 'QA', 'QC', 'QHSE', 'HSEQ', 'MTC',
        'PPIC', 'SDM', 'RND', 'EHS',

        // Operating units and common Indonesian company abbreviations.
        'GAJ', 'PT', 'CV', 'UD', 'PLN', 'BUMN', 'KSO',

        // Marine and yard work, where IOMS actually operates.
        'ABK', 'ROV', 'NDT', 'WPS', 'PQR', 'AC', 'DC', 'HVAC',

    ],

];
