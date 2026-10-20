<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Package;
use App\Models\PermitToWork;
use App\Models\PtwWorkflowSetting;
use App\Models\PushSubscription;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notifications\WebPushService;
use App\Services\PtwWorkflowService;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PpeTypeSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.129.0 -- NOTIFICATIONS THAT ARRIVE WITHOUT A REFRESH.
 *
 * The brief for this release is an EXPERIENCE -- a badge that changes while
 * somebody watches it, a toast, a phone that rings in a pocket -- and most of
 * that is only provable in a browser, which is where it was driven (see
 * [[Verification Status]]). What a test can pin is everything underneath it,
 * and the properties here are the ones that would make the experience a lie
 * if they broke:
 *
 *   - the ORDER: an approver is not asked before a verification exists;
 *   - the CURSOR: nothing is lost across a disconnect and nothing is doubled;
 *   - the BOUNDARY: a notification reaches its recipient and nobody else,
 *     and a change marker says nothing about another tenant's records;
 *   - the INDEPENDENCE: a push that fails does not undo a decision.
 *
 * @see docs/ADR/069-a-notification-arrives-without-being-asked-for.md
 */
class RealtimeNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $requester;

    private User $verifier;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        /*
         * No catch-all `Http::fake()` here: stubs MERGE and the first
         * registered one wins, so a per-test `Http::fake(['*' => 410])` was
         * silently answered by this one instead. Push is unconfigured by
         * default in tests, so nothing reaches the network anyway.
         */
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);
        $this->seed(PpeTypeSeeder::class);

        [$this->tenant, $this->company] = $this->makeTenant('rt');

        $this->requester = $this->user('req', User::ROLE_MANAGER, $this->tenant, $this->company);
        $this->requester->update(['ptw_access' => true]);
        $this->verifier = $this->user('ver', User::ROLE_HSE, $this->tenant, $this->company);
        $this->approver = $this->user('app', User::ROLE_MANAGER, $this->tenant, $this->company);

        PtwWorkflowSetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'verify_roles' => [User::ROLE_HSE], 'verify_user_ids' => [],
                'approve_roles' => [], 'approve_user_ids' => [$this->approver->id],
            ],
        );
    }

    /** @return array{0: Tenant, 1: Company} */
    private function makeTenant(string $suffix): array
    {
        $package = Package::where('slug', 'business')->sole();

        $tenant = Tenant::create([
            'name' => 'Yard '.$suffix, 'slug' => 'rt-'.$suffix.'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Unit '.$suffix, 'code' => strtoupper($suffix), 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($tenant->fresh());
        Department::create(['company_id' => $company->id, 'name' => 'HSE']);

        return [$tenant, $company];
    }

    private function user(string $slug, string $role, Tenant $tenant, Company $company): User
    {
        return User::create([
            'name' => ucfirst($slug), 'email' => $slug.'-'.uniqid().'@rt.test',
            'password' => bcrypt('secret-pass-1'), 'role' => $role,
            'tenant_id' => $tenant->id, 'company_id' => $company->id, 'is_active' => true,
        ]);
    }

    private function permit(string $status = PermitToWork::STATUS_SUBMITTED, ?Company $company = null, ?User $requester = null): PermitToWork
    {
        return PermitToWork::withoutGlobalScopes()->create([
            'ptw_number' => 'PTW-RT-'.uniqid(),
            'company_id' => ($company ?? $this->company)->id,
            'permit_type' => 'hot_work',
            'location' => 'Workshop Zone B',
            'work_description' => 'Welding on the main deck.',
            'start_datetime' => Carbon::parse('2026-11-01 01:00:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-11-01 09:00:00', 'UTC'),
            'requested_by' => ($requester ?? $this->requester)->id,
            'status' => $status,
        ]);
    }

    private function transition(User $actor, PermitToWork $permit, string $status)
    {
        return $this->actingAs($actor)
            ->post('/permits-to-work/'.$permit->id.'/transition', ['status' => $status]);
    }

    private function live(User $user, array $query = [])
    {
        return $this->actingAs($user)->getJson('/notifications/live?'.http_build_query($query));
    }

    /*
    |--------------------------------------------------------------------------
    | 1-5. The PTW chain, in order
    |--------------------------------------------------------------------------
    */

    /** The payload the create form posts -- raising a permit submits it. */
    private function formPayload(): array
    {
        return [
            'company_id' => $this->company->id,
            'permit_type' => 'hot_work',
            'location' => 'Workshop Zone B',
            'work_description' => 'Welding on the main deck.',
            'start_datetime' => '2026-11-01 08:00:00',
            'end_datetime' => '2026-11-01 16:00:00',
        ];
    }

    public function test_a_submitted_permit_stores_a_notification_for_the_verifier(): void
    {
        $this->actingAs($this->requester)->post('/permits-to-work', $this->formPayload())->assertRedirect();

        $notification = Notification::where('user_id', $this->verifier->id)->latest('id')->first();

        $this->assertNotNull($notification, 'The configured verifier must be told.');
        $this->assertStringContainsString('/permits-to-work/', (string) $notification->url);
        $this->assertSame(PtwWorkflowService::STAGE_VERIFY, $notification->meta['ptw_stage'] ?? null);
    }

    public function test_the_approver_is_not_asked_before_a_verification_exists(): void
    {
        $this->actingAs($this->requester)->post('/permits-to-work', $this->formPayload())->assertRedirect();

        $this->assertSame(0, Notification::where('user_id', $this->approver->id)->count(),
            'The approver heard about a permit nobody has verified yet.');
    }

    public function test_verification_then_notifies_the_configured_approver(): void
    {
        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        $notification = Notification::where('user_id', $this->approver->id)->latest('id')->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('approval', $notification->title);
        $this->assertSame(PtwWorkflowService::STAGE_APPROVE, $notification->meta['ptw_stage'] ?? null);
    }

    public function test_the_requester_is_told_the_permit_is_active_in_words_they_can_act_on(): void
    {
        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();
        $this->transition($this->approver, $permit->fresh(), PermitToWork::STATUS_APPROVED)->assertRedirect();
        $this->transition($this->approver, $permit->fresh(), PermitToWork::STATUS_ACTIVE)->assertRedirect();

        $notification = Notification::where('user_id', $this->requester->id)
            ->where('title', 'Permit is now active')->first();

        $this->assertNotNull($notification, 'The person who raised the permit must be told the work may start.');
        $this->assertStringContainsString($permit->ptw_number, (string) $notification->body);
        $this->assertStringContainsString('ACTIVE', (string) $notification->body);
        // Not the trait's generic sentence.
        $this->assertStringNotContainsString('is now Active', (string) $notification->title);
    }

    public function test_a_returned_permit_tells_the_requester_why(): void
    {
        $permit = $this->permit();

        $this->actingAs($this->verifier)->post('/permits-to-work/'.$permit->id.'/transition', [
            'status' => PermitToWork::STATUS_REJECTED,
            'reason' => 'Area belum diisolasi.',
        ])->assertRedirect();

        $notification = Notification::where('user_id', $this->requester->id)->latest('id')->first();

        $this->assertSame('Permit returned for revision', $notification->title);
        $this->assertStringContainsString('Area belum diisolasi.', (string) $notification->body,
            'The reason IS the notification; it must lead, not be dropped.');
    }

    /*
    |--------------------------------------------------------------------------
    | 6-10. The live endpoint, and what a dropped connection costs
    |--------------------------------------------------------------------------
    */

    public function test_the_first_call_reports_where_to_start_and_replays_nothing(): void
    {
        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED);

        // No `cursor` parameter at all: the first ask.
        $response = $this->live($this->approver)->assertOk();

        $response->assertJsonCount(0, 'items');
        $this->assertGreaterThan(0, $response->json('cursor'));
        $this->assertSame(1, $response->json('unread_count'),
            'The count is reported even though the history is not replayed as new.');
    }

    /**
     * Including the case that was broken: a tab whose cursor is ZERO because
     * the person had no notifications when it opened. `cursor=0` means "I
     * have seen nothing", and must not be read as "this is my first ask".
     */
    public function test_a_tab_that_was_away_receives_exactly_what_it_missed(): void
    {
        // Where this tab was when it lost the connection -- 0, here.
        $cursor = $this->live($this->approver)->json('cursor');
        $this->assertSame(0, $cursor);

        $first = $this->permit();
        $this->transition($this->verifier, $first, PermitToWork::STATUS_VERIFIED);

        $second = $this->permit();
        $this->transition($this->verifier, $second, PermitToWork::STATUS_VERIFIED);

        $response = $this->live($this->approver, ['cursor' => $cursor])->assertOk();

        $this->assertCount(2, $response->json('items'), 'Both missed notifications must arrive.');
        // Newest first, so a client prepending them keeps the order.
        $this->assertGreaterThan($response->json('items.1.id'), $response->json('items.0.id'));
    }

    public function test_asking_twice_with_the_same_cursor_is_idempotent(): void
    {
        $cursor = $this->live($this->approver)->json('cursor');

        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED);

        $first = $this->live($this->approver, ['cursor' => $cursor])->json('items');
        $second = $this->live($this->approver, ['cursor' => $cursor])->json('items');

        // A retry after a timeout must not invent a second notification.
        $this->assertSame(array_column($first, 'id'), array_column($second, 'id'));
    }

    public function test_the_feed_carries_only_the_signed_in_persons_notifications(): void
    {
        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED);

        $cursor = max(0, (int) Notification::min('id') - 1);

        $mine = $this->live($this->approver, ['cursor' => $cursor])->json('items');
        $theirs = $this->live($this->requester, ['cursor' => $cursor])->json('items');

        $this->assertNotEmpty($mine);
        $this->assertEmpty(array_intersect(array_column($mine, 'id'), array_column($theirs, 'id')),
            'One account received another account\'s notifications.');
    }

    public function test_a_notification_never_crosses_into_another_tenant(): void
    {
        [$otherTenant, $otherCompany] = $this->makeTenant('other');
        $intruder = $this->user('intruder', User::ROLE_MANAGER, $otherTenant, $otherCompany);

        app(CurrentTenant::class)->set($this->tenant->fresh());

        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED);

        $response = $this->live($intruder, ['cursor' => 0])->assertOk();

        $this->assertSame(0, $response->json('unread_count'));
        $this->assertCount(0, $response->json('items'));
    }

    /*
    |--------------------------------------------------------------------------
    | 11-13. Change markers: enough to refresh a page, and nothing more
    |--------------------------------------------------------------------------
    */

    public function test_a_watched_record_reports_a_marker_that_moves_when_it_does(): void
    {
        $permit = $this->permit();

        $before = $this->live($this->approver, ['watch' => 'PermitToWork:'.$permit->id])
            ->json('markers.PermitToWork:'.$permit->id);

        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED);

        $after = $this->live($this->approver, ['watch' => 'PermitToWork:'.$permit->id])
            ->json('markers.PermitToWork:'.$permit->id);

        $this->assertNotSame($before, $after, 'A page watching this permit would never learn it had moved.');
    }

    public function test_a_marker_says_nothing_about_another_tenants_record(): void
    {
        [$otherTenant, $otherCompany] = $this->makeTenant('probe');
        $intruder = $this->user('probe', User::ROLE_SUPER_ADMIN, $otherTenant, $otherCompany);

        app(CurrentTenant::class)->set($this->tenant->fresh());
        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED);

        $marker = $this->live($intruder, ['watch' => 'PermitToWork:'.$permit->id])
            ->json('markers.PermitToWork:'.$permit->id);

        $this->assertSame(0, $marker,
            'Another tenant learned that a permit exists and when it last changed.');
    }

    public function test_only_allow_listed_types_can_be_watched(): void
    {
        $response = $this->live($this->approver, ['watch' => 'User:1,ActivityLog:1,PermitToWork:9999'])->assertOk();

        $markers = $response->json('markers');

        $this->assertArrayNotHasKey('User:1', $markers);
        $this->assertArrayNotHasKey('ActivityLog:1', $markers);
        $this->assertArrayHasKey('PermitToWork:9999', $markers);
    }

    /*
    |--------------------------------------------------------------------------
    | 14-15. Read state
    |--------------------------------------------------------------------------
    */

    public function test_marking_read_answers_the_new_count_without_a_redirect(): void
    {
        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED);

        $notification = Notification::where('user_id', $this->approver->id)->sole();

        $response = $this->actingAs($this->approver)
            ->postJson('/notifications/'.$notification->id.'/read')
            ->assertOk();

        $this->assertSame(0, $response->json('unread_count'));
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_a_read_notification_stays_in_the_history(): void
    {
        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED);

        $notification = Notification::where('user_id', $this->approver->id)->sole();
        $this->actingAs($this->approver)->postJson('/notifications/'.$notification->id.'/read')->assertOk();

        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
        $this->assertSame(1, Notification::where('user_id', $this->approver->id)->count(),
            'Marking read must not delete the record.');
    }

    public function test_somebody_elses_notification_cannot_be_marked_read(): void
    {
        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED);

        $notification = Notification::where('user_id', $this->approver->id)->sole();

        // 404 rather than 403: that a notification exists is not something
        // this account may learn.
        $this->actingAs($this->requester)
            ->postJson('/notifications/'.$notification->id.'/read')
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    /*
    |--------------------------------------------------------------------------
    | 16-18. Web push: the address book, and its independence from the decision
    |--------------------------------------------------------------------------
    */

    private function configurePush(): array
    {
        $pair = app(WebPushService::class)->generateKeyPair();

        config([
            'realtime.push.public_key' => $pair['public'],
            'realtime.push.private_key' => $pair['private'],
        ]);

        return $pair;
    }

    public function test_a_browser_registers_once_however_often_it_re_subscribes(): void
    {
        $this->configurePush();

        $payload = [
            'endpoint' => 'https://push.example.test/abc',
            'keys' => ['p256dh' => 'key', 'auth' => 'auth'],
        ];

        $this->actingAs($this->approver)->postJson('/notifications/push-subscription', $payload)->assertOk();
        $this->actingAs($this->approver)->postJson('/notifications/push-subscription', $payload)->assertOk();

        $this->assertSame(1, PushSubscription::where('user_id', $this->approver->id)->count(),
            'A re-subscription must update the row, or one notification rings a phone twice.');
    }

    public function test_push_is_not_offered_when_the_installation_has_no_keys(): void
    {
        config(['realtime.push.public_key' => null, 'realtime.push.private_key' => null]);

        $this->actingAs($this->approver)
            ->postJson('/notifications/push-subscription', ['endpoint' => 'https://push.example.test/x'])
            ->assertStatus(503);
    }

    public function test_a_device_can_only_be_unsubscribed_by_the_person_holding_it(): void
    {
        $this->configurePush();

        $endpoint = 'https://push.example.test/theirs';

        PushSubscription::create([
            'user_id' => $this->approver->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
        ]);

        $this->actingAs($this->requester)
            ->deleteJson('/notifications/push-subscription', ['endpoint' => $endpoint])
            ->assertOk();

        $this->assertDatabaseHas('push_subscriptions', ['endpoint_hash' => PushSubscription::hashFor($endpoint)]);

        $this->actingAs($this->approver)
            ->deleteJson('/notifications/push-subscription', ['endpoint' => $endpoint])
            ->assertOk();

        $this->assertDatabaseMissing('push_subscriptions', ['endpoint_hash' => PushSubscription::hashFor($endpoint)]);
    }

    public function test_a_push_carries_no_payload_and_a_signed_vapid_token(): void
    {
        $this->configurePush();

        Http::fake(['*' => Http::response('', 201)]);

        $endpoint = 'https://push.example.test/device';

        $subscription = PushSubscription::create([
            'user_id' => $this->approver->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
        ]);

        $this->assertTrue(app(WebPushService::class)->pushTo($subscription, ['topic' => 'ptw-1']));

        Http::assertSent(function ($request) {
            $this->assertSame('', (string) $request->body(), 'A tickle must carry nothing past a lock screen.');
            $this->assertStringStartsWith('vapid t=', $request->header('Authorization')[0]);
            $this->assertStringContainsString(', k=', $request->header('Authorization')[0]);
            $this->assertSame('high', $request->header('Urgency')[0]);

            return true;
        });
    }

    public function test_an_endpoint_that_is_gone_is_forgotten(): void
    {
        $this->configurePush();

        Http::fake(['*' => Http::response('', 410)]);

        $endpoint = 'https://push.example.test/stale';

        $subscription = PushSubscription::create([
            'user_id' => $this->approver->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
        ]);

        $this->assertFalse(app(WebPushService::class)->pushTo($subscription));
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subscription->id]);
    }

    /**
     * THE ONE THAT MATTERS MOST. A permit that was verified stays verified
     * whether or not anybody's phone rang.
     */
    public function test_a_failing_push_service_cannot_undo_a_decision(): void
    {
        $this->configurePush();

        Http::fake(function () {
            throw new \RuntimeException('the push service is on fire');
        });

        $endpoint = 'https://push.example.test/burning';

        PushSubscription::create([
            'user_id' => $this->approver->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
        ]);

        $permit = $this->permit();

        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        $this->assertSame(PermitToWork::STATUS_VERIFIED, $permit->fresh()->status);
        $this->assertNotNull($permit->fresh()->hse_verifier_id);
        $this->assertSame(1, Notification::where('user_id', $this->approver->id)->count(),
            'The record of the notification must survive a delivery that did not.');
    }

    /**
     * And the reverse: nothing is delivered for a decision that did not
     * commit. The push is deferred to after the commit, so a rolled-back
     * transaction announces nothing.
     */
    public function test_nothing_is_announced_for_a_transaction_that_rolled_back(): void
    {
        $this->configurePush();
        Http::fake(['*' => Http::response('', 201)]);

        $endpoint = 'https://push.example.test/never';

        PushSubscription::create([
            'user_id' => $this->approver->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
        ]);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () {
                app(\App\Services\NotificationService::class)->notify(
                    $this->approver,
                    Notification::CATEGORY_APPROVAL,
                    'A decision that will not survive',
                );

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException $e) {
            // Expected.
        }

        $this->assertSame(0, Notification::where('user_id', $this->approver->id)->count());
        Http::assertNothingSent();
    }

    /*
    |--------------------------------------------------------------------------
    | The destination still checks permission
    |--------------------------------------------------------------------------
    */

    public function test_a_notifications_destination_is_still_gated_by_the_server(): void
    {
        [$otherTenant, $otherCompany] = $this->makeTenant('gate');
        $outsider = $this->user('outsider', User::ROLE_SUPER_ADMIN, $otherTenant, $otherCompany);

        app(CurrentTenant::class)->set($this->tenant->fresh());
        $permit = $this->permit();

        // The url a notification would carry, opened by somebody it does not
        // belong to. A notification is not a capability.
        $this->actingAs($outsider)->get('/permits-to-work/'.$permit->id)->assertNotFound();
    }

    public function test_the_live_endpoint_refuses_a_guest(): void
    {
        $this->getJson('/notifications/live')->assertUnauthorized();
    }

    public function test_the_activity_a_marker_reads_is_the_records_own(): void
    {
        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED);

        $expected = (int) ActivityLog::where('subject_type', PermitToWork::class)
            ->where('subject_id', $permit->id)
            ->max('id');

        $this->assertSame(
            $expected,
            $this->live($this->approver, ['watch' => 'PermitToWork:'.$permit->id])->json('markers.PermitToWork:'.$permit->id),
        );
    }
}
