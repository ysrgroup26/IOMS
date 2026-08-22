<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v1.11.6 -- idempotency ledger for inbound payment webhooks. See the
 * owning migration's own doc comment. `recordIfNew()` is the only
 * intended write path: it relies on the (gateway, event_id) unique
 * constraint to detect a duplicate delivery atomically rather than a
 * check-then-insert race.
 */
class PaymentWebhookEvent extends Model
{
    protected $fillable = [
        'gateway', 'event_id', 'event_type', 'payload', 'verified', 'processed', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'verified' => 'boolean',
            'processed' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Returns the existing row (already processed -- caller must skip
     * reapplying it) if this (gateway, event_id) was seen before, or
     * creates and returns a new one otherwise. The unique constraint is
     * what actually makes this race-safe, not this method's own
     * check-first read.
     */
    public static function recordIfNew(string $gateway, string $eventId, ?string $eventType, array $payload, bool $verified): self
    {
        return static::firstOrCreate(
            ['gateway' => $gateway, 'event_id' => $eventId],
            ['event_type' => $eventType, 'payload' => $payload, 'verified' => $verified]
        );
    }
}
