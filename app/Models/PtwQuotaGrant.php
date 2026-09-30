<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * v2.86.0 -- one grant of PTW document quota to a tenant.
 *
 * Two kinds, and the difference between them is entirely in `expires_at`:
 * an INCLUDED grant has one and a PURCHASED grant does not. That is the
 * approved rule expressed as data rather than as a branch, so nothing has to
 * remember which pool expires.
 */
class PtwQuotaGrant extends Model
{
    protected $fillable = [
        'tenant_id', 'kind', 'quantity', 'consumed', 'effective_from',
        'expires_at', 'term_started_at', 'term_sequence', 'invoice_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'consumed' => 'integer',
            'term_sequence' => 'integer',
            'effective_from' => 'datetime',
            'expires_at' => 'datetime',
            'term_started_at' => 'datetime',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function consumptions()
    {
        return $this->hasMany(PtwQuotaConsumption::class);
    }

    /**
     * Grants that can still be spent right now: already effective, and
     * either never expiring or not yet expired.
     *
     * A null `expires_at` is what makes purchased quota carry forward, so it
     * is spelled out here once and every caller inherits it.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->where('effective_from', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function getRemainingAttribute(): int
    {
        return max(0, $this->quantity - $this->consumed);
    }
}
