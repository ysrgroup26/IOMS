<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.11.0 (SaaS Finalization Pass). EXTENDS the existing Milestone 2
     * `subscriptions`/`packages` tables rather than creating a parallel
     * "License" system -- `Subscription` already IS the commercial-access
     * record per Tenant (see its own doc comment: "one row per Tenant
     * subscription period"). What it was missing:
     *
     * - `type`: trial / subscription / lifetime. Previously only
     *   `status` (trial/active/cancelled/expired) existed, which
     *   conflates TWO different questions -- "what kind of commercial
     *   arrangement is this" (type) vs. "is it currently usable right
     *   now" (status). A lifetime record's status is simply always
     *   'active' with no `ends_at` -- see Subscription::isLifetime().
     * - `seat_limit`: per-subscription override of `packages.max_users`
     *   (nullable -- null means "use the package's own limit", never a
     *   second source of truth that can silently disagree with it).
     * - `license_key`: opaque per-subscription reference string (NOT a
     *   secret/signing key -- just a human-shareable identifier a
     *   support conversation can reference), nullable, unique when set.
     * - `billing_reference`, `notes`, `created_by`: attribution/free-text
     *   fields explicitly asked for, additive, all nullable.
     *
     * `invoices`: genuinely new -- no billing/invoice concept existed
     * anywhere in this codebase before this migration (confirmed via
     * search, not assumed). One row per billing document, tenant-owned,
     * optionally linked to the subscription it bills for (nullable --
     * an invoice can outlive the subscription row it was raised against,
     * e.g. after a plan change creates a new Subscription row per the
     * existing history-table convention). No payment gateway integration
     * exists yet -- `payment_reference`/`payment_method` are free-text,
     * filled in manually by a Platform Admin recording a payment that
     * happened outside this system (bank transfer, etc.), never a
     * fabricated automatic confirmation.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'type')) {
                $table->string('type')->default('subscription')->after('package_id');
            }
            if (! Schema::hasColumn('subscriptions', 'seat_limit')) {
                $table->unsignedInteger('seat_limit')->nullable()->after('billing_cycle');
            }
            if (! Schema::hasColumn('subscriptions', 'license_key')) {
                $table->string('license_key')->nullable()->unique()->after('seat_limit');
            }
            if (! Schema::hasColumn('subscriptions', 'billing_reference')) {
                $table->string('billing_reference')->nullable()->after('license_key');
            }
            if (! Schema::hasColumn('subscriptions', 'notes')) {
                $table->text('notes')->nullable()->after('cancelled_at');
            }
            if (! Schema::hasColumn('subscriptions', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            }
        });

        Schema::createIfMissing('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('IDR');
            $table->string('status')->default('draft'); // draft, issued, paid, overdue, void
            $table->date('due_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('payment_method')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');

        Schema::table('subscriptions', function (Blueprint $table) {
            foreach (['type', 'seat_limit', 'license_key', 'billing_reference', 'notes'] as $column) {
                if (Schema::hasColumn('subscriptions', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('subscriptions', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
        });
    }
};
