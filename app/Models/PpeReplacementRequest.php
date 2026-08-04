<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PpeReplacementRequest extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    protected $fillable = [
        'request_number',
        'request_date',
        'company_id',
        'requested_by',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function items()
    {
        return $this->hasMany(PpeReplacementRequestItem::class);
    }

    public function scopeVisibleTo($query, $user)
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->where('company_id', $user->company_id);
    }

    /**
     * PRR-{YEAR}-{00001}, same convention as Material Request's MR-* and
     * the Task Engine's TSK-*.
     */
    public static function generateRequestNumber(): string
    {
        $year = now()->year;
        $lastNumber = static::withTrashed()
            ->where('request_number', 'like', "PRR-{$year}-%")
            ->orderByDesc('id')
            ->value('request_number');

        $sequence = $lastNumber ? ((int) substr($lastNumber, -5)) + 1 : 1;

        return sprintf('PRR-%d-%05d', $year, $sequence);
    }
}
