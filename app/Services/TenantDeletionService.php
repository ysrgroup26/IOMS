<?php

namespace App\Services;

use App\Mail\TenantDeletionNotice;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Models\TenantRegistration;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * ADR 087 -- A CUSTOMER'S DATA IS NEVER DELETED ON THE SPOT.
 *
 * The owner's rule (2026-11-17): customer data matters too much to remove at
 * will. Deleting an organization is therefore a SCHEDULE, not an act:
 *
 *  - schedule(): the organization is held for HOLD_DAYS (90). Its
 *    administrators are told at once, and reminded REMINDERS days before the
 *    date. Nothing is removed and nothing changes for them meanwhile.
 *  - cancel(): the hold is lifted, any time before the date.
 *  - processDue() (daily, `tenants:process-deletions`): sends due reminders and
 *    purges organizations whose date has passed.
 *
 * The ONE exception is a TEST organization -- one that never paid for anything:
 * no paid invoice and no settled payment, ever, and not the Sandbox. That can be
 * purged immediately by a platform admin who types its slug, because there is
 * no customer whose data it is. A registration that never became an
 * organization and never paid is purged the same way.
 *
 * Every step is in the Activity Log.
 */
class TenantDeletionService
{
    public const HOLD_DAYS = 90;

    /** Days before the date on which administrators are reminded. */
    public const REMINDERS = [30, 7, 1];

    public function __construct(private TenantPurgeService $purger) {}

    public function neverPaid(Tenant $tenant): bool
    {
        $paidInvoice = Invoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->where('status', Invoice::STATUS_PAID)->orWhereNotNull('payment_date'))
            ->exists();
        $settled = PaymentTransaction::query()
            ->whereHas('invoice', fn ($q) => $q->withoutGlobalScopes()->where('tenant_id', $tenant->id))
            ->where('status', PaymentTransaction::STATUS_PAID)
            ->exists();

        return ! $paidInvoice && ! $settled;
    }

    public function canPurgeNow(Tenant $tenant): bool
    {
        return ! $tenant->is_demo && $this->neverPaid($tenant);
    }

    /** @return array<string,int> */
    public function purgeTestTenant(Tenant $tenant, string $typedSlug, ?User $actor): array
    {
        if ($typedSlug !== $tenant->slug) {
            throw new InvalidArgumentException('Ketik slug tenant persis untuk mengonfirmasi.');
        }
        if (! $this->canPurgeNow($tenant)) {
            throw new InvalidArgumentException('Tenant ini pernah membayar atau adalah Sandbox: penghapusan harus dijadwalkan (ditahan 90 hari).');
        }
        $name = $tenant->name;
        $counts = $this->purger->purgeTenant($tenant);
        ActivityLog::record('deleted', "Tenant uji \"{$name}\" ({$typedSlug}) dihapus permanen", null, [
            'kind' => 'tenant_purge', 'mode' => 'test_immediate', 'rows' => $counts, 'by' => $actor?->id,
        ]);

        return $counts;
    }

    /** @return array<string,int> */
    public function purgeTestRegistration(TenantRegistration $registration, ?User $actor): array
    {
        if (! $this->registrationPurgeable($registration)) {
            throw new InvalidArgumentException('Pendaftaran ini sudah dibayar atau sudah menjadi organisasi: tidak bisa dihapus dari sini.');
        }
        $ref = $registration->reference;
        $counts = $this->purger->purgeRegistration($registration);
        ActivityLog::record('deleted', "Pendaftaran uji {$ref} dihapus permanen", null, [
            'kind' => 'registration_purge', 'rows' => $counts, 'by' => $actor?->id,
        ]);

        return $counts;
    }

    public function registrationPurgeable(TenantRegistration $r): bool
    {
        if ($r->tenant_id !== null || $r->paid_at !== null || $r->provisioned_at !== null
            || in_array($r->status, [TenantRegistration::STATUS_PAID, TenantRegistration::STATUS_PROVISIONED], true)) {
            return false;
        }

        return ! PaymentTransaction::query()
            ->whereHas('invoice', fn ($q) => $q->withoutGlobalScopes()->where('registration_id', $r->id)
                ->when($r->invoice_id, fn ($q) => $q->orWhere('id', $r->invoice_id)))
            ->where('status', PaymentTransaction::STATUS_PAID)
            ->exists();
    }

    public function schedule(Tenant $tenant, string $reason, ?User $actor, ?Carbon $now = null): void
    {
        if ($tenant->is_demo) {
            throw new InvalidArgumentException('Sandbox tidak bisa dijadwalkan untuk dihapus.');
        }
        if ($tenant->deletion_scheduled_for) {
            throw new InvalidArgumentException('Penghapusan tenant ini sudah dijadwalkan.');
        }
        $now ??= now();
        $tenant->forceFill([
            'deletion_scheduled_for' => $now->copy()->addDays(self::HOLD_DAYS)->startOfDay(),
            'deletion_requested_at' => $now,
            'deletion_requested_by' => $actor?->id,
            'deletion_reason' => mb_substr($reason, 0, 255),
            'deletion_notices' => [],
        ])->save();

        $this->notify($tenant, 'scheduled');
        ActivityLog::record('updated', "Penghapusan tenant \"{$tenant->name}\" dijadwalkan untuk {$tenant->deletion_scheduled_for->toDateString()} (ditahan ".self::HOLD_DAYS.' hari)', null, [
            'kind' => 'tenant_deletion_scheduled', 'tenant_id' => $tenant->id, 'reason' => $reason,
        ]);
    }

    public function cancel(Tenant $tenant, ?User $actor): void
    {
        if (! $tenant->deletion_scheduled_for) {
            return;
        }
        $tenant->forceFill([
            'deletion_scheduled_for' => null, 'deletion_requested_at' => null, 'deletion_requested_by' => null,
            'deletion_reason' => null, 'deletion_notices' => null,
        ])->save();
        $this->notify($tenant, 'cancelled');
        ActivityLog::record('updated', "Penghapusan tenant \"{$tenant->name}\" dibatalkan", null, [
            'kind' => 'tenant_deletion_cancelled', 'tenant_id' => $tenant->id, 'by' => $actor?->id,
        ]);
    }

    /** @return array{reminded:int, purged:int} */
    public function processDue(?Carbon $now = null): array
    {
        $now ??= now();
        $reminded = 0;
        $purged = 0;

        foreach (Tenant::whereNotNull('deletion_scheduled_for')->get() as $tenant) {
            if ($tenant->deletion_scheduled_for->lte($now)) {
                $name = $tenant->name;
                $counts = $this->purger->purgeTenant($tenant);
                ActivityLog::record('deleted', "Tenant \"{$name}\" dihapus setelah masa tahan ".self::HOLD_DAYS.' hari', null, [
                    'kind' => 'tenant_purge', 'mode' => 'after_hold', 'rows' => $counts, 'automatic' => true,
                ]);
                $purged++;

                continue;
            }
            $daysLeft = (int) ceil($now->diffInHours($tenant->deletion_scheduled_for, false) / 24);
            $sent = $tenant->deletion_notices ?? [];
            // Every mark already crossed and not yet sent. A late run sends ONE email
            // (the nearest mark) and records the others, never three at once.
            $due = array_values(array_filter(self::REMINDERS, fn ($m) => $daysLeft <= $m && ! in_array("d{$m}", $sent, true)));
            if ($due) {
                $this->notify($tenant, 'reminder', $daysLeft);
                $reminded++;
                $tenant->forceFill(['deletion_notices' => array_values(array_merge($sent, array_map(fn ($m) => "d{$m}", $due)))])->save();
            }
        }

        return ['reminded' => $reminded, 'purged' => $purged];
    }

    private function notify(Tenant $tenant, string $event, ?int $daysLeft = null): void
    {
        $admins = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->where('role', User::ROLE_SUPER_ADMIN)->where('is_active', true)->pluck('email');

        foreach ($admins as $email) {
            try {
                Mail::to($email)->send(new TenantDeletionNotice($tenant, $event, $daysLeft));
            } catch (Throwable $e) {
                // A mail failure never blocks the schedule; it is logged, and the next
                // run's reminder still goes out (ADR 071's rule for every Mailable).
                Log::warning('Tenant deletion notice failed', ['tenant' => $tenant->id, 'event' => $event, 'error' => $e->getMessage()]);
            }
        }
    }
}
