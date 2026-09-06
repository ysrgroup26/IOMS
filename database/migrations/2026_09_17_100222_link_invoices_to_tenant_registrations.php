<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.51.0 -- lets an invoice exist BEFORE its tenant does.
 *
 * The onboarding order is deliberate: a prospect pays, and only a
 * server-verified payment creates the tenant. That means the invoice the
 * prospect pays cannot yet have a tenant_id, which the existing schema
 * required.
 *
 * The alternative -- creating the Tenant first so the invoice has
 * something to point at -- was rejected: a Tenant row is a live isolation
 * boundary, and an unpaid shell inside it is exactly the "pending tenant
 * with application access" this phase forbids.
 *
 * So `tenant_id` becomes nullable and `registration_id` is added. Every
 * existing invoice keeps its tenant_id, every existing query still works,
 * and provisioning back-fills tenant_id the moment the tenant is created,
 * so an invoice is never left orphaned once its tenant exists. This reuses
 * the existing Invoice / PaymentTransaction / PaymentWebhookEvent
 * architecture rather than introducing a second billing path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'registration_id')) {
                $table->foreignId('registration_id')->nullable()->after('tenant_id')
                    ->constrained('tenant_registrations')->nullOnDelete();
            }
        });

        // Drop the FK before relaxing nullability, then restore it -- MySQL
        // will not reliably MODIFY a column that an active constraint
        // references.
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'registration_id')) {
                $table->dropConstrainedForeignId('registration_id');
            }
        });

        // Only tighten tenant_id back to NOT NULL if nothing would be
        // orphaned by it -- a rollback must never destroy or invalidate a
        // real invoice row.
        if (\Illuminate\Support\Facades\DB::table('invoices')->whereNull('tenant_id')->doesntExist()) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
            });
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable(false)->change();
            });
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
        }
    }
};
