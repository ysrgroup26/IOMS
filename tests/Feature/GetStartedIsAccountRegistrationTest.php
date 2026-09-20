<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\TenantRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.74.2 -- GET STARTED CREATES AN ACCOUNT. NOTHING ELSE.
 *
 * ---------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 * ---------------------------------------------------------------------
 *
 * "Get Started" used to mean: hand a stranger one long form and ask for
 * their name, their email, a password, their company's legal name, its
 * full postal address, its tax numbers, a plan and a billing cycle --
 * before they had anything at all. That was forced by the old model, in
 * which no `users` row existed until a payment cleared.
 *
 * The model changed in v2.74.0 and the page did not. This pins the page.
 *
 * ---------------------------------------------------------------------
 * WHAT IT DEFENDS, AND HOW
 * ---------------------------------------------------------------------
 *
 * The failure mode is not a crash; it is a page quietly growing a field
 * again. A pricing card gets "helpfully" added to the signup screen, a
 * company-name input is put back "so we can personalise it", and the
 * product is back to asking a person for their company before they have
 * an account. Nothing would fail. So this asserts ABSENCE, which is the
 * only thing that catches it -- at two levels, because either alone is
 * escapable:
 *
 *   1. the PROPS  -- what the server sends. Catches a controller that
 *      starts feeding the catalogue to the signup page again.
 *   2. the SOURCE -- what the component can render at all. Catches
 *      fields hard-coded into the JSX with no props behind them, which
 *      is exactly how a "small addition" gets made.
 *
 * Both are needed. A props assertion passes on a form with the fields
 * written straight into the markup; a source assertion passes if some
 * other component starts being rendered.
 *
 * NOTE on why neither assertion reads the raw HTML: this app ships its
 * release notes to every page (the in-app About dialog), and those notes
 * discuss companies, plans and prices in prose. A page-wide string search
 * would match that and fail on wording rather than on behaviour.
 */
class GetStartedIsAccountRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function seedPlans(): void
    {
        $this->seed(\Database\Seeders\PackageSeeder::class);
    }

    /**
     * A component's source with its comments removed.
     *
     * The docblocks in these files EXPLAIN the commercial fields they
     * deliberately no longer have, so searching the raw file would match
     * the explanation and fail. What matters is the markup.
     */
    private function markupOf(string $page): string
    {
        $source = file_get_contents(resource_path("js/Pages/{$page}.jsx"));

        // Block comments (including JSX {/* ... */}) then line comments.
        $source = preg_replace('#/\*.*?\*/#s', '', $source);

        return preg_replace('#^\s*//.*$#m', '', $source);
    }

    /* ------------------------------------------------------------------ */
    /* The route                                                           */
    /* ------------------------------------------------------------------ */

    /** Get Started is the account form, reached by the one canonical URL. */
    public function test_get_started_opens_account_registration(): void
    {
        $this->seedPlans();

        $this->get(route('get-started'))->assertRedirect(route('register'));

        $this->get(route('register'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/Register'));
    }

    /* ------------------------------------------------------------------ */
    /* 1. The props                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * The catalogue is not merely hidden on the signup page -- it is never
     * sent. An Inertia page ships its props as JSON inside the HTML, so a
     * prop that is "not displayed" is still delivered to the browser.
     */
    public function test_the_account_form_is_sent_no_company_plan_or_payment_data(): void
    {
        $this->seedPlans();

        $this->get(route('register'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/Register')
                ->missing('plans')
                ->missing('selectedPlan')
                ->missing('billingCycle')
                ->missing('industries')
                ->missing('account')
                ->missing('order'));
    }

    /* ------------------------------------------------------------------ */
    /* 2. The source                                                       */
    /* ------------------------------------------------------------------ */

    /** The signup component cannot render a company, a plan or a price. */
    public function test_the_account_form_component_contains_no_commercial_inputs(): void
    {
        $markup = $this->markupOf('Auth/Register');

        $forbidden = [
            'company_',            // every company field is named company_*
            'billing_cycle',
            'billing_email',
            'Your company',
            'Your plan',
            'Legal company name',
            'Continue to payment',
            'annual_saving',
            'formatted',           // a rendered price
            'industries',
            'setData(\'plan\'',    // the plan chooser
        ];

        foreach ($forbidden as $fragment) {
            $this->assertStringNotContainsStringIgnoringCase(
                $fragment,
                $markup,
                "Auth/Register.jsx renders \"{$fragment}\". Get Started creates an ACCOUNT; company, "
                .'plan and payment belong to subscription setup (see docs/ADR/038).'
            );
        }
    }

    /** The four things it DOES ask for, and the consent it needs. */
    public function test_the_account_form_asks_for_exactly_the_account(): void
    {
        $markup = $this->markupOf('Auth/Register');

        foreach (['Full name', 'Your email', 'Password', 'Confirm password'] as $label) {
            $this->assertStringContainsString($label, $markup, "The account form lost its \"{$label}\" field.");
        }

        // "Your email", not "Work email": an account is a person, and does
        // not have to belong to a company yet.
        $this->assertStringNotContainsString('Work email', $markup);

        // Consent is still collected, and Google is still offered when the
        // deployment has credentials for it.
        $this->assertStringContainsString('terms', $markup);
        $this->assertStringContainsString('GoogleSignInButton', $markup);
        $this->assertStringContainsString('googleEnabled', $markup);
    }

    /* ------------------------------------------------------------------ */
    /* 3. The endpoint behind the old form                                 */
    /* ------------------------------------------------------------------ */

    /**
     * The pay-first POST is GONE, not merely unlinked.
     *
     * An endpoint no page can reach is not harmless when it creates
     * accounts and orders: it is a surface nobody looks at. Asserting the
     * route name does not resolve is what stops it being quietly restored
     * "for the old flow".
     */
    public function test_the_pay_first_registration_endpoint_no_longer_exists(): void
    {
        $this->assertFalse(
            app('router')->has('register.store'),
            'POST /get-started is gone. Orders are raised at POST /subscribe by a signed-in account.'
        );

        $this->post('/get-started', ['contact_email' => 'someone@contoh.test'])->assertStatus(405);
    }

    /** Creating an account creates a person, and no commercial record. */
    public function test_creating_an_account_creates_no_order(): void
    {
        Mail::fake();
        $this->seedPlans();

        $packagesBefore = Package::count();

        $this->post(route('register.account'), [
            'name' => 'Sri Handayani',
            'email' => 'sri@contoh.test',
            // Not a common password: the rule set includes Laravel's
            // uncompromised() check against Have I Been Pwned.
            'password' => 'Perancah2026kuat',
            'password_confirmation' => 'Perancah2026kuat',
            'terms' => true,
        ])->assertRedirect(route('register.welcome'));

        $account = User::withoutGlobalScopes()->where('email', 'sri@contoh.test')->firstOrFail();

        $this->assertSame(User::ROLE_ACCOUNT, $account->role);
        $this->assertNull($account->tenant_id);
        $this->assertNull($account->company_id);

        $this->assertSame(0, TenantRegistration::count(), 'Registering an account raised an order.');
        $this->assertSame($packagesBefore, Package::count());
    }

    /* ------------------------------------------------------------------ */
    /* 4. Where the commercial form actually lives                         */
    /* ------------------------------------------------------------------ */

    /**
     * The other side of the same coin.
     *
     * Everything asserted absent above has to exist SOMEWHERE, or this
     * file would pass just as well on a product that cannot be bought at
     * all. It lives on subscription setup, behind an account.
     */
    public function test_the_company_and_plan_fields_live_on_subscription_setup(): void
    {
        $this->seedPlans();

        $account = User::create([
            'name' => 'Rina Kusuma',
            'email' => 'rina@contoh.test',
            'password' => bcrypt('x'),
            'role' => User::ROLE_ACCOUNT,
            'tenant_id' => null,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($account)->get(route('subscribe.setup'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/GetStarted')
                ->has('plans')
                ->has('industries')
                ->has('account'));

        $markup = $this->markupOf('Public/GetStarted');

        foreach (['Your account', 'Your company', 'Your plan', 'Legal company name', 'Continue to payment'] as $term) {
            $this->assertStringContainsString($term, $markup, "Subscription setup lost \"{$term}\".");
        }
    }

    /** And a stranger cannot open it. There is no anonymous way in. */
    public function test_subscription_setup_is_not_reachable_without_an_account(): void
    {
        $this->seedPlans();

        $this->get(route('subscribe.setup'))->assertRedirect(route('login'));
        $this->post(route('subscribe.store'), [])->assertRedirect(route('login'));

        $this->assertSame(0, TenantRegistration::count());
    }
}
