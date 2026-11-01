<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\ApprovalFlowVersion;
use App\Models\PermitToWork;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalEngine;
use App\Support\CurrentTenant;
use Database\Seeders\ApprovalFlowReviewSeeder;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.141.0 -- the staging review data for the Approval Flow Builder is what
 * the product itself would produce: permits at each stage, version 1 kept
 * by permits under way, version 2 taken by the next one, and separation of
 * duties visible on the permit waiting for its Final Verification.
 */
class ApprovalFlowReviewSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_review_organisation_shows_every_point_of_the_flow(): void
    {
        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);
        $this->seed(ApprovalFlowReviewSeeder::class);
        $this->seed(ApprovalFlowReviewSeeder::class);   // idempotent

        $tenant = Tenant::where('slug', ApprovalFlowReviewSeeder::TENANT_SLUG)->sole();
        app(CurrentTenant::class)->set($tenant);

        $permits = PermitToWork::orderBy('id')->get();
        $this->assertSame(['submitted', 'submitted', 'approved', 'submitted', 'submitted'], $permits->pluck('status')->all());
        $this->assertSame(0, $permits[3]->approvals()->count(), 'Yard Dua stays on the standard gates.');

        $versionOf = fn (PermitToWork $permit) => ApprovalFlowVersion::withoutGlobalScopes()->find($permit->approvals()->value('approval_flow_version_id'))->version;
        $this->assertSame([1, 1, 1, 2], [$versionOf($permits[0]), $versionOf($permits[1]), $versionOf($permits[2]), $versionOf($permits[4])]);

        // Waiting for Final Verification: Hendra approved stage 2, Hana did not.
        $engine = app(ApprovalEngine::class);
        $atFinal = $permits[1]->approvals()->where('status', Approval::STATUS_PENDING)->get();
        $atFinal->each->setRelation('approvable', $permits[1]);
        $hendra = User::where('email', 'flow.hse1@ioms.local')->sole();
        $hana = User::where('email', 'flow.hse2@ioms.local')->sole();
        $this->assertFalse($atFinal->contains(fn ($a) => $engine->authorize($a, $hendra)));
        $this->assertTrue($atFinal->contains(fn ($a) => $engine->authorize($a, $hana)));

        // D1: the Department User's task is theirs to open.
        $dept = User::where('email', 'flow.dept@ioms.local')->sole();
        $this->actingAs($dept)->get(route('my-work'))->assertOk();
    }
}
