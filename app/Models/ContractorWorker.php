<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Acceleration Part 4. See the owning migration's own doc comment. */
class ContractorWorker extends Model
{
    public const HSE_STATUSES = ['pending', 'fit_for_work', 'not_fit', 'inducted', 'expired'];

    protected $fillable = ['contractor_id', 'name', 'worker_id_number', 'position', 'competency', 'hse_status', 'notes'];

    public function contractor()
    {
        return $this->belongsTo(Contractor::class);
    }
}
