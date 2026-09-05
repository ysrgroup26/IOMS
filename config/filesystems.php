<?php

return [

    'default' => env('FILESYSTEM_DISK', 'public'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        // Employee photos, company logo
        'uploads' => [
            'driver' => 'local',
            'root' => storage_path('app/public/uploads'),
            'url' => env('APP_URL').'/storage/uploads',
            'visibility' => 'public',
            'throw' => false,
        ],

        // v2.38.0 (Master Audit, P1). Destination for tenant-sensitive
        // operational documents (contractor licences, competency
        // certificates, HIRADC/JSA, waste manifests, inspection
        // evidence). Deliberately NOT under storage/app/public and NOT
        // reachable through the public/storage symlink -- the only way
        // out is SecureDocumentController, which enforces authentication
        // and the tenant boundary.
        //
        // `serve` is false on purpose: Laravel's own serve route would
        // grant access on a valid signature alone, which is bearer-style
        // and carries no tenant check. IOMS needs the ownership check, so
        // delivery goes through the controller instead.
        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private-documents'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => false,
        ],

        // Database backups created via Settings > Backup Database
        'backups' => [
            'driver' => 'local',
            'root' => storage_path('app/backups'),
            'visibility' => 'private',
            'throw' => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
