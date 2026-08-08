<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 3 (Dynamic Document Engine, Task #66). See the creating
 * migration's doc comment. Rendered by `App\Services\DocumentEngine`,
 * never referenced directly by a module's PDF export.
 */
class DocumentTemplate extends Model
{
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
