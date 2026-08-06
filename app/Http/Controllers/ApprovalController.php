<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Services\ApprovalEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Universal Approval Engine (v1.6.9; extended to v2 in Milestone 3).
 * Genuinely generic -- these two actions work against the `Approval`
 * record itself (already knows its own `approvable` polymorphic
 * target), not against a specific module's routes. Every actual decision
 * (single-step legacy, or multi-level/parallel/conditional) is delegated
 * to `App\Services\ApprovalEngine`, which is where the authorization and
 * chain-advancement logic actually lives now -- this controller stays a
 * thin HTTP adapter.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function approve(Request $request, Approval $approval): RedirectResponse
    {
        $this->authorizeDecision($request, $approval);

        $request->validate(['comments' => ['nullable', 'string', 'max:1000']]);

        $this->engine->decide($approval, $request->user(), Approval::STATUS_APPROVED, $request->input('comments'));

        return back()->with('flash', ['success' => 'Approved.']);
    }

    public function reject(Request $request, Approval $approval): RedirectResponse
    {
        $this->authorizeDecision($request, $approval);

        $request->validate(['comments' => ['required', 'string', 'max:1000']]);

        $this->engine->decide($approval, $request->user(), Approval::STATUS_REJECTED, $request->input('comments'));

        return back()->with('flash', ['success' => 'Rejected.']);
    }

    private function authorizeDecision(Request $request, Approval $approval): void
    {
        abort_unless($approval->status === Approval::STATUS_PENDING, 422, 'This approval has already been decided.');
        abort_unless($this->engine->authorize($approval, $request->user()), 403);
    }
}
