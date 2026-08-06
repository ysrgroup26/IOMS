<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Milestone 3 (Notification Center). Deliberately thin -- the list itself
 * is shared via HandleInertiaRequests (so every page has it, matching
 * the existing `work_center`/`modules` shared-prop pattern), this
 * controller only handles the two write actions.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $service) {}

    public function markRead(Request $request, Notification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $this->service->markRead($notification);

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $this->service->markAllRead($request->user());

        return back();
    }
}
