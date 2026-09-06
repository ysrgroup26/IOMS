<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.51.0 -- self-service tenant onboarding.
 *
 * A registration is a PROSPECT, not a tenant. It deliberately lives in
 * its own table rather than creating a half-built Tenant row up front,
 * because a Tenant row is a live isolation boundary: everything from
 * TenantScope to entitlements to numbering assumes a tenant exists
 * because someone bought IOMS. Creating one for every abandoned form
 * would put unpaid, unverified, unauthenticated shells inside the
 * boundary and force every downstream query to start asking "but is this
 * tenant real?".
 *
 * So the prospect's details sit here until payment is confirmed
 * server-side, and only then does provisioning create Tenant + Company +
 * User + Subscription in one transaction. Until that moment a
 * registration has no application access whatsoever -- there is no user
 * account to log in with.
 *
 * `password` holds an ALREADY-HASHED value (see RegistrationController --
 * the plaintext never reaches this table) so the administrator can set
 * their own password before paying and use it the moment they are
 * provisioned, without IOMS ever emailing a credential.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_registrations', function (Blueprint $table) {
            $table->id();

            // Public-facing identifiers. `token` addresses the registration
            // in URLs -- long and random, so a registration cannot be
            // enumerated or guessed from its id. `reference` is the short
            // human-quotable code used in email subjects and support.
            $table->string('token', 64)->unique();
            $table->string('reference', 32)->unique();

            $table->string('status', 32)->default('pending_verification')
                ->comment('pending_verification, verified, awaiting_payment, paid, provisioned, expired, cancelled');

            // --- Account (becomes the tenant's first administrator) ---
            $table->string('contact_name');
            $table->string('contact_email');
            $table->string('contact_phone', 50)->nullable();
            $table->string('password');

            // --- Company / tenant identity ---
            $table->string('company_legal_name');
            $table->string('company_display_name')->nullable();
            $table->string('company_industry', 100)->nullable();
            $table->string('company_address', 500)->nullable();
            $table->string('company_city', 120)->nullable();
            $table->string('company_province', 120)->nullable();
            $table->string('company_postal_code', 20)->nullable();
            $table->string('company_country', 100)->default('Indonesia');
            $table->string('company_phone', 50)->nullable();
            $table->string('company_email')->nullable();
            $table->string('company_tax_id', 50)->nullable()->comment('NPWP');
            $table->string('company_business_id', 50)->nullable()->comment('NIB');
            $table->string('company_logo_path')->nullable();

            // Where invoices and payment notices go. Defaults to the
            // account email; kept separate because finance and the
            // administrator are frequently not the same person.
            $table->string('billing_email')->nullable();

            // --- Commercial terms, ALWAYS server-derived ---
            // amount/currency are snapshotted from the packages table at
            // checkout time so a client cannot submit its own price and so
            // a later catalog change never silently re-prices an invoice
            // that was already issued.
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->string('billing_cycle', 16)->default('yearly');
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 3)->default('IDR');

            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Filled only by provisioning, never by the prospect.
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('ip_address', 45)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('contact_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_registrations');
    }
};
