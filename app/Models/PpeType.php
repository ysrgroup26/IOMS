<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PpeType extends Model
{
    protected $fillable = ['name', 'replacement_interval_months', 'is_active'];

    protected function casts(): array
    {
        return [
            'replacement_interval_months' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function assignments()
    {
        return $this->hasMany(EmployeePpe::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('name');
    }

    /**
     * True for request-based equipment (e.g. Harness, Headlamp) that has
     * no fixed replacement schedule but must still be recorded in history.
     */
    public function isRequestBased(): bool
    {
        return is_null($this->replacement_interval_months);
    }
}
