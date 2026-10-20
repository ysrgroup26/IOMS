<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * v2.127.0 -- WHO VERIFIES AND WHO APPROVES A PERMIT, PER OPERATING UNIT.
 *
 * One row per company. See the owning migration for why this is a table
 * rather than a `CompanySetting` (a permit belongs to an operating unit,
 * and a tenant-keyed store cannot route three yards differently), and why
 * each stage carries both ROLES and NAMED USERS.
 *
 * This model holds the stored configuration and nothing else. Every
 * question worth asking of it -- may this person verify, who gets told,
 * what happens when nothing is configured -- is answered by
 * `App\Services\PtwWorkflowService`, because those answers are also needed
 * for companies that have no row at all, and a model cannot answer for a
 * row that does not exist.
 */
class PtwWorkflowSetting extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'verify_roles',
        'verify_user_ids',
        'approve_roles',
        'approve_user_ids',
    ];

    protected function casts(): array
    {
        return [
            'verify_roles' => 'array',
            'verify_user_ids' => 'array',
            'approve_roles' => 'array',
            'approve_user_ids' => 'array',
        ];
    }
}
