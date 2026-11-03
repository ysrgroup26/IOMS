<?php

/*
 * staging-payment-check.php -- READ-ONLY report of the Duitku SANDBOX integration on staging
 * (ADR 085, runbook docs/PAYMENTS-DUITKU.md section 6a). Installed root-owned at
 * /usr/local/lib/ioms-staging-ops/payment-check.php and run by `ioms-staging-ops review-check`
 * as the staging account:
 *
 *     php payment-check.php /srv/ioms-staging/app
 *
 * What it proves, and how:
 *   - the configuration names Duitku, holds both credentials and points at the SANDBOX
 *     (refuses outright if DUITKU_IS_PRODUCTION is true);
 *   - for every PENDING Duitku attempt, a signed transactionStatus READ is answered -- which
 *     proves the status signature against the real sandbox (the method-list signature is
 *     proved separately by `artisan ioms:duitku-check`);
 *   - what happened to the latest onboarding orders: attempt state, callback received,
 *     invoice settled, registration provisioned, subscription active.
 *
 * It WRITES NOTHING: database work is inside a transaction that is rolled back, and the
 * provider calls are reads. It prints no secret and no full e-mail address.
 */

use App\Contracts\PaymentGatewayInterface;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\Subscription;
use App\Models\TenantRegistration;
use App\Services\Payments\DuitkuGateway;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$app = $argv[1] ?? '';
if ($app !== '/srv/ioms-staging/app' || ! is_file($app.'/vendor/autoload.php')) {
    fwrite(STDERR, "payment-check runs only against /srv/ioms-staging/app\n");
    exit(2);
}

chdir($app);
require $app.'/vendor/autoload.php';
$laravel = require $app.'/bootstrap/app.php';
$laravel->make(Kernel::class)->bootstrap();

if (config('database.connections.mysql.database') !== 'ioms_staging' || app()->environment('production')) {
    fwrite(STDERR, "refusing: not the staging database\n");
    exit(2);
}

$line = fn (string $label, string $value, string $mark = 'ok') => printf("%-4s %-26s %s\n", $mark, $label, $value);
$mask = fn (?string $email) => $email ? preg_replace('/^(.).*(@.*)$/', '$1***$2', $email) : '-';

echo "== Duitku sandbox (payment-check)\n";

if (config('payment.gateway') !== DuitkuGateway::GATEWAY) {
    $line('gateway', 'PAYMENT_GATEWAY is not duitku -- checkout shows the manual-payment notice', 'WAIT');
    exit(0);
}
if (filter_var(config('payment.duitku.is_production'), FILTER_VALIDATE_BOOL)) {
    $line('endpoint', 'DUITKU_IS_PRODUCTION=true on STAGING -- refused; set it to false', 'FAIL');
    exit(1);
}
$gateway = app(PaymentGatewayInterface::class);
if (! $gateway instanceof DuitkuGateway) {
    $line('credentials', 'a Duitku credential is missing -- the null gateway is bound', 'FAIL');
    exit(1);
}
$code = (string) config('payment.duitku.merchant_code');
$line('endpoint', 'https://sandbox.duitku.com (DUITKU_IS_PRODUCTION=false)');
$line('merchant code', substr($code, 0, 2).str_repeat('*', max(0, strlen($code) - 4)).substr($code, -2));
$line('callback URL', route('webhooks.payment.duitku'));
$line('return URL', route('register.status', ['token' => '<token>']));

$ok = true;
DB::beginTransaction();
try {
    $events = PaymentWebhookEvent::where('gateway', DuitkuGateway::GATEWAY)->latest('id')->limit(10)->get();
    $line('callbacks received', (string) PaymentWebhookEvent::where('gateway', DuitkuGateway::GATEWAY)->count(), $events->isEmpty() ? 'WAIT' : 'ok');
    foreach ($events as $e) {
        printf("       %s  result=%s  processed=%s  %s\n", $e->created_at?->format('Y-m-d H:i:s'), $e->event_type ?? '-', $e->processed ? 'yes' : 'no', $e->event_id);
    }

    $orders = TenantRegistration::with(['package', 'invoice'])->latest('id')->limit(8)->get();
    echo "== latest onboarding orders\n";
    foreach ($orders as $r) {
        $inv = $r->invoice;
        printf("  %s  %-12s  %-13s  %s  invoice=%s %s %s\n",
            $r->created_at?->format('m-d H:i'), $r->package?->slug ?? '-', $r->status, $mask($r->contact_email),
            $inv?->invoice_number ?? '-', $inv?->status ?? '-', $inv ? number_format((float) $inv->amount, 0, ',', '.') : '');

        if ($r->tenant_id) {
            $sub = Subscription::where('tenant_id', $r->tenant_id)->latest('id')->first();
            printf("       workspace: tenant #%d, subscription %s %s-%s\n", $r->tenant_id, $sub?->status ?? 'NONE',
                $sub?->starts_at?->format('Y-m-d') ?? '?', $sub?->ends_at?->format('Y-m-d') ?? '?');
            if ($inv && $inv->status === Invoice::STATUS_PAID && ! $sub) {
                $ok = false;
                $line('consistency', $r->reference.' is paid but has no subscription', 'FAIL');
            }
        }
        if (! $inv) {
            continue;
        }

        $attempts = PaymentTransaction::where('invoice_id', $inv->id)->orderBy('id')->get();
        $paid = $attempts->where('status', PaymentTransaction::STATUS_PAID)->count();
        if ($paid > 1 || ($paid === 1 && $inv->status !== Invoice::STATUS_PAID)) {
            $ok = false;
            $line('consistency', $inv->invoice_number." has $paid paid attempt(s), invoice ".$inv->status, 'FAIL');
        }
        foreach ($attempts as $t) {
            $read = '';
            if ($t->status === PaymentTransaction::STATUS_PENDING) {
                $state = 'unknown';
                // A signed read against the real sandbox. Writes nothing.
                try {
                    $state = $gateway->getPaymentStatus($t->gateway_reference);
                } catch (Throwable $e) {
                    $state = 'unknown';
                    $read = '  ('.class_basename($e).': '.mb_substr($e->getMessage(), 0, 120).')';
                }
                $read = '  provider-now='.$state.$read;
                if ($state === 'unknown') {
                    $ok = false;
                    $read .= ' (status read REFUSED: check the signature/merchant code; laravel.log has the response)';
                }
            }
            printf("       attempt %s  %-7s method=%s result=%s callback=%s paid=%s%s\n",
                $t->gateway_reference, $t->status, $t->payment_method ?? '-', $t->result_code ?? '-',
                $t->callback_received_at?->format('H:i:s') ?? '-', $t->paid_at?->format('H:i:s') ?? '-', $read);
        }
    }
} finally {
    DB::rollBack();
}

exit($ok ? 0 : 1);
