<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * v2.51.0 -- a prospect part-way through IOMS onboarding.
 *
 * Deliberately NOT a Tenant. See the create_tenant_registrations_table
 * migration for why a pending signup must stay outside the tenant
 * isolation boundary until payment is confirmed server-side.
 *
 * A registration carries no application access of any kind: no user
 * account exists until TenantProvisioningService runs, so "pending tenant
 * cannot access the application" is true by construction rather than by
 * a middleware check that could be bypassed.
 */
class TenantRegistration extends Model
{
    /*
     |-------------------------------------------------------------------
     | v2.94.0 -- AN UNFINISHED ORDER IS ALWAYS RESUMABLE.
     |-------------------------------------------------------------------
     | A customer who starts in October and comes back in November resumes
     | the SAME registration and the SAME invoice, repriced to the current
     | catalogue. There is one order per account and it does not lapse.
     |
     | `expires_at` is still written, as a record of when the order was
     | raised plus the checkout window, but NOTHING READS IT to decide
     | anything. It used to be read in two places that disagreed: the
     | account page hid an order past it, while the subscribe flow resumed
     | the same row and pushed the date forward -- and the public status
     | page DISABLED THE PAY BUTTON on it. So whether a returning customer
     | could pay depended on which link they came back through, which is
     | the worst possible way for a payment to be unavailable.
     |
     | `STATUS_EXPIRED` and `OPEN_STATUSES` were removed with it. Nothing
     | ever wrote the status, and `OPEN_STATUSES` was dead code whose
     | docblock claimed it held an email address against duplicate signups
     | -- a claim nothing enforced.
     */
    public const STATUS_PENDING_VERIFICATION = 'pending_verification';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_PROVISIONED = 'provisioned';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'token', 'reference', 'status',
        'contact_name', 'contact_email', 'contact_phone', 'password',
        'company_legal_name', 'company_display_name', 'company_industry',
        'company_address', 'company_city', 'company_province', 'company_postal_code',
        'company_country', 'company_phone', 'company_email',
        'company_tax_id', 'company_business_id', 'company_logo_path',
        'billing_email',
        'package_id', 'billing_cycle', 'amount', 'currency',
        'email_verified_at', 'paid_at', 'provisioned_at', 'expires_at',
        'invoice_id', 'tenant_id', 'user_id', 'ip_address', 'notes',
    ];

    /**
     * `password` is already a bcrypt hash by the time it reaches this
     * model and must never be serialized anywhere -- hidden so it cannot
     * leak through an accidental toArray()/Inertia prop.
     */
    protected $hidden = ['password', 'token'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'email_verified_at' => 'datetime',
            'paid_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isProvisioned(): bool
    {
        return $this->status === self::STATUS_PROVISIONED;
    }

    /** The name the tenant/company should actually be called -- display name if the company gave one, otherwise its legal name. */
    public function displayName(): string
    {
        return $this->company_display_name ?: $this->company_legal_name;
    }

    public function billingEmail(): string
    {
        return $this->billing_email ?: $this->contact_email;
    }

    public static function newToken(): string
    {
        return Str::random(64);
    }

    /** REG-YYYY-XXXXXX. Short enough to quote in an email subject, random enough not to disclose signup volume. */
    public static function newReference(): string
    {
        return 'REG-'.now()->format('Y').'-'.strtoupper(Str::random(6));
    }
}
