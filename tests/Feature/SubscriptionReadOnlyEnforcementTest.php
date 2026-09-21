<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\ManHourLog;
use App\Models\Package;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * v2.77.0 -- LAPSED MEANS READ-ONLY, FOR EVERY WRITE, AND RENEWAL UNDOES IT.
 *
 *   expires → grace → lapsed → data remains → writes blocked
 *           → customer renews → payment verified → access restored
 *
 * SubscriptionLifecycleTest proves the state machine and that ONE write is
 * refused. This file proves the property the customer relies on: that no
 * state-changing route in the product lets a lapsed tenant through, that
 * the real records survive, and that the way back works end to end
 * through the same signed webhook production uses.
 */
class SubscriptionReadOnlyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Route-name prefixes that must stay writable while lapsed, mirroring
     * EnforceSubscriptionWriteAccess::WRITABLE_PREFIXES, plus the payment
     * webhook (no user; authenticated by signature, not session).
     */
    private const MAY_WRITE = [
        'subscription.', 'logout', 'login', 'password.', 'notifications.',
        'account.', 'verification.', 'webhooks.',
    ];

    private Tenant $tenant;
    private User $admin;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config(['saas.enforce_entitlement' => true]);

        $package = Package::create([
            'name' => 'Business', 'slug' => 'business-'.uniqid(), 'is_active' => true, 'is_public' => true,
            'price_monthly' => 1000000, 'price_yearly' => 10000000, 'currency' => 'IDR',
        ]);

        $this->tenant = Tenant::create(['name' => 'Yard', 'slug' => 'yard-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Co', 'code' => 'YRD', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            // Ended 40 days ago: past the 14-day grace, so LAPSED.
            'starts_at' => now()->subMonths(3), 'ends_at' => now()->subDays(40),
            'agreed_price_monthly' => 1000000, 'agreed_price_yearly' => 10000000, 'agreed_currency' => 'IDR',
        ]);

        // Every workspace, so what is being tested is the LAPSE and not a
        // plan that happens not to include a module.
        $this->tenant->workspaces()->sync(Workspace::pluck('id'));

        app(CurrentTenant::class)->set($this->tenant);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@yard.test', 'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);
    }

    private function realRecords(): array
    {
        $department = Department::create(['name' => 'Produksi', 'company_id' => $this->company->id]);
        $employee = Employee::create([
            'employee_id' => 'E-001', 'full_name' => 'Budi', 'company_id' => $this->company->id,
            'department_id' => $department->id, 'status' => 'active',
        ]);
        $log = ManHourLog::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id,
            'work_date' => now()->subDays(50)->toDateString(), 'regular_hours' => 8, 'overtime_hours' => 1,
        ]);

        return [$department, $employee, $log];
    }

    /** Row counts of every table: a write that got through changes one. */
    private function snapshot(): array
    {
        $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
            ->pluck('name')
            // Session and cache bookkeeping move on any request.
            ->reject(fn ($t) => in_array($t, ['sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs'], true));

        return $tables->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    /* ------------------------------------------------------------------ */
    /* 1. Every write route                                                */
    /* ------------------------------------------------------------------ */

    /**
     * THE SWEEP. Every POST / PUT / PATCH / DELETE route in the product,
     * hit as a lapsed tenant's Super Admin.
     *
     * Accepted outcomes: 403 (the write guard) or 404 (route-model binding
     * found nothing to act on -- also no write). Anything else, and any
     * change to any table, is a route that escaped read-only.
     */
    public function test_no_state_changing_route_escapes_read_only(): void
    {
        $this->realRecords();
        $before = $this->snapshot();

        $escaped = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $method = collect($route->methods())->first(fn ($m) => in_array($m, ['POST', 'PUT', 'PATCH', 'DELETE'], true));
            $name = $route->getName() ?? '';

            if (! $method || collect(self::MAY_WRITE)->contains(fn ($p) => $name === rtrim($p, '.') || str_starts_with($name, $p))) {
                continue;
            }

            // Placeholders get an id that exists nowhere.
            $uri = preg_replace('/\{[^}]+\}/', '999999', $route->uri());
            $status = $this->actingAs($this->admin)->call($method, '/'.ltrim($uri, '/'))->getStatusCode();
            $checked++;

            if (! in_array($status, [403, 404], true)) {
                $escaped[] = "{$method} /{$route->uri()} ({$name}) → {$status}";
            }
        }

        $this->assertGreaterThan(200, $checked, 'The sweep did not see the product\'s write routes.');
        $this->assertSame([], $escaped, "Write routes that did not refuse a lapsed tenant:\n".implode("\n", $escaped));
        $this->assertSame($before, $this->snapshot(), 'A lapsed tenant changed data.');
    }

    /* ------------------------------------------------------------------ */
    /* 2. Representative actions on REAL records                           */
    /* ------------------------------------------------------------------ */

    /** Create, edit, delete and a real man-hour entry, against records that exist. */
    public function test_create_edit_and_delete_on_real_records_are_refused(): void
    {
        [, $employee, $log] = $this->realRecords();
        $before = $this->snapshot();

        $this->actingAs($this->admin)->post(route('man-hour.store'), [
            'employee_id' => $employee->id, 'work_date' => now()->toDateString(),
            'regular_hours' => 8, 'overtime_hours' => 0,
        ])->assertForbidden();

        $this->actingAs($this->admin)->delete(route('man-hour.destroy', $log))->assertForbidden();

        $this->actingAs($this->admin)->put(route('employees.update', $employee), [
            'full_name' => 'Renamed',
        ])->assertForbidden();

        $this->actingAs($this->admin)->delete(route('employees.destroy', $employee))->assertForbidden();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame('Budi', $employee->fresh()->full_name);
    }

    /** The refusal explains itself: what is paused, what is not, where to go. */
    public function test_the_refusal_explains_what_happened_and_how_to_recover(): void
    {
        [, $employee] = $this->realRecords();

        $response = $this->actingAs($this->admin)->delete(route('employees.destroy', $employee));

        $response->assertForbidden();
        $this->assertStringContainsString('Billing', $response->getContent());
    }

    /** Historical data is never withdrawn because an invoice is late. */
    public function test_every_record_stays_readable_while_lapsed(): void
    {
        [, $employee, $log] = $this->realRecords();

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
        $this->actingAs($this->admin)->get(route('employees.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('employees.show', $employee))->assertOk();

        $summary = $this->actingAs($this->admin)
            ->get(route('man-hour.index', ['from' => now()->subDays(60)->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()
            ->viewData('page')['props']['summary'];

        $this->assertEquals(9.0, $summary['total_hours']);
        $this->assertSame((float) 9, (float) $log->fresh()->total_hours);
    }

    /** A person's own account is not tenant data, and stays theirs to manage. */
    public function test_a_lapsed_user_can_still_change_their_own_password(): void
    {
        $this->actingAs($this->admin)->put(route('account.password.update'), [
            'current_password' => 'secret-pass-1',
            'password' => 'Perancah2026kuat',
            'password_confirmation' => 'Perancah2026kuat',
        ])->assertStatus(302)->assertSessionHasNoErrors();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Perancah2026kuat', $this->admin->fresh()->password));
    }

    /* ------------------------------------------------------------------ */
    /* 3. The way back                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * THE WHOLE RECOVERY, through the real routes:
     *
     *   lapsed → Renew (issues an invoice on the SAME subscription)
     *          → signed webhook settles it
     *          → the SAME subscription row is extended
     *          → writes work again, every record intact
     *
     * No second tenant, company, subscription or account is created at
     * any point. That is asserted, not assumed.
     */
    public function test_renewing_restores_write_access_on_the_same_tenant_with_data_intact(): void
    {
        Mail::fake();
        [, $employee, $log] = $this->realRecords();

        $counts = fn () => [
            'tenants' => Tenant::count(),
            'companies' => Company::withoutGlobalScopes()->count(),
            'subscriptions' => Subscription::withoutGlobalScopes()->count(),
            'users' => User::withoutGlobalScopes()->count(),
        ];
        $identity = $counts();
        $subscriptionId = $this->tenant->subscription->id;

        // 1. Lapsed: refused.
        $this->actingAs($this->admin)->delete(route('man-hour.destroy', $log))->assertForbidden();

        // 2. Renew: allowed while lapsed, and it raises an invoice.
        $this->actingAs($this->admin)->post(route('subscription.renew'))->assertRedirect();
        $invoice = Invoice::where('tenant_id', $this->tenant->id)->latest('id')->firstOrFail();
        $this->assertSame('renewal', $invoice->purpose);

        // 3. The provider's SIGNED notification settles it. Nothing the
        //    browser does can -- this is the production path.
        $this->settle($invoice)->assertOk();

        // 4. Same subscription row, now active and writable.
        $subscription = Subscription::withoutGlobalScopes()->findOrFail($subscriptionId);
        $this->assertSame(Subscription::LIFECYCLE_ACTIVE, $subscription->lifecycleState());
        $this->assertTrue($subscription->ends_at->isFuture());

        // A fresh user, as the next real request would load: the instance
        // held by this test still carries the tenant's pre-payment
        // subscription in memory.
        $response = $this->actingAs($this->admin->fresh())->post(route('man-hour.store'), [
            'employee_id' => $employee->id, 'work_date' => now()->toDateString(),
            'regular_hours' => 8, 'overtime_hours' => 0,
        ]);
        $this->assertNotSame(403, $response->getStatusCode(), 'Still read-only after a verified payment.');
        $response->assertRedirect()->assertSessionHasNoErrors();

        // 5. Nothing duplicated, nothing lost.
        $this->assertSame($identity, $counts(), 'Renewal created a second tenant, company, subscription or account.');
        $this->assertNotNull($log->fresh(), 'The record from before the lapse is gone.');
        $this->assertSame(2, ManHourLog::count());
    }

    /** An unsigned "payment" restores nothing. */
    public function test_an_unverified_payment_notification_restores_nothing(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)->post(route('subscription.renew'));
        $invoice = Invoice::where('tenant_id', $this->tenant->id)->latest('id')->firstOrFail();

        config(['payment.gateway' => 'midtrans', 'payment.midtrans.server_key' => 'test-server-key']);
        $this->postJson(route('webhooks.payment.midtrans'), [
            'order_id' => 'INV'.$invoice->id.'-1', 'status_code' => '200',
            'gross_amount' => number_format((float) $invoice->amount, 2, '.', ''),
            'transaction_status' => 'settlement', 'signature_key' => 'forged',
        ]);

        $this->assertSame(Subscription::LIFECYCLE_LAPSED, $this->tenant->fresh()->subscription->lifecycleState());
    }

    /** A correctly-signed Midtrans settlement, exactly as SubscriptionLifecycleTest sends it. */
    private function settle(Invoice $invoice): \Illuminate\Testing\TestResponse
    {
        $serverKey = 'test-server-key';
        config([
            'payment.gateway' => 'midtrans',
            'payment.midtrans.server_key' => $serverKey,
            'payment.midtrans.client_key' => 'test-client-key',
        ]);

        $orderId = 'INV'.$invoice->id.'-20260101120000';
        $gross = number_format((float) $invoice->amount, 2, '.', '');

        PaymentTransaction::firstOrCreate(['gateway_reference' => $orderId], [
            'invoice_id' => $invoice->id, 'gateway' => 'midtrans', 'status' => 'pending',
            'amount' => $invoice->amount, 'currency' => $invoice->currency,
        ]);

        $payload = [
            'order_id' => $orderId, 'status_code' => '200', 'gross_amount' => $gross,
            'transaction_status' => 'settlement', 'transaction_id' => 'txn-renew',
        ];
        $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$serverKey);

        // The provider calls server-to-server with no session, so there is
        // no signed-in user -- which is also why the write guard, which
        // only acts on a signed-in tenant user, never stands in its way.
        // Drop the test's own session to send it the way production does.
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->flushSession();

        return $this->postJson(route('webhooks.payment.midtrans'), $payload);
    }
}
