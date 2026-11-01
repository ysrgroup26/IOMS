<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebPush;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CorrectiveAction;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Package;
use App\Models\PushSubscription;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Models\Workspace;
use App\Services\Notifications\WebPushService;
use App\Services\NotificationService;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * v2.141.0 -- THE NOTIFICATION EVENT LAYER.
 *
 * Who is told what: an assignee when work is handed to them, the owner of a
 * deadline the day before and every day it is overdue (once), each in their
 * own tenant; what a person may switch off and what they may not; and that
 * a push is a job with a delivery record.
 */
class NotificationEventLayerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private User $lead;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);
        [$this->tenant, $this->yard] = $this->organisation('Galangan');
        $this->lead = $this->person($this->tenant, $this->yard, User::ROLE_SUPER_ADMIN);
        $this->worker = $this->person($this->tenant, $this->yard, User::ROLE_HSE);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function organisation(string $name): array
    {
        $package = Package::where('slug', 'business')->sole();
        $tenant = Tenant::create(['name' => $name, 'slug' => 's-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $company = Company::withoutGlobalScopes()->create(['name' => $name, 'code' => strtoupper(substr($name, 0, 3)).rand(10, 99), 'tenant_id' => $tenant->id, 'is_active' => true]);
        Subscription::create(['tenant_id' => $tenant->id, 'package_id' => $package->id, 'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription', 'billing_cycle' => Subscription::CYCLE_MONTHLY, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth()]);
        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));

        return [$tenant, $company];
    }

    private function person(Tenant $tenant, Company $company, string $role): User
    {
        return User::withoutGlobalScopes()->create(['name' => ucfirst($role).' '.uniqid(), 'email' => uniqid().'@g.test', 'password' => bcrypt('secret-pass-1'), 'role' => $role, 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'is_active' => true]);
    }

    private function task(array $attributes = []): Task
    {
        return Task::create([
            'task_number' => 'TSK-'.uniqid(), 'title' => 'Ganti filter pompa', 'company_id' => $this->yard->id,
            'created_by' => $this->lead->id, 'status' => Task::STATUS_OPEN, 'priority' => Task::PRIORITY_MEDIUM,
            ...$attributes,
        ]);
    }

    public function test_an_assignee_is_told_when_a_task_is_handed_to_them_and_where_to_open_it(): void
    {
        $this->actingAs($this->lead);
        $task = $this->task(['assigned_user_id' => $this->worker->id]);

        $note = Notification::where('user_id', $this->worker->id)->sole();
        $this->assertSame('task.assigned', $note->event_key);
        $this->assertSame(Notification::CATEGORY_ASSIGNMENT, $note->category);
        $this->assertSame('tasks.show', $note->meta['route']);
        $this->assertStringContainsString($task->task_number, $note->title);
    }

    public function test_nobody_is_told_about_work_they_gave_themselves_and_a_reassignment_tells_the_new_assignee(): void
    {
        $this->actingAs($this->worker);
        $task = $this->task(['assigned_user_id' => $this->worker->id]);
        $this->assertSame(0, Notification::count());

        $this->actingAs($this->worker);
        $task->update(['assigned_user_id' => $this->lead->id]);
        $this->assertSame(1, Notification::where('user_id', $this->lead->id)->where('event_key', 'task.assigned')->count());
    }

    public function test_a_corrective_action_from_any_module_tells_its_owner(): void
    {
        $this->actingAs($this->lead);
        CorrectiveAction::create([
            'company_id' => $this->yard->id, 'source_type' => Task::class, 'source_id' => $this->task()->id,
            'action' => 'Pasang pagar pengaman di tepi dok', 'assigned_to' => $this->worker->id,
            'priority' => 'high', 'due_date' => now()->addDays(5)->toDateString(), 'status' => CorrectiveAction::STATUS_OPEN, 'created_by' => $this->lead->id,
        ]);

        $note = Notification::where('user_id', $this->worker->id)->sole();
        $this->assertSame('capa.assigned', $note->event_key);
        $this->assertSame('high', $note->priority);
    }

    public function test_deadline_reminders_run_without_a_tenant_reach_only_their_own_tenant_and_never_repeat(): void
    {
        $this->task(['assigned_user_id' => $this->worker->id, 'due_date' => now()->addDay()->toDateString()]);
        $this->task(['assigned_user_id' => $this->worker->id, 'due_date' => now()->subDays(2)->toDateString(), 'title' => 'Terlambat']);
        $this->task(['assigned_user_id' => $this->worker->id, 'due_date' => now()->addDays(10)->toDateString(), 'title' => 'Masih lama']);

        [$otherTenant, $otherYard] = $this->organisation('Lain');
        $outsider = $this->person($otherTenant, $otherYard, User::ROLE_HSE);
        Notification::query()->delete();   // assignments are not what this test is about

        app(CurrentTenant::class)->set(null);   // how the scheduler runs it
        $this->artisan('notifications:deadlines')->assertSuccessful();
        $this->artisan('notifications:deadlines')->assertSuccessful();   // an hour later

        $this->assertSame(1, Notification::where('user_id', $this->worker->id)->where('event_key', 'task.due_soon')->count());
        $this->assertSame(1, Notification::where('user_id', $this->worker->id)->where('event_key', 'task.overdue')->count());
        $this->assertSame(2, Notification::where('user_id', $this->worker->id)->count(), 'Nothing for work due in ten days.');
        $this->assertSame(0, Notification::where('user_id', $outsider->id)->count());
    }

    public function test_a_person_may_switch_reminders_off_but_never_the_in_app_record_of_an_assignment(): void
    {
        UserNotificationPreference::create(['user_id' => $this->worker->id, 'category' => Notification::CATEGORY_REMINDER, 'in_app' => false, 'push' => false]);
        UserNotificationPreference::create(['user_id' => $this->worker->id, 'category' => Notification::CATEGORY_ASSIGNMENT, 'in_app' => false, 'push' => false]);

        $this->actingAs($this->lead);
        $this->task(['assigned_user_id' => $this->worker->id, 'due_date' => now()->addDay()->toDateString()]);
        app(CurrentTenant::class)->set(null);
        $this->artisan('notifications:deadlines')->assertSuccessful();

        $this->assertSame(1, Notification::where('user_id', $this->worker->id)->where('event_key', 'task.assigned')->count());
        $this->assertSame(0, Notification::where('user_id', $this->worker->id)->where('event_key', 'task.due_soon')->count());
    }

    public function test_the_account_page_saves_preferences_and_cannot_switch_off_approvals_in_app(): void
    {
        $this->actingAs($this->worker)->get(route('account.overview'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('notificationPreferences', 6));

        $this->actingAs($this->worker)->put(route('account.notification-preferences.update'), ['preferences' => [
            ['category' => Notification::CATEGORY_APPROVAL, 'in_app' => false, 'push' => false],
            ['category' => Notification::CATEGORY_INFORMATION, 'in_app' => false, 'push' => true],
        ]])->assertSessionHasNoErrors();

        $this->assertTrue((bool) UserNotificationPreference::where('user_id', $this->worker->id)->where('category', 'approval')->value('in_app'));
        $this->assertFalse((bool) UserNotificationPreference::where('user_id', $this->worker->id)->where('category', 'approval')->value('push'));
        $this->assertFalse((bool) UserNotificationPreference::where('user_id', $this->worker->id)->where('category', 'information')->value('in_app'));

        $this->actingAs($this->worker)->put(route('account.notification-preferences.update'), ['preferences' => [
            ['category' => 'made_up', 'in_app' => true, 'push' => true],
        ]])->assertSessionHasErrors('preferences.0.category');
    }

    public function test_push_is_a_queued_job_and_is_not_queued_for_a_person_who_switched_push_off(): void
    {
        Queue::fake();
        $this->actingAs($this->lead);
        $this->task(['assigned_user_id' => $this->worker->id]);
        Queue::assertPushed(DeliverWebPush::class, 1);

        UserNotificationPreference::create(['user_id' => $this->lead->id, 'category' => Notification::CATEGORY_ASSIGNMENT, 'in_app' => true, 'push' => false]);
        $this->actingAs($this->worker);
        $this->task(['assigned_user_id' => $this->lead->id]);
        Queue::assertPushed(DeliverWebPush::class, 1);
    }

    public function test_every_push_attempt_is_recorded_sent_or_no_device(): void
    {
        $keys = app(WebPushService::class)->generateKeyPair();
        config(['realtime.push.public_key' => $keys['public'], 'realtime.push.private_key' => $keys['private'], 'realtime.push.subject' => 'mailto:ops@ioms.test']);
        Http::fake(['*' => Http::response('', 201)]);

        $this->actingAs($this->lead);
        $this->task(['assigned_user_id' => $this->worker->id]);
        $first = Notification::where('user_id', $this->worker->id)->sole();
        $this->assertSame(NotificationDelivery::STATUS_NO_DEVICE, $first->deliveries()->sole()->status);

        PushSubscription::create(['user_id' => $this->worker->id, 'endpoint' => 'https://push.example.test/abc', 'endpoint_hash' => hash('sha256', 'https://push.example.test/abc'), 'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg']);
        $this->task(['assigned_user_id' => $this->worker->id, 'title' => 'Kedua']);
        $second = Notification::where('user_id', $this->worker->id)->latest('id')->first();
        $delivery = $second->deliveries()->sole();
        $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame(1, $delivery->devices);
    }

    public function test_a_company_cannot_switch_off_approvals_or_assignments_n1(): void
    {
        CompanySetting::set('notification_preferences', json_encode(['approval' => false, 'assignment' => false, 'information' => false]));

        $sent = app(NotificationService::class)->notify($this->worker, Notification::CATEGORY_APPROVAL, 'PTW waiting for your approval');
        $this->assertNotNull($sent, 'An approval request must reach the approver whatever the company switch says.');
        $this->assertNull(app(NotificationService::class)->notify($this->worker, Notification::CATEGORY_INFORMATION, 'FYI'));

        $this->actingAs($this->lead);
        $this->task(['assigned_user_id' => $this->worker->id]);
        $this->assertSame(1, Notification::where('user_id', $this->worker->id)->where('event_key', 'task.assigned')->count());

        $this->actingAs($this->lead)->post(route('settings.notifications'), ['preferences' => ['approval' => false, 'reminder' => true, 'warning' => true, 'success' => true, 'information' => true]]);
        $stored = json_decode(CompanySetting::getUncached('notification_preferences'), true);
        $this->assertTrue($stored['approval'], 'Saving Settings must not switch approvals off.');
    }
}
