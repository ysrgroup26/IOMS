<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Workstream C3. Invited-vendor response tracking -- see the owning migration's own doc comment. */
class RfqVendor extends Model
{
    public const STATUSES = ['invited', 'viewed', 'responded', 'no_response', 'declined', 'expired'];

    protected $fillable = ['rfq_id', 'vendor_id', 'status', 'invited_at'];

    protected function casts(): array
    {
        return ['invited_at' => 'datetime'];
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
