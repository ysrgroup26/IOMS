<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * ADR 086 -- the Agent API reads, by a token, for a scope, and never writes.
 */
class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $agentAccount;

    private Tenant $tenant;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $package = Package::create([
            'name' => 'Professional', 'slug' => 'professional-'.uniqid(), 'is_active' => true, 'is_public' => true,
            'price_monthly' => 555000, 'price_yearly' => 6105000, 'currency' => 'IDR',
        ]);
        $this->tenant = Tenant::create(['name' => 'Galangan Uji', 'slug' => 'galangan-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        Company::withoutGlobalScopes()->create(['name' => 'Galangan Uji Co', 'code' => 'GUC', 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $this->subscription = Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
            'license_key' => 'LISENSI-RAHASIA-123',
        ]);

        $this->agentAccount = User::create([
            'name' => 'TICorp AI Workforce', 'email' => 'agent@tahadagroup.test', 'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);
    }

    private function token(array $scopes, ?User $owner = null): string
    {
        $abilities = [AuthenticateAgentToken::READ, ...array_map(fn ($s) => "agent:{$s}", $scopes)];

        return ($owner ?? $this->agentAccount)->createToken('agent: uji', $abilities)->plainTextToken;
    }

    public function test_requires_a_bearer_token_and_refuses_a_session(): void
    {
        $this->getJson('/api/agent/v1/ringkasan')->assertStatus(401);

        // A signed-in Master Admin browser (statefulApi) is NOT an agent token.
        $this->actingAs($this->agentAccount)->getJson('/api/agent/v1/ringkasan')->assertStatus(401);
    }

    public function test_scope_is_enforced_per_route(): void
    {
        $plain = $this->token(['support']);

        $this->withToken($plain)->getJson('/api/agent/v1/tiket')->assertOk();
        $this->withToken($plain)->getJson('/api/agent/v1/pembayaran')->assertStatus(403);
        $this->withToken($plain)->getJson('/api/agent/v1/ringkasan')->assertStatus(403);
    }

    public function test_a_token_without_agent_read_is_refused(): void
    {
        $plain = $this->agentAccount->createToken('other', ['agent:ops'])->plainTextToken;

        $this->withToken($plain)->getJson('/api/agent/v1/ringkasan')->assertStatus(403);
    }

    public function test_owner_must_remain_an_active_platform_admin(): void
    {
        $plain = $this->token(['ops']);
        $this->withToken($plain)->getJson('/api/agent/v1/ringkasan')->assertOk();

        $this->agentAccount->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/agent/v1/ringkasan')->assertStatus(403);

        $tenantAdmin = User::create([
            'name' => 'Admin Tenant', 'email' => 'admin@galangan.test', 'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token(['ops'], $tenantAdmin))->getJson('/api/agent/v1/ringkasan')->assertStatus(403);
    }

    public function test_it_never_writes(): void
    {
        $plain = $this->token(array_keys(AuthenticateAgentToken::SCOPES));

        $this->withToken($plain)->postJson('/api/agent/v1/tiket')->assertStatus(405);
        $this->withToken($plain)->putJson('/api/agent/v1/tenant/'.$this->tenant->id)->assertStatus(405);
        $this->withToken($plain)->deleteJson('/api/agent/v1/tenant/'.$this->tenant->id)->assertStatus(405);
    }

    public function test_summary_and_tenant_detail_describe_without_granting(): void
    {
        $plain = $this->token(['ops', 'tenants']);

        $this->withToken($plain)->getJson('/api/agent/v1/ringkasan')
            ->assertOk()
            ->assertJsonPath('data.stats.tenants_total', Tenant::count())
            ->assertJsonPath('data.stats.tenants_active', Tenant::where('status', Tenant::STATUS_ACTIVE)->count());

        $res = $this->withToken($plain)->getJson('/api/agent/v1/tenant/'.$this->tenant->id)->assertOk();
        $res->assertJsonPath('data.tenant.name', 'Galangan Uji')
            ->assertJsonPath('data.subscription.package_name', 'Professional');
        $this->assertStringNotContainsString('LISENSI-RAHASIA-123', $res->getContent());
        $this->assertStringNotContainsString('license_key', $res->getContent());

        $this->withToken($plain)->getJson('/api/agent/v1/tenant')->assertOk()
            ->assertJsonFragment(['name' => 'Galangan Uji', 'plan' => 'Professional']);
        $this->withToken($plain)->getJson('/api/agent/v1/paket')->assertOk()
            ->assertJsonPath('data.plans.0.price_monthly', fn ($v) => (float) $v === 555000.0);
    }

    public function test_support_ticket_detail_carries_the_messages(): void
    {
        $ticket = SupportTicket::create([
            'reference' => 'SUP-UJI-1', 'subject' => 'Tidak bisa login', 'status' => SupportTicket::STATUS_OPEN,
            'priority' => SupportTicket::PRIORITY_NORMAL, 'channel' => 'email',
            'requester_email' => 'budi@galangan.test', 'requester_name' => 'Budi', 'tenant_id' => $this->tenant->id,
        ]);
        $ticket->messages()->create([
            'direction' => 'inbound', 'author_name' => 'Budi', 'author_email' => 'budi@galangan.test',
            'body' => 'Saya tidak bisa masuk sejak pagi.', 'sent_at' => now(),
        ]);

        $plain = $this->token(['support']);
        $this->withToken($plain)->getJson('/api/agent/v1/tiket/'.$ticket->id)
            ->assertOk()
            ->assertJsonPath('data.ticket.subject', 'Tidak bisa login')
            ->assertJsonPath('data.messages.0.body', 'Saya tidak bisa masuk sejak pagi.')
            ->assertJsonPath('data.subscription.status', fn ($v) => $v !== null);
    }

    public function test_master_admin_issues_shows_once_and_revokes(): void
    {
        $owner = User::create([
            'name' => 'Founder', 'email' => 'founder@tahadagroup.test', 'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);

        $this->actingAs($owner)->post('/platform/agent-access', [
            'name' => 'TICorp', 'owner_id' => $this->agentAccount->id, 'scopes' => ['ops', 'support'],
        ])->assertRedirect('/platform/agent-access')->assertSessionHas('agent_token');

        $token = PersonalAccessToken::where('tokenable_id', $this->agentAccount->id)->firstOrFail();
        $this->assertSame([AuthenticateAgentToken::READ, 'agent:ops', 'agent:support'], $token->abilities);
        $this->assertTrue(ActivityLog::withoutGlobalScopes()->where('action', 'created')->where('description', 'like', 'Token agent%')->exists());

        // Shown on the page the redirect lands on -- once -- and never again.
        $this->actingAs($owner)->get('/platform/agent-access')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Platform/AgentAccess')->where('new_token.name', 'TICorp')->has('tokens', 1));
        $this->actingAs($owner)->get('/platform/agent-access')->assertOk()
            ->assertInertia(fn ($page) => $page->where('new_token', null)->has('tokens', 1));

        // Only agent tokens can be revoked here.
        $other = $owner->createToken('pribadi', ['*'])->accessToken;
        $this->actingAs($owner)->delete('/platform/agent-access/'.$other->id)->assertNotFound();

        $this->actingAs($owner)->delete('/platform/agent-access/'.$token->id)->assertRedirect('/platform/agent-access');
        $this->assertNull(PersonalAccessToken::find($token->id));
        $this->assertTrue(ActivityLog::withoutGlobalScopes()->where('action', 'deleted')->where('description', 'like', 'Token agent%')->exists());
    }

    public function test_a_token_may_only_belong_to_an_active_platform_admin_and_tenant_admins_cannot_reach_the_page(): void
    {
        $tenantAdmin = User::create([
            'name' => 'Admin Tenant', 'email' => 'admin2@galangan.test', 'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        $this->actingAs($this->agentAccount)->post('/platform/agent-access', [
            'name' => 'Salah', 'owner_id' => $tenantAdmin->id, 'scopes' => ['ops'],
        ])->assertSessionHasErrors('owner_id');

        $this->actingAs($this->agentAccount)->post('/platform/agent-access', [
            'name' => 'Salah', 'owner_id' => $this->agentAccount->id, 'scopes' => ['database'],
        ])->assertSessionHasErrors('scopes.0');

        $this->actingAs($tenantAdmin)->get('/platform/agent-access')->assertStatus(403);
    }

    public function test_the_ai_workforce_account_is_created_once_owns_tokens_and_can_never_sign_in(): void
    {
        $owner = User::create([
            'name' => 'Founder', 'email' => 'founder@tahadagroup.test', 'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);

        $this->actingAs($owner)->post('/platform/agent-access/workforce')->assertRedirect('/platform/agent-access');
        $this->actingAs($owner)->post('/platform/agent-access/workforce')->assertRedirect('/platform/agent-access');
        $accounts = User::withoutGlobalScopes()->where('email', User::AGENT_WORKFORCE_EMAIL)->get();
        $this->assertCount(1, $accounts);
        $workforce = $accounts->first();
        $this->assertTrue($workforce->isPlatformAdmin() && $workforce->isAgentWorkforce() && $workforce->is_active);

        // The page now offers it as the default owner.
        $this->actingAs($owner)->get('/platform/agent-access')
            ->assertInertia(fn ($page) => $page->where('workforce.id', $workforce->id));

        // A token it owns reads the API.
        $this->app['auth']->forgetGuards();
        $plain = $workforce->createToken('agent: uji', [AuthenticateAgentToken::READ, 'agent:ops'])->plainTextToken;
        $this->withToken($plain)->getJson('/api/agent/v1/ringkasan')->assertOk();

    }

    public function test_the_ai_workforce_account_can_never_sign_in(): void
    {
        $workforce = User::withoutGlobalScopes()->create([
            'name' => 'TICorp AI Workforce', 'email' => User::AGENT_WORKFORCE_EMAIL, 'password' => 'Diketahui-2026',
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);

        // Even with a password somebody somehow knew, the form refuses it ...
        $this->post('/login', ['email' => User::AGENT_WORKFORCE_EMAIL, 'password' => 'Diketahui-2026'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('web');

        // ... and a session obtained any other way is ended on the next request.
        $this->actingAs($workforce, 'web')->get('/platform')->assertRedirect('/login');
        $this->assertGuest('web');
    }

    public function test_api_errors_are_json_not_the_friendly_page(): void
    {
        $this->get('/api/agent/v1/ringkasan', ['Accept' => 'application/json'])
            ->assertStatus(401)->assertHeader('Content-Type', 'application/json');
        $this->get('/api/agent/v1/ringkasan')->assertStatus(401)->assertJsonStructure(['message']);
        $plain = $this->token(['support']);
        $this->withToken($plain)->getJson('/api/agent/v1/tiket/999999')->assertStatus(404)->assertJsonStructure(['message']);
    }
}
