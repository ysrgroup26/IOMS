<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.14.0 (SaaS Productization / Pricing Foundation). Package already has
 * everything Part 3 of that phase's directive asks for EXCEPT these four --
 * confirmed by a fresh audit of app/Models/Package.php and this table's own
 * migration before adding anything, per that phase's "reuse existing
 * architecture, do not create a redundant `plans` table" instruction:
 *
 * - `currency` -- every price on this table was always implicitly IDR
 *   (matching `config('saas.default_currency')`, the same default
 *   `invoices`/`payment_transactions` already use) but never actually
 *   stored anywhere on Package itself. Explicit now so a formatted price
 *   never has to guess which currency a given plan's numbers are in.
 * - `trial_days` -- Subscription already has a full trial state machine
 *   (`STATUS_TRIAL`, `trial_ends_at`) but nothing defines how MANY days a
 *   given Plan's trial should last; this is that missing per-Plan input.
 *   Nullable: null means "this plan has no trial" (e.g. Enterprise, sold
 *   by negotiation, or a free Starter tier with nothing to trial).
 * - `is_public` -- whether this plan should appear on the tenant-facing
 *   Plans/pricing comparison page at all. Lets a Platform Admin define an
 *   internal-only or retired plan (e.g. a legacy grandfathered tier still
 *   assigned to one tenant) without it showing up as something a tenant
 *   could request. Defaults true so every existing seeded plan keeps
 *   showing exactly as it does today -- this migration changes no
 *   existing plan's visibility.
 * - `is_custom` -- marks a plan as "contact us for pricing" (Enterprise)
 *   rather than a fixed self-serve price -- the Plans page reads this to
 *   show a CTA instead of a number, never to fabricate one. Defaults
 *   false; nothing currently seeded needs to be backfilled true (the
 *   Platform Admin can flip Enterprise to custom explicitly if desired --
 *   this migration does not assume that decision for them).
 *
 * Deliberately NOT added here (see this phase's own "Part 15: migration
 * discipline" audit): a `billing_interval` column (the existing
 * `price_monthly`/`price_yearly` dual-column shape already represents
 * both intervals without a redundant enum), a `metadata` JSON column
 * (`features` JSON already exists for this purpose), and a `display_name`
 * column (`name` already serves that role -- there is no second,
 * internal-only name anywhere in this codebase to distinguish it from).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('currency', 3)->default('IDR')->after('price_yearly');
            $table->unsignedInteger('trial_days')->nullable()->after('currency');
            $table->boolean('is_public')->default(true)->after('is_active');
            $table->boolean('is_custom')->default(false)->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['currency', 'trial_days', 'is_public', 'is_custom']);
        });
    }
};
