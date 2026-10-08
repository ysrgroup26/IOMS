<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\DailyReport;
use App\Models\Package;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.107.0 -- DELETING SOMETHING OTHER MODULES REFER TO.
 *
 * Found by sweeping every DELETE route for a model whose table other tables
 * hold a RESTRICT foreign key to, with no dependency check in the
 * controller. Two were real, and both failed in the same two ways.
 *
 * `users` has no soft deletes and 93 foreign keys point at it:
 *
 *   27 RESTRICT and 4 NO ACTION -> the delete THROWS for any account that
 *   has approved something or owns a document, which is every account that
 *   has been used. The administrator saw a 500.
 *
 *   59 SET NULL -> where it succeeded, it silently nulled the actor on 59
 *   relationships, erasing exactly the attribution v2.100.0 exists to
 *   preserve. An audit trail that empties itself when somebody leaves the
 *   company is not an audit trail.
 *
 * `projects` is the same shape: `daily_reports.project_id` RESTRICTs, and
 * fifteen other keys SET NULL.
 *
 * Both now refuse, and name the operation the product already has --
 * deactivating an account, closing a project -- rather than crashing or
 * quietly detaching history.
 */
class DeletionPreservesHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Yard', 'slug' => 'y-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Co', 'code' => 'YC', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $this->tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($this->tenant->fresh());

        $this->admin = $this->user(User::ROLE_SUPER_ADMIN);
    }

    private function user(string $role, array $overrides = []): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@y.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => $role,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /* ==================================================================
     * AN ACCOUNT WITH A HISTORY
     * ================================================================== */

    public function test_an_account_that_has_signed_in_cannot_be_deleted(): void
    {
        $member = $this->user('hse', ['last_login_at' => now()->subDay()]);

        $this->actingAs($this->admin)
            ->delete(route('settings.users.destroy', $member->id))
            ->assertSessionHas('error');

        $this->assertNotNull(
            User::find($member->id),
            'An account with a history must survive, so its records keep pointing at somebody.'
        );
    }

    public function test_an_account_that_has_acted_cannot_be_deleted(): void
    {
        $member = $this->user('hse');

        $this->actingAs($member);
        ActivityLog::record('created', 'Did something.');

        $this->actingAs($this->admin)
            ->delete(route('settings.users.destroy', $member->id))
            ->assertSessionHas('error');

        $this->assertNotNull(User::find($member->id));
    }

    /**
     * THE POINT OF REFUSING: the attribution survives. Before this, the
     * delete either threw or nulled the actor across 59 relationships.
     */
    public function test_refusing_the_delete_keeps_the_audit_trail_attributed(): void
    {
        $member = $this->user('hse', ['display_name' => 'Budi (Produksi)']);

        $this->actingAs($member);
        $log = ActivityLog::record('updated', 'Something happened.');

        $this->actingAs($this->admin)
            ->delete(route('settings.users.destroy', $member->id));

        $log->refresh();

        $this->assertSame($member->id, $log->user_id, 'The link to the account must survive.');
        $this->assertSame('Budi (Produksi)', $log->actor_name);
    }

    /** Deactivating is the operation that was always intended. */
    public function test_an_account_with_a_history_can_still_be_deactivated(): void
    {
        $member = $this->user('hse', ['last_login_at' => now()->subDay()]);

        $this->actingAs($this->admin)
            ->put(route('settings.users.update', $member->id), [
                'name' => $member->name,
                'email' => $member->email,
                'role' => 'hse',
                'is_active' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) $member->fresh()->is_active);
    }

    /** An account that genuinely did nothing is still removable. */
    public function test_an_unused_account_can_still_be_deleted(): void
    {
        $mistake = $this->user('hse');

        $this->actingAs($this->admin)
            ->delete(route('settings.users.destroy', $mistake->id))
            ->assertSessionHasNoErrors();

        $this->assertNull(User::find($mistake->id), 'There is no history to protect here.');
    }

    /* ==================================================================
     * A PROJECT WITH WORK RECORDED AGAINST IT
     * ================================================================== */

    private function project(): Project
    {
        return Project::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Drydocking MT Sinar',
            'status' => 'ongoing',
        ]);
    }

    /**
     * PROJECT MANAGEMENT IS RETIRED, AND CLEANLY SO.
     *
     * The same deletion guard was added to `ProjectController::destroy()`,
     * where `daily_reports.project_id` RESTRICTs in exactly the same way.
     * It cannot be exercised over HTTP, and that is correct rather than a
     * fault: `project-management` is one of the seven departments retired
     * in v2.84.0 (ADR 045), so `EnforceTenantEntitlement` refuses the
     * prefix on every plan.
     *
     * Asserted here because "unreachable" and "broken" look identical from
     * the outside, and the difference is the whole point of a retirement.
     * The navigation half is pinned by WorkspaceArchitectureTest; this is
     * the server half.
     */
    public function test_project_management_is_retired_rather_than_broken(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin)
            ->delete(route('projects.destroy', $project->id))
            ->assertForbidden();

        $this->assertNotNull(Project::find($project->id));

        // And the navigation does not offer what the server refuses.
        $navigation = file_get_contents(resource_path('js/lib/workspaces.js'));

        foreach (['projects.index', 'daily-reports.index', 'milestones.index'] as $retired) {
            $this->assertStringNotContainsString(
                "'".$retired."'",
                $navigation,
                "A retired route must not be offered in navigation: {$retired}"
            );
        }
    }

    /**
     * The sweep that found these, kept as a rule: a DELETE route whose
     * model other tables RESTRICT against must say so rather than let the
     * database raise it.
     */
    public function test_both_deletions_explain_themselves_rather_than_throwing(): void
    {
        foreach ([
            app_path('Http/Controllers/SettingsController.php') => 'destroyUser',
            app_path('Http/Controllers/ProjectController.php') => 'destroy',
        ] as $file => $method) {
            $source = file_get_contents($file);

            preg_match('/public function '.$method.'\(.*?\n    \}/s', $source, $body);

            $this->assertNotEmpty($body, "{$method}() should exist.");
            $this->assertStringContainsString(
                "with('error'",
                $body[0],
                "{$method}() must refuse with an explanation rather than let the database throw."
            );
        }
    }
}
