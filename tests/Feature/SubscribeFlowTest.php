<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Subscription;
use App\Models\TenantRegistration;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.74.0 -- AN EXISTING ACCOUNT ACQUIRES AN ORGANIZATION.
 *
 *   Account -> Choose Plan -> Organization -> Order -> Payment -> Active
 *
 * Two properties matter most here and both are easy to break silently:
 *
 *   1. THE FLOW NEVER RE-ASKS FOR IDENTITY. The order takes its contact
 *      name and email from the signed-in account, not from the request --
 *      otherwise a form field could raise an order in someone else's name.
 *
 *   2. PROVISIONING ATTACHES, IT DOES NOT DUPLICATE. The account that
 *      paid becomes Super Admin of the tenant it paid for. Creating a
 *      second user would duplicate the identity and, because
 *      `users.email` is unique, fail outright.
 */
class SubscribeFlowTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedAccount(): User
    {
        return User::create([
            'name' => 'Rina Kusuma',
            'email' => 'rina@contoh.test',
            'password' => bcrypt('x'),
            'role' => User::ROLE_ACCOUNT,
            'tenant_id' => null,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function plan(): Package
    {
        return Package::query()->active()->public()->firstOrFail();
    }

    private function organizationPayload(array $overrides = []): array
    {
        return array_merge([
            'company_legal_name' => 'PT Contoh Industri Nusantara',
            'company_display_name' => 'Contoh Industri',
            'company_address' => 'Jl. Bawal Kav. 12',
            'company_city' => 'Batam',
            'company_province' => 'Kepulauan Riau',
            'billing_cycle' => 'monthly',
            'terms' => true,
        ], $overrides);
    }

    public function test_the_order_takes_its_identity_from_the_account_not_the_request(): void
    {
        $user = $this->verifiedAccount();
        $plan = $this->plan();

        $this->actingAs($user)
            ->post("/subscribe/{$plan->slug}/organization", $this->organizationPayload([
                // A hostile form could try to raise an order against
                // somebody else. These keys are not in the rules and must
                // be ignored entirely.
                'contact_name' => 'Someone Else',
                'contact_email' => 'attacker@evil.test',
            ]))
            ->assertRedirect();

        $order = TenantRegistration::latest('id')->firstOrFail();

        $this->assertSame($user->id, $order->user_id);
        $this->assertSame('Rina Kusuma', $order->contact_name);
        $this->assertSame('rina@contoh.test', $order->contact_email);
        $this->assertNull($order->password, 'An account-raised order carries no password; the credential is on the user.');
    }

    /**
     * The order is already verified, because the ACCOUNT is. Making
     * somebody confirm the same address twice is asking them to prove a
     * thing they have proved.
     */
    public function test_an_account_raised_order_skips_email_verification(): void
    {
        $user = $this->verifiedAccount();
        $plan = $this->plan();

        $this->actingAs($user)->post("/subscribe/{$plan->slug}/organization", $this->organizationPayload());

        $order = TenantRegistration::latest('id')->firstOrFail();

        $this->assertSame(TenantRegistration::STATUS_VERIFIED, $order->status);
        $this->assertNotNull($order->email_verified_at);
    }

    /** Nothing commercial exists until a verified payment provisions it. */
    public function test_raising_an_order_provisions_nothing(): void
    {
        $user = $this->verifiedAccount();
        $plan = $this->plan();
        $subscriptionsBefore = Subscription::withoutGlobalScopes()->count();

        $this->actingAs($user)->post("/subscribe/{$plan->slug}/organization", $this->organizationPayload());

        $this->assertSame($subscriptionsBefore, Subscription::withoutGlobalScopes()->count());
        $this->assertNull($user->fresh()->tenant_id);
        $this->assertSame(User::ROLE_ACCOUNT, $user->fresh()->role);
    }

    /** Returning to the form updates the live order rather than orphaning it. */
    public function test_returning_to_the_form_reuses_the_existing_order(): void
    {
        $user = $this->verifiedAccount();
        $plan = $this->plan();

        $this->actingAs($user)->post("/subscribe/{$plan->slug}/organization", $this->organizationPayload());
        $this->actingAs($user)->post("/subscribe/{$plan->slug}/organization", $this->organizationPayload([
            'company_display_name' => 'Contoh Industri Revised',
        ]));

        $this->assertSame(1, TenantRegistration::where('user_id', $user->id)->count());
        $this->assertSame('Contoh Industri Revised', TenantRegistration::latest('id')->first()->company_display_name);
    }

    /**
     * THE ATTACH PATH -- the single most important assertion in this file.
     *
     * Driven through TenantProvisioningService, which is the same entry
     * point the verified webhook calls. A second user here would be a
     * duplicated identity.
     */
    public function test_provisioning_attaches_the_existing_account_instead_of_creating_a_second_user(): void
    {
        Mail::fake();

        $user = $this->verifiedAccount();
        $plan = $this->plan();

        $this->actingAs($user)->post("/subscribe/{$plan->slug}/organization", $this->organizationPayload());

        $order = TenantRegistration::latest('id')->firstOrFail();
        $order->update(['status' => TenantRegistration::STATUS_PAID, 'paid_at' => now()]);

        $usersBefore = User::withoutGlobalScopes()->count();

        $tenant = app(TenantProvisioningService::class)->activate($order->fresh());

        $this->assertNotNull($tenant);
        $this->assertSame(
            $usersBefore,
            User::withoutGlobalScopes()->count(),
            'Provisioning an account-raised order must ATTACH the existing user, never create a second one.'
        );

        $user->refresh();
        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertSame(User::ROLE_SUPER_ADMIN, $user->role);
        $this->assertNotNull($user->company_id);

        // The keystone still holds after promotion.
        $this->assertFalse($user->isPlatformAdmin());
        $this->assertFalse($user->hasNoOrganization());
        $this->assertSame('dashboard', $user->landingRouteName());

        // And the commercial record is real.
        $subscription = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
    }

    /**
     * The LEGACY public flow -- no account until payment -- must keep
     * working unchanged. `user_id` is what distinguishes the two paths, so
     * a null one still creates the user.
     */
    public function test_the_legacy_public_flow_still_creates_its_own_user(): void
    {
        Mail::fake();

        $plan = $this->plan();

        $order = TenantRegistration::create([
            'token' => TenantRegistration::newToken(),
            'reference' => TenantRegistration::newReference(),
            'status' => TenantRegistration::STATUS_PAID,
            'contact_name' => 'Legacy Buyer',
            'contact_email' => 'legacy@contoh.test',
            'password' => bcrypt('secret'),
            'company_legal_name' => 'PT Legacy',
            'package_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'amount' => 100000,
            'currency' => 'IDR',
            'email_verified_at' => now(),
            'paid_at' => now(),
            // Deliberately null -- this is the distinguishing fact.
            'user_id' => null,
        ]);

        $usersBefore = User::withoutGlobalScopes()->count();

        $tenant = app(TenantProvisioningService::class)->activate($order);

        $this->assertNotNull($tenant);
        $this->assertSame($usersBefore + 1, User::withoutGlobalScopes()->count());

        $admin = User::withoutGlobalScopes()->where('email', 'legacy@contoh.test')->firstOrFail();
        $this->assertSame(User::ROLE_SUPER_ADMIN, $admin->role);
        $this->assertSame($tenant->id, $admin->tenant_id);
        $this->assertNotNull($admin->email_verified_at, 'A provisioned tenant admin verified their address before paying.');
    }

    /** An order belongs to the account that raised it, and to nobody else. */
    public function test_another_account_cannot_open_someone_elses_order(): void
    {
        $owner = $this->verifiedAccount();
        $plan = $this->plan();

        $this->actingAs($owner)->post("/subscribe/{$plan->slug}/organization", $this->organizationPayload());
        $order = TenantRegistration::latest('id')->firstOrFail();

        $intruder = User::create([
            'name' => 'Intruder', 'email' => 'intruder@contoh.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_ACCOUNT, 'tenant_id' => null, 'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // 404 rather than 403 -- a 403 would confirm the order exists.
        $this->actingAs($intruder)->get("/subscribe/order/{$order->token}")->assertNotFound();
    }
}
