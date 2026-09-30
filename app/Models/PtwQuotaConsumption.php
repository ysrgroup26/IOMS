<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v2.86.0 -- one PTW document, spent.
 *
 * This row is the reason deleting a permit does not refund quota. It is not
 * a cascade target of `permits_to_work`, so it outlives the permit it paid
 * for, and `permit_to_work_id` is unique, so a permit can never spend twice.
 *
 * Kept as its own table rather than as a column on the permit because the
 * accounting has to survive the permit: a tenant's consumption history is a
 * billing record, and a billing record that disappears when an operational
 * record is deleted is not a billing record.
 */
class PtwQuotaConsumption extends Model
{
    protected $fillable = [
        'tenant_id', 'ptw_quota_grant_id', 'permit_to_work_id', 'kind', 'consumed_by',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function grant()
    {
        return $this->belongsTo(PtwQuotaGrant::class, 'ptw_quota_grant_id');
    }

    public function permit()
    {
        return $this->belongsTo(PermitToWork::class, 'permit_to_work_id');
    }

    public function consumer()
    {
        return $this->belongsTo(User::class, 'consumed_by');
    }
}
