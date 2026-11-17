<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * ADR 088 -- AN ORGANIZATION THAT STOPPED PAYING IS KEPT, NOT DELETED.
 *
 * After a period ends the organization has the grace window, then is read-only
 * with every record intact (ADR 033); signing in and paying restores it. That is
 * unchanged. This adds one further, reversible step for organizations nobody
 * has touched for a long time: ARCHIVED -- hidden from Master Admin's default
 * list, nothing removed, nothing blocked -- and un-archived automatically the
 * next time anybody from it signs in.
 *
 * Archive criteria (all of them): not the Sandbox; no deletion scheduled; its
 * subscription is lapsed and its period ended more than DORMANT_DAYS ago; and
 * no member has signed in for DORMANT_DAYS (or ever).
 *
 * Moving archived uploads to cold storage is a later step (it needs object
 * storage configured on the production host); rows are small, files are not.
 */
class TenantArchiveService
{
    public const DORMANT_DAYS = 365;

    public function archiveDormant(?Carbon $now = null): int
    {
        $now ??= now();
        $cutoff = $now->copy()->subDays(self::DORMANT_DAYS);
        $count = 0;

        $candidates = Tenant::whereNull('archived_at')->whereNull('deletion_scheduled_for')
            ->where(fn ($q) => $q->whereNull('is_demo')->orWhere('is_demo', false))
            ->with('subscription.package')->get();

        foreach ($candidates as $tenant) {
            $snap = $tenant->subscription?->stateSnapshot();
            if (($snap['lifecycle_state'] ?? null) !== 'lapsed') {
                continue;
            }
            $ended = $tenant->subscription->periodEndsAt();
            if (! $ended || $ended->gt($cutoff)) {
                continue;
            }
            $lastLogin = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->max('last_login_at');
            if ($lastLogin && Carbon::parse($lastLogin)->gt($cutoff)) {
                continue;
            }
            $tenant->forceFill(['archived_at' => $now])->save();
            ActivityLog::record('updated', "Tenant \"{$tenant->name}\" diarsipkan (tidak aktif ".self::DORMANT_DAYS.' hari; data utuh)', null, [
                'kind' => 'tenant_archived', 'tenant_id' => $tenant->id, 'automatic' => true,
            ]);
            $count++;
        }

        return $count;
    }

    /** The next sign-in from an archived organization brings it back. */
    public function restoreOnSignIn(User $user): void
    {
        if (! $user->tenant_id) {
            return;
        }
        $tenant = Tenant::find($user->tenant_id);
        if ($tenant?->archived_at) {
            $tenant->forceFill(['archived_at' => null])->save();
            ActivityLog::record('updated', "Tenant \"{$tenant->name}\" dipulihkan dari arsip karena ada yang masuk", null, [
                'kind' => 'tenant_unarchived', 'tenant_id' => $tenant->id, 'automatic' => true,
            ]);
        }
    }
}
