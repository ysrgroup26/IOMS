<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Services\Notifications\WebPushService;
use App\Services\WorkCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * v2.129.0 -- WHAT HAS HAPPENED SINCE THE LAST THING THIS TAB SAW.
 *
 * -----------------------------------------------------------------------------
 * THE CONTRACT
 * -----------------------------------------------------------------------------
 *
 * One endpoint, one question, answered with a CURSOR rather than a snapshot:
 * "here is the id of the newest notification I know about -- what is newer?"
 *
 * The cursor is what makes a dropped connection harmless. A client that was
 * asleep, offline, or on a train for ten minutes asks with the same cursor it
 * had and receives everything it missed, in order, exactly once. Nothing is
 * lost because the rows were never in flight -- they were written to the
 * table before anybody was told -- and nothing is doubled because the client
 * only ever advances its cursor past rows it has actually received.
 *
 * That property is the reason this is a cursor endpoint and not a "give me
 * the latest ten" endpoint, which cannot tell a client what it missed.
 *
 * -----------------------------------------------------------------------------
 * WHY THIS AND NOT A WEBSOCKET
 * -----------------------------------------------------------------------------
 *
 * IOMS deploys to cPanel shared hosting: no long-running process, which is
 * also why Inertia SSR is off in this product. Reverb cannot run there, and a
 * hosted socket provider is a paid third-party dependency with credentials
 * the owner would have to create -- for an experience this endpoint already
 * delivers. The client asks every few seconds while the tab is VISIBLE and
 * not at all while it is hidden, because the hidden case belongs to web push.
 * See ADR 069, which records what was evaluated and why.
 *
 * -----------------------------------------------------------------------------
 * WHAT IT DELIBERATELY DOES NOT DO
 * -----------------------------------------------------------------------------
 *
 * It does not report the state of any RECORD. A permit's status on an open
 * page is refreshed by Inertia's own partial reload, which re-runs the
 * controller and therefore every tenant scope, every entitlement check and
 * every `assertInCurrentTenant()` that controller performs. An endpoint that
 * reported record state would be a second, thinner path to the same data --
 * and a second path is where isolation defects live. This one returns
 * notifications belonging to the authenticated user and nothing else.
 */
class NotificationStreamController extends Controller
{
    /** How many missed notifications one response will carry. */
    private const MAX_ITEMS = 25;

    /**
     * v2.129.0 -- WHAT A PAGE MAY ASK TO BE TOLD HAS CHANGED.
     *
     * A tab showing one record wants to follow it even when the viewer is not
     * a RECIPIENT of the notification that moved it -- an approver who has
     * just approved a permit is told nothing when HSE then activates it, and
     * before this their open page sat on a stale status with a stale set of
     * buttons.
     *
     * So a page may name the record it is showing and receive a CHANGE
     * MARKER: an opaque number that moves when something happens to it, and
     * says nothing whatever about what. The state still arrives only through
     * Inertia's partial reload, which re-runs the controller and every check
     * in it. This endpoint never learns to describe a record.
     *
     * An ALLOW-LIST, not a free morph type, because the alternative is an
     * endpoint that reports activity timing for any table an attacker cares
     * to name. Adding a module to the real-time foundation is one line here
     * plus one `useWatchedRecord()` call on its page (ADR 069).
     */
    private const WATCHABLE = [
        'PermitToWork' => \App\Models\PermitToWork::class,
    ];

    /** A tab shows a handful of records at most; more than this is probing. */
    private const MAX_WATCHED = 5;

    public function __construct(private readonly WorkCenterService $workCenter) {}

    /**
     * The delta since `cursor`.
     *
     * `cursor` is a notification id, never a timestamp: ids are assigned by
     * the database in commit order, so "greater than" cannot straddle two
     * rows written in the same second the way a timestamp comparison can.
     */
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:0'],
            // "Type:id,Type:id" -- see WATCHABLE.
            'watch' => ['nullable', 'string', 'max:200'],
        ]);

        /*
         * ABSENT IS NOT ZERO, and the difference is a defect this contract
         * had until a test caught it.
         *
         * A FIRST call -- no `cursor` parameter at all -- must not replay a
         * person's history as "new": it would toast and chime through
         * everything they have ever been sent, every time a tab opened. So
         * it answers with the counts and the id to start from, and no items.
         *
         * `cursor=0` is a different statement: "I have seen nothing; tell me
         * about anything." It is what a tab sends after being told the cursor
         * is 0, which is what EVERY ACCOUNT WITH NO NOTIFICATIONS YET is
         * told. Reading the two as the same thing meant such an account
         * asked with 0 forever and was never sent anything -- the people most
         * likely to be new to the product were the ones live notifications
         * silently did not work for.
         */
        $first = ! $request->has('cursor');
        $cursor = (int) ($validated['cursor'] ?? 0);

        $query = Notification::where('user_id', $user->id);

        $items = $first
            ? collect()
            : $query->clone()
                ->where('id', '>', $cursor)
                ->orderByDesc('id')
                ->limit(self::MAX_ITEMS)
                ->get(['id', 'category', 'title', 'body', 'url', 'read_at', 'created_at', 'meta']);

        $newest = (int) $query->clone()->max('id');

        /*
         * WHAT THE SERVICE WORKER NEEDS, in the same response.
         *
         * Woken by a payloadless push, the worker has to show SOMETHING and
         * has no state of its own to show it from. It could ask twice --
         * once for the cursor, once for the row behind it -- but a woken
         * worker is on a cold start with a short lifetime and a metered
         * connection, and two round trips there is one too many.
         *
         * So the newest notification travels as `latest`, for DISPLAY only:
         * it is not an item, it does not advance a cursor, and the live-sync
         * client ignores it (the page's own props already carry the list).
         * That keeps the first-call contract intact -- a tab opening does
         * not toast and chime through a person's history.
         */
        $latest = $query->clone()->orderByDesc('id')->first(['id', 'title', 'body', 'url', 'notifiable_type', 'notifiable_id']);

        return response()->json([
            // Where to ask from next time. The newest row that EXISTS, not
            // the newest row returned: a person with 200 missed
            // notifications gets the most recent 25 and a cursor that does
            // not promise the other 175 are coming -- they are in the
            // Notification Center, which is the record.
            'cursor' => max($newest, $cursor),
            'unread_count' => (int) $query->clone()->unread()->count(),
            // Not a notification row: a live computed count the bell shows
            // beside them. Carried here so the badge is one number from one
            // request rather than two.
            'ppe_alert_count' => $this->workCenter->ppeAlertCount(),
            'items' => $items->map(fn (Notification $n) => [
                ...$n->only(['id', 'category', 'title', 'body', 'url', 'read_at', 'created_at']),
                /*
                 * The same `route_prefix` the shared Inertia prop carries,
                 * built the same way, so a row that arrives live is offered
                 * on exactly the terms a row that arrived with the page is
                 * (ADR 061). A client that had to guess would offer links
                 * the server answers 403 for.
                 */
                'route_prefix' => is_string($n->meta['route'] ?? null)
                    ? explode('.', $n->meta['route'])[0]
                    : null,
                // Lets an open page recognise a notification about the
                // record it is showing, and ask Inertia to refresh just
                // that record's props. Type and id only.
                'subject' => $n->notifiable_type && $n->notifiable_id
                    ? ['type' => class_basename($n->notifiable_type), 'id' => (int) $n->notifiable_id]
                    : null,
            ])->values(),
            'markers' => $this->markersFor($validated['watch'] ?? null),
            'latest' => $latest === null ? null : [
                'id' => $latest->id,
                'title' => $latest->title,
                'body' => $latest->body,
                'url' => $latest->url,
                'subject' => $latest->notifiable_type && $latest->notifiable_id
                    ? ['type' => class_basename($latest->notifiable_type), 'id' => (int) $latest->notifiable_id]
                    : null,
            ],
        ]);
    }

    /**
     * A change marker per watched record: the id of its most recent activity.
     *
     * WHAT THIS DELIBERATELY IS NOT: a description. It is one integer. A
     * caller learns that a record they named has had something happen to it,
     * which is exactly what a page with that record open needs in order to
     * ask Inertia for a refresh, and is the least that can be said.
     *
     * SCOPED BY COMPANY, which is the part that matters. The query is
     * restricted to activity rows belonging to companies THIS TENANT owns
     * (`Company::query()` runs through TenantScope), so naming another
     * customer's permit returns null -- not a marker, not a zero, nothing
     * that distinguishes "no activity" from "not yours". Within a tenant a
     * marker reveals that a permit moved, which any account that may open the
     * PTW register could already see by opening it.
     *
     * @return array<string, int>
     */
    private function markersFor(?string $watch): array
    {
        if ($watch === null || trim($watch) === '') {
            return [];
        }

        $requested = collect(explode(',', $watch))
            ->map(fn ($entry) => trim($entry))
            ->filter()
            ->take(self::MAX_WATCHED);

        if ($requested->isEmpty()) {
            return [];
        }

        $companyIds = Company::query()->pluck('id');

        $markers = [];

        foreach ($requested as $entry) {
            [$type, $id] = array_pad(explode(':', $entry, 2), 2, null);

            $class = self::WATCHABLE[$type] ?? null;

            if ($class === null || ! is_numeric($id)) {
                continue;
            }

            $markers[$entry] = (int) ActivityLog::where('subject_type', $class)
                ->where('subject_id', (int) $id)
                ->whereIn('company_id', $companyIds)
                ->max('id');
        }

        return $markers;
    }

    /**
     * Mark one notification read, answering JSON.
     *
     * The existing `notifications.read` route answers a redirect, which is
     * right for a click that also navigates. This one is for the bell
     * marking a row read IN PLACE -- a redirect there would reload the page
     * the person is reading, which is the behaviour this release exists to
     * remove.
     */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        // Not `abort_unless(... === $user->id)` with a 403: a notification
        // belonging to somebody else is not a thing this account may know
        // exists. Same reasoning as the 404-not-403 pattern used for
        // tenant-scoped records.
        abort_if($notification->user_id !== $request->user()->id, 404);

        if (! $notification->isRead()) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json([
            'unread_count' => Notification::where('user_id', $request->user()->id)->unread()->count(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        Notification::where('user_id', $request->user()->id)->unread()->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }

    /*
    |--------------------------------------------------------------------------
    | Web push subscriptions
    |--------------------------------------------------------------------------
    */

    /**
     * Remember this browser as somewhere to reach this person.
     *
     * Idempotent by endpoint: a browser that re-subscribes (which happens
     * on its own, when a push service rotates an endpoint) presents the same
     * address and updates its row. Without that, every rotation would add a
     * duplicate and one notification would ring a phone twice.
     *
     * The endpoint is whatever the browser's push service issued and is NOT
     * validated against a list of known hosts: Chrome, Firefox, Safari and
     * every Chromium fork use different ones, and an allow-list here would
     * silently stop working for a browser nobody tested. It is only ever
     * used as a POST target by the server, never rendered.
     */
    public function subscribe(Request $request, WebPushService $push): JsonResponse
    {
        abort_unless($push->isConfigured(), 503, 'Push notifications are not configured on this installation.');

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'url'],
            'keys.p256dh' => ['nullable', 'string', 'max:255'],
            'keys.auth' => ['nullable', 'string', 'max:255'],
        ]);

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashFor($validated['endpoint'])],
            [
                // The owner is re-asserted on every update: an endpoint
                // re-presented by a DIFFERENT account on a shared device
                // must move with the person now holding it, not keep
                // notifying the last one.
                'user_id' => $request->user()->id,
                'endpoint' => $validated['endpoint'],
                'p256dh' => $validated['keys']['p256dh'] ?? null,
                'auth' => $validated['keys']['auth'] ?? null,
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ],
        );

        return response()->json(['subscribed' => true, 'id' => $subscription->id]);
    }

    /**
     * Forget this browser.
     *
     * Scoped to the authenticated user, so posting somebody else's endpoint
     * cannot unsubscribe their phone.
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashFor($validated['endpoint']))
            ->delete();

        return response()->json(['subscribed' => false]);
    }
}
