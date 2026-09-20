<?php

namespace Tests\Feature;

use App\Mail\VerifyAccountEmail;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.74.0 -- AN ACCOUNT IS AN IDENTITY, AND NOTHING ELSE.
 *
 * The product decision this release exists for: registering creates a
 * person, not an organization and not a subscription. Everything below
 * pins one half of that -- what IS created, and just as importantly what
 * is NOT.
 *
 * See docs/ADR/038-account-organization-subscription.md.
 */
class AccountRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rina Kusuma',
            'email' => 'rina@contoh.test',
            // Deliberately not a common password: the rule set includes
            // Laravel's uncompromised() check against Have I Been Pwned.
            'password' => 'Perancah2026kuat',
            'password_confirmation' => 'Perancah2026kuat',
            'terms' => true,
        ], $overrides);
    }

    public function test_registering_creates_an_identity_and_nothing_else(): void
    {
        Mail::fake();

        // Counted as a DELTA: a "Default Tenant" row ships with the schema,
        // so an absolute zero here would assert something that was never
        // true rather than the thing this test is about.
        $tenantsBefore = Tenant::count();
        $companiesBefore = Company::withoutGlobalScopes()->count();

        // The fork, not the account area and not plan selection. See
        // SubscribeFlowTest::test_registration_ends_on_the_setup_fork.
        $this->post('/register', $this->payload())->assertRedirect('/register/welcome');

        $user = User::withoutGlobalScopes()->where('email', 'rina@contoh.test')->firstOrFail();

        // The identity.
        $this->assertSame('Rina Kusuma', $user->name);
        $this->assertTrue($user->hasPassword());
        $this->assertTrue($user->is_active);

        // THE THINGS THAT MUST NOT EXIST. This is the whole product
        // decision, expressed as four assertions.
        $this->assertNull($user->tenant_id, 'Registration must not create or attach a tenant.');
        $this->assertNull($user->company_id, 'Registration must not create or attach a company.');
        $this->assertSame($tenantsBefore, Tenant::count(), 'Registration must not create an organization.');
        $this->assertSame(0, Subscription::withoutGlobalScopes()->count(), 'Registration must not create a subscription.');
        $this->assertSame($companiesBefore, Company::withoutGlobalScopes()->count());
    }

    /**
     * THE KEYSTONE.
     *
     * `isPlatformAdmin()` used to be `is_null($tenant_id)`. Every account
     * created by this endpoint has a null tenant by design, so under the
     * old definition every signup would have been a Platform Super Admin
     * with cross-tenant reach. This is the assertion that stops that
     * definition from ever being restored.
     */
    public function test_a_new_account_is_not_a_platform_admin(): void
    {
        Mail::fake();

        $this->post('/register', $this->payload());

        $user = User::withoutGlobalScopes()->where('email', 'rina@contoh.test')->firstOrFail();

        $this->assertFalse(
            $user->isPlatformAdmin(),
            'A tenant-less account must NEVER be treated as the platform operator.'
        );
        $this->assertTrue($user->hasNoOrganization());
        $this->assertSame(User::ROLE_ACCOUNT, $user->role);
    }

    /** The role a fresh account holds must grant nothing at all. */
    public function test_the_account_role_grants_no_capability(): void
    {
        Mail::fake();
        $this->post('/register', $this->payload());
        $user = User::withoutGlobalScopes()->where('email', 'rina@contoh.test')->firstOrFail();

        foreach (['isSuperAdmin', 'isHse', 'isHrd', 'isManager', 'isPlatformAdmin'] as $predicate) {
            $this->assertFalse($user->{$predicate}(), "ROLE_ACCOUNT must not satisfy {$predicate}().");
        }

        foreach (['canManageIncidents', 'canManageHse', 'canCreatePtw'] as $capability) {
            $this->assertFalse($user->{$capability}(), "ROLE_ACCOUNT must not satisfy {$capability}().");
        }
    }

    public function test_a_verification_email_is_sent(): void
    {
        Mail::fake();

        $this->post('/register', $this->payload());

        Mail::assertSent(VerifyAccountEmail::class, fn ($mail) => $mail->hasTo('rina@contoh.test'));
    }

    public function test_the_new_account_is_signed_in_but_unverified(): void
    {
        Mail::fake();

        $this->post('/register', $this->payload());

        $this->assertAuthenticated();
        $this->assertFalse(auth()->user()->hasVerifiedEmail());
    }

    /**
     * The duplicate-address message must be identical whatever kind of
     * account holds it, so an unauthenticated stranger cannot use this
     * endpoint to learn which addresses are registered with IOMS.
     */
    public function test_an_existing_address_is_refused_without_revealing_what_kind_of_account_it_is(): void
    {
        Mail::fake();

        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-dup']);
        User::create([
            'name' => 'Existing', 'email' => 'taken@contoh.test', 'password' => bcrypt('x'),
            'role' => 'hse', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);
        User::create([
            'name' => 'Account only', 'email' => 'account@contoh.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_ACCOUNT, 'tenant_id' => null, 'is_active' => true,
        ]);

        $tenantUserError = $this->post('/register', $this->payload(['email' => 'taken@contoh.test']))
            ->assertSessionHasErrors('email')->getSession()->get('errors')->first('email');

        $accountUserError = $this->post('/register', $this->payload(['email' => 'account@contoh.test']))
            ->assertSessionHasErrors('email')->getSession()->get('errors')->first('email');

        $this->assertSame(
            $tenantUserError,
            $accountUserError,
            'The duplicate-email message must not distinguish a tenant user from an unsubscribed account.'
        );

        $this->assertSame(2, User::withoutGlobalScopes()->count());
    }

    public function test_terms_must_be_accepted(): void
    {
        Mail::fake();

        $this->post('/register', $this->payload(['terms' => false]))->assertSessionHasErrors('terms');

        $this->assertSame(0, User::withoutGlobalScopes()->count());
    }
}
