<?php

namespace Tests\Feature;

use App\Mail\TenantDeletionNotice;
use App\Models\Company;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantArchiveService;
use App\Services\TenantDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR 088 -- inactive organizations are archived, never deleted for inactivity;
 * an organization's own administrator can delete it, with a short cooling-off.
 */
class TenantRetentionTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->package = Package::create([
            'name' => 'Starter', 'slug' => 'starter-'.uniqid(), 'is_active' => true, 'is_public' => true,
            'price_monthly' => 189000, 'price_yearly' => 2268000, 'currency' => 'IDR',
        ]);
    }

    /** @return array{Tenant, User} */
    private function organization(string $name, int $periodEndedDaysAgo): array
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        Company::withoutGlobalScopes()->create(['name' => "{$name} Co", 'code' => strtoupper(Str::random(4)), 'tenant_id' => $tenant->id, 'is_active' => true]);
        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $this->package->id, 'status' => Subscription::STATUS_ACTIVE,
            'type' => 'subscription', 'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subDays($periodEndedDaysAgo + 30), 'ends_at' => now()->subDays($periodEndedDaysAgo),
        ]);
        $admin = User::create(['name' => "Admin {$name}", 'email' => Str::slug($name).'@pelanggan.test', 'password' => 'Rahasia-2026!',
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $tenant->id, 'is_active' => true]);

        return [$tenant, $admin];
    }

    public function test_the_administrator_deletes_their_own_organization_after_confirming_even_while_read_only(): void
    {
        [$tenant, $admin] = $this->organization('Galangan Pergi', 40);   // lapsed: read-only

        $this->actingAs($admin)->post('/subscription/organization-deletion', ['organization_name' => 'Salah', 'password' => 'Rahasia-2026!'])
            ->assertSessionHasErrors('organization_name');
        $this->actingAs($admin)->post('/subscription/organization-deletion', ['organization_name' => 'Galangan Pergi', 'password' => 'salah'])
            ->assertSessionHasErrors('organization_name');
        $this->assertNull($tenant->fresh()->deletion_scheduled_for);

        $this->actingAs($admin)->post('/subscription/organization-deletion', ['organization_name' => 'Galangan Pergi', 'password' => 'Rahasia-2026!'])
            ->assertSessionHasNoErrors();
        $scheduled = $tenant->fresh()->deletion_scheduled_for;
        $this->assertTrue($scheduled->isSameDay(now()->addDays(TenantDeletionService::CUSTOMER_HOLD_DAYS)));
        Mail::assertSent(TenantDeletionNotice::class, fn ($m) => $m->event === 'scheduled');

        $this->assertSame(['reminded' => 1, 'purged' => 0], app(TenantDeletionService::class)->processDue(now()->addDays(6)->addHours(12)));
        $this->assertSame(1, app(TenantDeletionService::class)->processDue(now()->addDays(8))['purged']);
        $this->assertNull(Tenant::find($tenant->id));
    }

    public function test_the_administrator_can_cancel_and_other_members_cannot_delete(): void
    {
        [$tenant, $admin] = $this->organization('Galangan Tetap', 3);
        $member = User::create(['name' => 'Staf', 'email' => 'staf@tetap.test', 'password' => 'Rahasia-2026!',
            'role' => User::ROLE_HSE, 'tenant_id' => $tenant->id, 'is_active' => true]);

        $this->actingAs($member)->post('/subscription/organization-deletion', ['organization_name' => 'Galangan Tetap', 'password' => 'Rahasia-2026!'])
            ->assertStatus(403);

        $this->actingAs($admin)->post('/subscription/organization-deletion', ['organization_name' => 'Galangan Tetap', 'password' => 'Rahasia-2026!']);
        $this->assertNotNull($tenant->fresh()->deletion_scheduled_for);
        $this->actingAs($admin)->delete('/subscription/organization-deletion')->assertSessionHasNoErrors();
        $this->assertNull($tenant->fresh()->deletion_scheduled_for);
        $this->assertSame(0, app(TenantDeletionService::class)->processDue(now()->addDays(30))['purged']);
        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_dormant_organizations_are_archived_never_deleted_and_a_sign_in_restores_them(): void
    {
        [$dormant, $dormantAdmin] = $this->organization('Galangan Tidur', 400);
        [$recent] = $this->organization('Galangan Baru Habis', 20);
        [$active, $activeAdmin] = $this->organization('Galangan Lama Tapi Masuk', 400);
        $activeAdmin->forceFill(['last_login_at' => now()->subDays(10)])->save();

        $this->assertSame(1, app(TenantArchiveService::class)->archiveDormant());
        $this->assertNotNull($dormant->fresh()->archived_at);
        $this->assertNull($recent->fresh()->archived_at);
        $this->assertNull($active->fresh()->archived_at);
        $this->assertSame(0, app(TenantArchiveService::class)->archiveDormant());   // idempotent

        // Everything is still there; only hidden.
        $this->assertSame(1, Company::withoutGlobalScopes()->where('tenant_id', $dormant->id)->count());

        // The next sign-in brings it back.
        $this->post('/login', ['email' => $dormantAdmin->email, 'password' => 'Rahasia-2026!']);
        $this->assertNull($dormant->fresh()->archived_at);
    }

    public function test_master_admin_lists_in_use_organizations_by_default(): void
    {
        $operator = User::create(['name' => 'Founder', 'email' => 'founder@tahadagroup.test', 'password' => 'Rahasia-2026!',
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true]);
        $this->organization('Galangan Tidur', 400);
        app(TenantArchiveService::class)->archiveDormant();
        $this->organization('Galangan Habis', 40);
        [$inUse] = $this->organization('Galangan Jalan', -20);   // period ends in 20 days

        $this->actingAs($operator)->get('/platform/tenants')->assertOk()
            ->assertInertia(fn ($p) => $p->where('filter', 'aktif')->where('counts.arsip', 1)->where('counts.tidak_aktif', 1)
                ->where('tenants', fn ($t) => collect($t)->pluck('name')->contains('Galangan Jalan') && ! collect($t)->pluck('name')->contains('Galangan Tidur')));
        $this->actingAs($operator)->get('/platform/tenants?filter=arsip')->assertOk()
            ->assertInertia(fn ($p) => $p->where('tenants', fn ($t) => collect($t)->pluck('name')->all() === ['Galangan Tidur']));
    }
}
