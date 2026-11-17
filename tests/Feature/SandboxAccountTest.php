<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Models\Company;
use App\Models\Department;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoTenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * ADR 089 -- the Sandbox is opened from an IOMS account with a confirmed address,
 * the visit is recorded for follow-up, and Master Admin can create the Sandbox
 * on a deployment that never seeded it.
 */
class SandboxAccountTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::create(['name' => 'Founder', 'email' => 'founder@tahadagroup.test', 'password' => 'Rahasia-2026!',
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true]);
    }

    private function prospect(bool $verified): User
    {
        $u = User::create(['name' => 'Calon', 'email' => ($verified ? 'calon' : 'belum').'@galangan.test', 'password' => 'Rahasia-2026!',
            'role' => User::ROLE_ACCOUNT, 'tenant_id' => null, 'is_active' => true]);
        if ($verified) {
            $u->forceFill(['email_verified_at' => now()])->save();
        }

        return $u;
    }

    public function test_master_admin_creates_the_sandbox_even_when_a_customer_already_uses_hse(): void
    {
        // A real customer already holds the platform-unique department code "HSE".
        $customer = Tenant::create(['name' => 'Galangan Asli', 'slug' => 'galangan-asli', 'status' => Tenant::STATUS_ACTIVE]);
        $co = Company::withoutGlobalScopes()->create(['name' => 'Galangan Asli Co', 'code' => 'GAC', 'tenant_id' => $customer->id, 'is_active' => true]);
        Department::withoutGlobalScopes()->create(['company_id' => $co->id, 'code' => 'HSE', 'name' => 'Health, Safety & Environment', 'is_active' => true]);

        $this->actingAs($this->operator())->post('/platform/sandbox/activate')->assertSessionHasNoErrors()->assertRedirect();
        $demo = Tenant::where('slug', config('ioms.sandbox.tenant_slug'))->first();
        $this->assertTrue((bool) $demo?->is_demo);
        $this->assertTrue(User::withoutGlobalScopes()->where('email', config('ioms.sandbox.user_email'))->exists());
        $this->assertSame('HSE', Department::withoutGlobalScopes()->where('company_id', $co->id)->value('code'));   // customer untouched

        // Pressing it again changes nothing it should not.
        $this->actingAs($this->operator2())->post('/platform/sandbox/activate')->assertSessionHasNoErrors();
        $this->assertSame(1, Tenant::where('slug', config('ioms.sandbox.tenant_slug'))->count());
    }

    private function operator2(): User
    {
        return User::withoutGlobalScopes()->where('role', User::ROLE_PLATFORM_ADMIN)->first();
    }

    public function test_a_guest_is_sent_to_create_an_account_and_an_unverified_account_must_confirm_first(): void
    {
        app(DemoTenantSeeder::class)->run();

        $this->post('/sandbox')->assertRedirect('/register');
        $this->assertGuest();

        $unverified = $this->prospect(false);
        $this->actingAs($unverified)->post('/sandbox')->assertSessionHasErrors('sandbox');
        $this->assertSame(0, (int) $unverified->fresh()->sandbox_visits);
    }

    public function test_a_verified_account_enters_and_the_visit_is_recorded(): void
    {
        app(DemoTenantSeeder::class)->run();
        $prospect = $this->prospect(true);

        $this->actingAs($prospect)->post('/sandbox')->assertRedirect(route('dashboard'));
        $this->assertSame(config('ioms.sandbox.user_email'), Auth::user()->email);
        $fresh = $prospect->fresh();
        $this->assertSame(1, (int) $fresh->sandbox_visits);
        $this->assertNotNull($fresh->sandbox_last_entered_at);
    }

    public function test_the_page_tells_a_visitor_where_they_stand(): void
    {
        app(DemoTenantSeeder::class)->run();
        $this->get('/sandbox')->assertInertia(fn ($p) => $p->where('available', true)->where('visitor.signed_in', false));
        $this->actingAs($this->prospect(false))->get('/sandbox')
            ->assertInertia(fn ($p) => $p->where('visitor.signed_in', true)->where('visitor.verified', false));
    }

    public function test_prospects_are_listed_for_follow_up_without_staff(): void
    {
        $operator = $this->operator();
        $prospect = $this->prospect(true);
        $prospect->forceFill(['sandbox_visits' => 2, 'sandbox_last_entered_at' => now()])->save();
        $workforce = User::withoutGlobalScopes()->create(['name' => 'TICorp AI Workforce', 'email' => User::AGENT_WORKFORCE_EMAIL,
            'password' => 'x-'.uniqid(), 'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true]);
        $plain = $workforce->createToken('agent: uji', [AuthenticateAgentToken::READ, 'agent:growth'])->plainTextToken;

        $res = $this->withToken($plain)->getJson('/api/agent/v1/akun-calon')->assertOk();
        $emails = collect($res->json('data.accounts'))->pluck('email');
        $this->assertTrue($emails->contains('calon@galangan.test'));
        $this->assertFalse($emails->contains($operator->email));
        $this->assertFalse($emails->contains(User::AGENT_WORKFORCE_EMAIL));
        $this->assertSame(2, collect($res->json('data.accounts'))->firstWhere('email', 'calon@galangan.test')['sandbox_visits']);
    }
}
