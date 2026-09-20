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
 *   Account -> Set up subscription -> Order -> Payment -> Active
 *
 * The setup form is the SAME page /get-started renders; only the entry
 * point differs. That is pinned below, because "just add a second form
 * for the signed-in case" is the obvious shortcut and the two would drift.
 *
 * Three properties matter most here and all are easy to break silently:
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
            'plan' => $this->plan()->slug,
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
            ->post('/subscribe', $this->organizationPayload([
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

        $this->actingAs($user)->post('/subscribe', $this->organizationPayload());

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

        $this->actingAs($user)->post('/subscribe', $this->organizationPayload());

        $this->assertSame($subscriptionsBefore, Subscription::withoutGlobalScopes()->count());
        $this->assertNull($user->fresh()->tenant_id);
        $this->assertSame(User::ROLE_ACCOUNT, $user->fresh()->role);
    }

    /** Returning to the form updates the live order rather than orphaning it. */
    public function test_returning_to_the_form_reuses_the_existing_order(): void
    {
        $user = $this->verifiedAccount();
        $plan = $this->plan();

        $this->actingAs($user)->post('/subscribe', $this->organizationPayload());
        $this->actingAs($user)->post('/subscribe', $this->organizationPayload([
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

        $this->actingAs($user)->post('/subscribe', $this->organizationPayload());

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

    /**
     * ONE SETUP FORM, TWO ENTRY POINTS.
     *
     * The signed-in subscribe page must render the SAME Inertia component
     * the public /get-started page renders -- not a second form that
     * happens to look like it. This assertion exists because the first
     * implementation of this flow DID build a parallel four-step wizard,
     * and two forms selling one product drift: a field is added to one, a
     * price format corrected in the other, and what a customer sees
     * depends on which door they came through.
     *
     * The only difference is the `account` prop, which is what makes the
     * page state the identity instead of collecting it.
     */
    public function test_the_subscribe_page_is_the_same_form_as_get_started(): void
    {
        $user = $this->verifiedAccount();

        $this->actingAs($user)->get('/subscribe')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/GetStarted')
                ->where('account.name', 'Rina Kusuma')
                ->where('account.email', 'rina@contoh.test')
                ->where('account.email_verified', true)
                ->has('plans')
                ->has('industries'));
    }

    /**
     * The other half of the same property: the PUBLIC door opens the same
     * page with no `account`, which is what keeps the password fields and
     * the email field in the pay-first flow.
     *
     * Its own test because /get-started redirects a signed-in visitor, so
     * it cannot share a session with the one above.
     */
    public function test_the_public_door_opens_the_same_form_without_an_account(): void
    {
        $this->get('/get-started')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Public/GetStarted')->missing('account'));
    }

    /**
     * An unverified account cannot buy. The invoice and the activation
     * notice would go to an address nobody has confirmed.
     */
    public function test_an_unverified_account_cannot_reach_subscription_setup(): void
    {
        $user = $this->verifiedAccount();
        $user->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($user)->get('/subscribe')->assertRedirect(route('account.overview'));
        $this->actingAs($user)->post('/subscribe', $this->organizationPayload())
            ->assertRedirect(route('account.overview'));

        $this->assertSame(0, TenantRegistration::where('user_id', $user->id)->count());
    }

    /**
     * Registration ends on the FORK, not in either branch of it.
     *
     * "Maybe later" has to be a real option, so the redirect after sign-up
     * must not be plan selection -- and it must not be the empty account
     * area either, which reads as broken to somebody who has just arrived.
     */
    public function test_registration_ends_on_the_setup_fork(): void
    {
        Mail::fake();

        $this->post('/register', [
            'name' => 'Baru Sekali',
            'email' => 'baru@contoh.test',
            // Deliberately not a common password: the rule set includes
            // Laravel's uncompromised() check against Have I Been Pwned.
            'password' => 'Perancah2026kuat',
            'password_confirmation' => 'Perancah2026kuat',
            'terms' => true,
        ])->assertRedirect(route('register.welcome'));

        $account = User::withoutGlobalScopes()->where('email', 'baru@contoh.test')->firstOrFail();

        $this->actingAs($account)->get('/register/welcome')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/AccountCreated'));

        // The fork itself created nothing commercial.
        $this->assertNull($account->tenant_id);
        $this->assertSame(0, TenantRegistration::where('user_id', $account->id)->count());
    }
}
