<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Universal Approval Engine (v1.6.9). Genuinely generic -- these two
 * actions work against the `Approval` record itself (already knows its
 * own `approvable` polymorphic target), not against a specific module's
 * routes. A future PPE Replacement Request, Permit To Work, Purchase
 * Request, Asset Request, or Inspection approval goes through this exact
 * same controller with zero new code here.
 */
class ApprovalController extends Controller
{
    public function approve(Request $request, Approval $approval): RedirectResponse
    {
        $this->authorizeDecision($request, $approval);

        $request->validate(['comments' => ['nullable', 'string', 'max:1000']]);
        $comments = $request->input('comments');

        $approval->approve($request->user(), $comments);

        // The approvable model's own status transition -- reuses
        // HasWorkflow's validation and single ActivityLog entry, rather
        // than a raw update() that would bypass both.
        $approval->approvable->transitionTo(
            $approval->approvable::STATUS_APPROVED,
            $request->user(),
            null,
            ['comments' => $comments]
        );

        return back()->with('flash', ['success' => 'Approved.']);
    }

    public function reject(Request $request, Approval $approval): RedirectResponse
    {
        $this->authorizeDecision($request, $approval);

        $request->validate(['comments' => ['required', 'string', 'max:1000']]);
        $comments = $request->input('comments');

        $approval->reject($request->user(), $comments);
        $approval->approvable->transitionTo(
            $approval->approvable::STATUS_REJECTED,
            $request->user(),
            null,
            ['comments' => $comments]
        );

        return back()->with('flash', ['success' => 'Rejected.']);
    }

    /**
     * Reads from config/workflow.php's 'approvers' list rather than a
     * hardcoded role check -- Super Admin can always decide (an
     * overrider is implicitly always an approver too), plus whoever else
     * is configured as an approver for any workflow-driven module, not
     * just Material Request.
     */
    private function authorizeDecision(Request $request, Approval $approval): void
    {
        $approvers = config('workflow.approvers', []);
        abort_unless($request->user()->isSuperAdmin() || in_array($request->user()->role, $approvers, true), 403);
        abort_unless($approval->status === Approval::STATUS_PENDING, 422, 'This approval has already been decided.');
    }
}
