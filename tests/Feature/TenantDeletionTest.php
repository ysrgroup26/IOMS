<?php

namespace Tests\Feature;

use App\Mail\TenantDeletionNotice;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantRegistration;
use App\Models\User;
use App\Services\TenantDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR 087 -- a test organization is removed exactly and completely; a customer's
 * is held 90 days with notices; nobody else's row is ever touched.
 */
class TenantDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->package = Package::create([
            'name' => 'Starter', 'slug' => 'starter-'.uniqid(), 'is_active' => true, 'is_public' => true,
            'price_monthly' => 189000, 'price_yearly' => 2268000, 'currency' => 'IDR',
        ]);
        $this->operator = User::create([
            'name' => 'Founder', 'email' => 'founder@tahadagroup.test', 'password' => 'secret-pass-1',
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);
    }

    /** An organization with a company, an administrator, a project (RESTRICT FK) and an invoice. */
    private function organization(string $name, string $adminEmail, string $invoiceStatus): Tenant
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $company = Company::withoutGlobalScopes()->create(['name' => "{$name} Co", 'code' => strtoupper(Str::random(4)), 'tenant_id' => $tenant->id, 'is_active' => true]);
        User::create(['name' => "Admin {$name}", 'email' => $adminEmail, 'password' => 'secret-pass-1',
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $tenant->id, 'is_active' => true]);
        DB::table('projects')->insert(['company_id' => $company->id, 'name' => "Proyek {$name}", 'status' => 'ongoing', 'created_at' => now(), 'updated_at' => now()]);
        $sub = Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $this->package->id, 'status' => Subscription::STATUS_ACTIVE,
            'type' => 'subscription', 'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonths(2), 'ends_at' => now()->subMonth(),
        ]);
        Invoice::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'subscription_id' => $sub->id, 'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'amount' => 189000, 'currency' => 'IDR', 'status' => $invoiceStatus, 'due_date' => now()->subDays(10),
            'payment_date' => $invoiceStatus === Invoice::STATUS_PAID ? now()->subDays(20) : null,
        ]);

        return $tenant;
    }

    public function test_a_test_organization_is_purged_completely_and_its_emails_can_register_again(): void
    {
        $test = $this->organization('Galangan Uji', 'uji@galangan.test', Invoice::STATUS_ISSUED);
        $real = $this->organization('Galangan Asli', 'asli@galangan.test', Invoice::STATUS_PAID);
        $realCounts = [
            'companies' => Company::withoutGlobalScopes()->where('tenant_id', $real->id)->count(),
            'projects' => DB::table('projects')->count() - 1,
            'invoices' => Invoice::withoutGlobalScopes()->where('tenant_id', $real->id)->count(),
        ];

        $this->actingAs($this->operator)->post("/platform/tenants/{$test->id}/purge-test", ['slug' => 'salah'])
            ->assertSessionHasErrors('slug');
        $this->assertNotNull(Tenant::find($test->id));

        $this->actingAs($this->operator)->post("/platform/tenants/{$test->id}/purge-test", ['slug' => $test->slug])
            ->assertRedirect('/platform/tenants');

        $this->assertNull(Tenant::find($test->id));
        $this->assertSame(0, Company::withoutGlobalScopes()->where('tenant_id', $test->id)->count());
        $this->assertSame(0, User::withoutGlobalScopes()->where('email', 'uji@galangan.test')->count());
        $this->assertSame(0, Invoice::withoutGlobalScopes()->where('tenant_id', $test->id)->count());
        $this->assertSame(0, DB::table('projects')->where('name', 'Proyek Galangan Uji')->count());

        // The real organization is untouched.
        $this->assertNotNull(Tenant::find($real->id));
        $this->assertSame($realCounts['companies'], Company::withoutGlobalScopes()->where('tenant_id', $real->id)->count());
        $this->assertSame($realCounts['projects'], DB::table('projects')->count());
        $this->assertSame($realCounts['invoices'], Invoice::withoutGlobalScopes()->where('tenant_id', $real->id)->count());
        $this->assertSame(1, User::withoutGlobalScopes()->where('email', 'asli@galangan.test')->count());
        $this->assertNotNull(User::withoutGlobalScopes()->find($this->operator->id));

        // The address is free again.
        User::create(['name' => 'Kembali', 'email' => 'uji@galangan.test', 'password' => 'secret-pass-1', 'role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
        $this->assertTrue(ActivityLog::withoutGlobalScopes()->where('action', 'deleted')->where('description', 'like', 'Tenant uji%')->exists());
    }

    public function test_an_organization_that_ever_paid_cannot_be_purged_on_the_spot(): void
    {
        $real = $this->organization('Galangan Asli', 'asli@galangan.test', Invoice::STATUS_PAID);
        $this->assertFalse(app(TenantDeletionService::class)->canPurgeNow($real));

        $this->actingAs($this->operator)->post("/platform/tenants/{$real->id}/purge-test", ['slug' => $real->slug])
            ->assertSessionHasErrors('slug');
        $this->assertNotNull(Tenant::find($real->id));
    }

    public function test_a_customer_is_held_ninety_days_with_notices_then_purged(): void
    {
        $real = $this->organization('Galangan Asli', 'asli@galangan.test', Invoice::STATUS_PAID);
        $svc = app(TenantDeletionService::class);
        $start = now()->startOfDay()->addHours(9);

        $svc->schedule($real, 'Permintaan pelanggan menutup akun', $this->operator, $start);
        $real->refresh();
        $this->assertTrue($real->deletion_scheduled_for->isSameDay($start->copy()->addDays(90)));
        Mail::assertSent(TenantDeletionNotice::class, fn ($m) => $m->event === 'scheduled' && $m->hasTo('asli@galangan.test'));

        $this->assertSame(['reminded' => 0, 'purged' => 0], $svc->processDue($start->copy()->addDays(10)));
        $this->assertSame(['reminded' => 1, 'purged' => 0], $svc->processDue($start->copy()->addDays(61)));   // 30 days left
        $this->assertSame(['reminded' => 0, 'purged' => 0], $svc->processDue($start->copy()->addDays(62)));   // not twice
        $this->assertSame(['reminded' => 1, 'purged' => 0], $svc->processDue($start->copy()->addDays(89)));   // late run: one email for 7 and 1
        $this->assertSame(['reminded' => 0, 'purged' => 0], $svc->processDue($start->copy()->addDays(89)->addHours(2)));
        $this->assertNotNull(Tenant::find($real->id));

        $this->assertSame(['reminded' => 0, 'purged' => 1], $svc->processDue($start->copy()->addDays(91)));
        $this->assertNull(Tenant::find($real->id));
    }

    public function test_a_scheduled_deletion_can_be_cancelled_and_the_customer_is_told(): void
    {
        $real = $this->organization('Galangan Asli', 'asli@galangan.test', Invoice::STATUS_PAID);
        $this->actingAs($this->operator)->post("/platform/tenants/{$real->id}/deletion", ['reason' => 'Uji pembatalan'])->assertRedirect();
        $this->assertNotNull($real->fresh()->deletion_scheduled_for);

        $this->actingAs($this->operator)->delete("/platform/tenants/{$real->id}/deletion")->assertRedirect();
        $this->assertNull($real->fresh()->deletion_scheduled_for);
        Mail::assertSent(TenantDeletionNotice::class, fn ($m) => $m->event === 'cancelled');
        $this->assertSame(['reminded' => 0, 'purged' => 0], app(TenantDeletionService::class)->processDue(now()->addDays(120)));
        $this->assertNotNull(Tenant::find($real->id));
    }

    public function test_an_unpaid_registration_is_purged_with_its_invoice_and_account_but_a_paid_one_is_not(): void
    {
        $account = User::create(['name' => 'Calon', 'email' => 'calon@uji.test', 'password' => 'secret-pass-1', 'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => null, 'is_active' => true]);
        $reg = TenantRegistration::forceCreate([
            'reference' => 'REG-UJI-1', 'token' => Str::random(40), 'status' => TenantRegistration::STATUS_AWAITING_PAYMENT,
            'company_legal_name' => 'Calon Uji', 'password' => 'x', 'contact_name' => 'Calon', 'contact_email' => 'calon@uji.test',
            'package_id' => $this->package->id, 'billing_cycle' => 'yearly', 'amount' => 2268000, 'currency' => 'IDR', 'user_id' => $account->id,
        ]);
        $inv = Invoice::withoutGlobalScopes()->create(['tenant_id' => null, 'registration_id' => $reg->id, 'invoice_number' => 'INV-REG-1',
            'amount' => 2268000, 'currency' => 'IDR', 'status' => Invoice::STATUS_ISSUED, 'due_date' => now()->addDays(3)]);
        $reg->forceFill(['invoice_id' => $inv->id])->save();

        $paid = TenantRegistration::forceCreate([
            'reference' => 'REG-UJI-2', 'token' => Str::random(40), 'status' => TenantRegistration::STATUS_PAID, 'paid_at' => now(),
            'company_legal_name' => 'Sudah Bayar', 'password' => 'x', 'contact_name' => 'B', 'contact_email' => 'bayar@uji.test',
            'package_id' => $this->package->id, 'billing_cycle' => 'yearly', 'amount' => 2268000, 'currency' => 'IDR',
        ]);

        $this->actingAs($this->operator)->delete("/platform/registrations/{$paid->id}")->assertSessionHasErrors('registration');
        $this->assertNotNull(TenantRegistration::find($paid->id));

        $this->actingAs($this->operator)->delete("/platform/registrations/{$reg->id}")->assertRedirect();
        $this->assertNull(TenantRegistration::find($reg->id));
        $this->assertNull(Invoice::withoutGlobalScopes()->find($inv->id));
        $this->assertSame(0, User::withoutGlobalScopes()->where('email', 'calon@uji.test')->count());
        $this->assertNotNull(User::withoutGlobalScopes()->find($this->operator->id));
    }

    public function test_tenant_administrators_cannot_reach_deletion(): void
    {
        $test = $this->organization('Galangan Uji', 'uji@galangan.test', Invoice::STATUS_ISSUED);
        $admin = User::withoutGlobalScopes()->where('email', 'uji@galangan.test')->first();
        $this->actingAs($admin)->post("/platform/tenants/{$test->id}/purge-test", ['slug' => $test->slug])->assertStatus(403);
        $this->assertNotNull(Tenant::find($test->id));
    }
}
