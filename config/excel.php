<?php

use Maatwebsite\Excel\DefaultValueBinder;
use Maatwebsite\Excel\Excel;

return [
    'exports' => [
        'chunk_size' => 1000,
        'pre_calculate_formulas' => false,
        'strict_null_comparison' => false,
        'csv' => [
            'delimiter' => ',',
            'enclosure' => '"',
            'line_ending' => PHP_EOL,
            'use_bom' => false,
            'include_separator_line' => false,
            'excel_compatibility' => false,
        ],
        'properties' => [
            'creator' => 'IOMS',
            'lastModifiedBy' => 'IOMS',
            'title' => 'HSE KPI Report',
            'description' => 'Exported from '.config('ioms.name'),
            'subject' => 'HSE KPI Report',
            'keywords' => 'hse,kpi,operations,report',
            'category' => 'HSE',
            'manager' => 'HSE Department',
            'company' => 'IOMS',
        ],
    ],

    'imports' => [
        'read_only' => true,
        'ignore_empty' => true,
        'heading_row' => [
            'formatter' => 'slug',
        ],
        'csv' => [
            'delimiter' => null,
            'enclosure' => '"',
            'escape_character' => '\\',
            'contiguous' => false,
            'input_encoding' => 'UTF-8',
        ],
        'properties' => [
            'creator' => 'IOMS',
        ],
    ],

    'extension_detector' => [
        'xlsx' => Excel::XLSX,
        'xlsm' => Excel::XLSX,
        'xltx' => Excel::XLSX,
        'xltm' => Excel::XLSX,
        'xls' => Excel::XLS,
        'xlt' => Excel::XLS,
        'csv' => Excel::CSV,
    ],

    'value_binder' => [
        'default' => DefaultValueBinder::class,
    ],

    'cache' => [
        'driver' => 'memory',
    ],

    'transactions' => [
        'handler' => 'db',
    ],

    'temporary_files' => [
        'local_path' => storage_path('framework/cache/laravel-excel'),
        'remote_disk' => null,
        'remote_prefix' => null,
        'force_resync_remote' => null,
    ],
];
