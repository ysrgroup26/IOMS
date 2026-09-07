<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.55.0 -- store the provider's checkout token alongside its redirect URL.
 *
 * IOMS now presents its own order summary before payment and opens the
 * provider's interface over that page, rather than sending the customer
 * straight to another site. That needs the token to survive the redirect
 * from the POST that creates the checkout to the GET that renders it, and
 * it needs to survive a page refresh -- so it belongs on the transaction
 * row beside `redirect_url`, not in the session.
 *
 * NULLABLE AND CARRYING NO AUTHORITY. A provider that only offers a hosted
 * redirect leaves it null and everything still works from `redirect_url`.
 * Holding the token proves a checkout was CREATED, never that anything was
 * paid: settlement is decided solely by a signed webhook (see
 * PaymentWebhookController). Nothing reads this column to decide state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('checkout_token')->nullable()->after('redirect_url');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn('checkout_token');
        });
    }
};
