<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 2 (Dynamic Workspace system, Task #43). DB-driven metadata
 * override for a `resources/js/lib/workspaces.js` WORKSPACES entry --
 * label/icon/order/active-state only. See the migration's own doc
 * comment for why the structural item list (routes, gates) stays in
 * code rather than becoming data-driven.
 */
class Workspace extends Model
{
    protected $fillable = [
        'key',
        'label',
        'icon',
        'tier',
        'is_core',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
