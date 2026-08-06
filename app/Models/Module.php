<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 2 (Dynamic Module system, Task #42). The catalog of modules
 * that EXIST -- not tenant-scoped (platform-wide catalog, like Package).
 * Whether a given module is currently VISIBLE is a separate concern,
 * still handled by the pre-existing `enabled_modules` CompanySetting --
 * see this migration's own doc comment for why that split is deliberate.
 */
class Module extends Model
{
    protected $fillable = [
        'key',
        'label',
        'description',
        'is_core',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
        ];
    }

    public function scopeToggleable($query)
    {
        return $query->where('is_core', false);
    }
}
