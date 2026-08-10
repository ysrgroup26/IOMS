<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Acceleration Part 1B (Warehouse Master). */
class Warehouse extends Model
{
    protected $fillable = ['code', 'company_id', 'name', 'location', 'pic_id', 'status'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function pic()
    {
        return $this->belongsTo(User::class, 'pic_id');
    }

    public function storageLocations()
    {
        return $this->hasMany(StorageLocation::class);
    }

    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->orderBy('name');
    }
}
