<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * v2.129.0 -- A BROWSER THAT HAS AGREED TO BE WOKEN UP.
 *
 * One row per browser profile, not per person: see the creating migration
 * for why, and for why the device's encryption keys are stored although a
 * payloadless push does not use them.
 *
 * Deliberately NOT tenant-scoped. A push subscription belongs to a USER,
 * and `user_id` is the whole of its ownership -- every read goes through
 * the owning user (`$user->pushSubscriptions`) or through an explicit
 * `where('user_id', ...)`, never through a tenant query. Adding a tenant
 * scope here would make the table look safer while changing nothing: the
 * row is reachable only from an account that is already resolved.
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'p256dh',
        'auth',
        'user_agent',
        'last_pushed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_pushed_at' => 'datetime',
        ];
    }

    /**
     * The unique key for an endpoint.
     *
     * MySQL cannot index a TEXT column's full length and an endpoint runs
     * past any sane VARCHAR, so the uniqueness that actually matters --
     * "this exact browser, once" -- is carried by a hash of it. SHA-256
     * and not `Hash::make`: this is a LOOKUP key that must be identical
     * for identical input, which a salted password hash is specifically
     * designed not to be.
     */
    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The push service this endpoint belongs to, as its origin.
     *
     * A VAPID token is issued FOR an audience (the push service's origin)
     * and is rejected by any other, so this is what the signature is bound
     * to. Null for an endpoint that is not a URL at all, which is refused
     * rather than signed for.
     */
    public function audience(): ?string
    {
        $parts = parse_url($this->endpoint);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'];
    }
}
