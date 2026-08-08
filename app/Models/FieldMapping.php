<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 3 (Import/Export Mapping, Task #67). See the creating
 * migration's doc comment. Read/written exclusively through
 * `App\Services\FieldMappingService`.
 */
class FieldMapping extends Model
{
    public const DIRECTION_IMPORT = 'import';

    public const DIRECTION_EXPORT = 'export';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'module_key',
        'direction',
        'field_key',
        'column_label',
        'sort_order',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }
}
