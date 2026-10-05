<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsTenantWideConfiguration;
use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 3 (Import/Export Mapping, Task #67). See the creating
 * migration's doc comment. Read/written exclusively through
 * `App\Services\FieldMappingService`.
 */
class FieldMapping extends Model
{
    use BelongsToCompany;

    /*
     * v2.99.0 -- a null `company_id` on this table means "applies to the
     * whole tenant", not "unowned". Without this trait every row the
     * feature creates is invisible the moment it is saved.
     */
    use IsTenantWideConfiguration;

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
