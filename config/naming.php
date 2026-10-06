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
