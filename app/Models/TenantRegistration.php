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
    public const STATUS_PENDING_VERIFICATION = 'pending_verification';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_PROVISIONED = 'provisioned';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    /** A registration in one of these states is still "live" -- it holds its email address against duplicate signups. */
    public const OPEN_STATUSES = [
        self::STATUS_PENDING_VERIFICATION,
        self::STATUS_VERIFIED,
        self::STATUS_AWAITING_PAYMENT,
        self::STATUS_PAID,
        self::STATUS_PROVISIONED,
    ];

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

    /** An abandoned checkout stops being actionable rather than lingering forever as a live claim on an email address. */
    public function isExpired(): bool
    {
        return $this->status !== self::STATUS_PROVISIONED
            && $this->expires_at !== null
            && $this->expires_at->isPast();
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
