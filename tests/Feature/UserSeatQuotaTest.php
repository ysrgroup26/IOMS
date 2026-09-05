<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.38.0 (Master Audit, P1) -- seat quota enforcement.
 *
 * CONFIRMED DEFECT these tests pin: the commercial rule is
 * `max_ptw_users <= max_users`, and the PTW half was enforced with a
 * row-locking transaction. The PARENT half was not enforced anywhere:
 * `Subscription::seatLimit()` was only ever read for display, so any
 * tenant could create unlimited accounts regardless of their plan.
 *
 * ON CONCURRENCY, HONESTLY: the fix uses `lockForUpdate()` inside a
 * transaction, which relies on InnoDB row/gap locking. This suite runs on
 * SQLite, which serialises writes globally -- so a "concurrency test"
 * here would pass whether or not the lock existed, and would be false
 * assurance rather than evidence. These tests therefore pin the
 * INVARIANT (the gate rejects at quota, allows below it, and treats null
 * as unlimited), which is what actually regresses when someone deletes
 * the check. The locking behaviour itself is asserted by code review and
 * documented as unproven in this environment.
 */
class UserSeatQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithSeats(?int $maxUsers, ?int $seatOverride = null): Tenant
    {
        $tenant = Tenant::create(['name' => 'T'.uniqid(), 'slug' => 't'.uniqid()]);

        $package = Package::create([
            'name' => 'Plan', 'slug' => 'plan-'.uniqid(),
            'max_users' => $maxUsers, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'status' => 'active',
            'seat_limit' => $seatOverride,
        ]);

        return $tenant->fresh();
    }

    private function addUsers(Tenant $tenant, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            User::create([
                'name' => "U$i", 'email' => uniqid().'@example.test',
                'password' => bcrypt('x'), 'role' => 'hrd',
                'tenant_id' => $tenant->id, 'is_active' => true,
            ]);
        }
    }

    public function test_creation_is_blocked_once_the_seat_limit_is_reached(): void
    {
        $tenant = $this->tenantWithSeats(3);
        $this->addUsers($tenant, 3);

        $this->assertFalse(app(EntitlementService::class)->canCreateUser($tenant->fresh()));
    }

    public function test_creation_is_allowed_below_the_seat_limit(): void
    {
        $tenant = $this->tenantWithSeats(3);
        $this->addUsers($tenant, 2);

        $this->assertTrue(app(EntitlementService::class)->canCreateUser($tenant->fresh()));
    }

    /** Null max_users means unlimited, matching every other quota in EntitlementService. */
    public function test_null_seat_limit_means_unlimited(): void
    {
        $tenant = $this->tenantWithSeats(null);
        $this->addUsers($tenant, 25);

        $this->assertTrue(app(EntitlementService::class)->canCreateUser($tenant->fresh()));
    }

    /**
     * A per-tenant `subscriptions.seat_limit` override must win over the
     * package default -- reading `package->max_users` directly would
     * silently ignore a Platform Admin's override.
     */
    public function test_subscription_seat_override_takes_precedence_over_the_package(): void
    {
        $tenant = $this->tenantWithSeats(100, seatOverride: 2);
        $this->addUsers($tenant, 2);

        $service = app(EntitlementService::class);

        $this->assertSame(2, $service->userSeatLimit($tenant->fresh()));
        $this->assertFalse($service->canCreateUser($tenant->fresh()));
    }

    /** One tenant's accounts must never consume another tenant's seats. */
    public function test_seat_usage_is_counted_per_tenant(): void
    {
        $tenantA = $this->tenantWithSeats(3);
        $tenantB = $this->tenantWithSeats(3);

        $this->addUsers($tenantA, 3);

        $service = app(EntitlementService::class);

        $this->assertFalse($service->canCreateUser($tenantA->fresh()));
        $this->assertTrue($service->canCreateUser($tenantB->fresh()), "Tenant A's users must not consume Tenant B's seats.");
        $this->assertSame(0, $service->usersUsedCount($tenantB->fresh()));
    }
}
