<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Acceleration Part 1B. Rack/bin-level location within a Warehouse. */
class StorageLocation extends Model
{
    protected $fillable = ['warehouse_id', 'code', 'area', 'description'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }
}
