<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsTenantWideConfiguration;
use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 3 (Dynamic Document Engine, Task #66). See the creating
 * migration's doc comment. Rendered by `App\Services\DocumentEngine`,
 * never referenced directly by a module's PDF export.
 */
class DocumentTemplate extends Model
{
    use BelongsToCompany;

    /*
     * v2.99.0 -- a null `company_id` on this table means "applies to the
     * whole tenant", not "unowned". Without this trait every row the
     * feature creates is invisible the moment it is saved.
     */
    use IsTenantWideConfiguration;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'module_key',
        'name',
        'is_default',
        'header_text',
        'footer_text',
        'show_logo',
        'show_qr',
        'show_signature',
        'show_watermark',
        'watermark_text',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'show_logo' => 'boolean',
            'show_qr' => 'boolean',
            'show_signature' => 'boolean',
            'show_watermark' => 'boolean',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
