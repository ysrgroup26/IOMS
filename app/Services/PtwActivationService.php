<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\PermitToWork;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\SystemActor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * v2.141.0 -- A PERMIT BECOMES ACTIVE AT ITS START TIME, BY ITSELF (owner's
 * final rule, ADR 083).
 *
 *   Created -> Approval 1..N -> Final Verification -> (waits for start) -> Active
 *
 * After Final Verification a permit on its company's approval flow is
 * Approved: ready, and waiting for its scheduled start. Nobody activates it a
 * second time. This service makes it Active when the start time is reached:
 * at once if the start has already passed when the verification is given,
 * otherwise from the `ptw:activate-due` scheduler.
 *
 * ONLY PERMITS ON A FLOW. A permit on the standard gates (ADR 067) is never
 * activated by this service: its route stays exactly what it was, so
 * deploying this changes no permit already running.
 *
 * SAFE TO RUN TWICE. Each permit is re-read under a row lock and moved only
 * from Approved, so two scheduler runs (or a run racing a decision) produce
 * one activation; the scheduler also runs `withoutOverlapping`.
 *
 * TENANT-AWARE. The scheduler has no tenant (Known Pitfall, v2.141.0): every
 * tenant is visited with its own CurrentTenant set, and nothing is read
 * across tenants.
 *
 * Outcomes are named, so the command, the tests and the page agree:
 *   activated       moved to Active now
 *   not_due         Approved, start still in the future
 *   not_ready       approvals or the Final Verification are not complete
 *   window_passed   the end time passed before it could start; NOT activated
 *   not_flow        on the standard gates; never touched here
 *   not_approved    any other status (already active, cancelled, ...)
 */
class PtwActivationService
{
    public const ACTIVATED = 'activated';

    public const NOT_DUE = 'not_due';

    public const NOT_READY = 'not_ready';

    public const WINDOW_PASSED = 'window_passed';

    public const NOT_FLOW = 'not_flow';

    public const NOT_APPROVED = 'not_approved';

    public function __construct(private NotificationService $notifier) {}

    /** Whether this permit is on a flow and waiting for its start time. */
    public function awaitingStart(PermitToWork $permit, ?Carbon $now = null): bool
    {
        $now ??= now();

        return $permit->status === PermitToWork::STATUS_APPROVED
            && $permit->approvals()->exists()
            && $permit->start_datetime !== null
            && $permit->start_datetime->greaterThan($now);
    }

    public function activateIfDue(PermitToWork $permit, ?Carbon $now = null): string
    {
        $now ??= now();
        $activated = false;

        $outcome = DB::transaction(function () use ($permit, $now, &$activated) {
            $fresh = PermitToWork::withoutGlobalScopes()->whereKey($permit->getKey())->lockForUpdate()->first();
            if (! $fresh) {
                return self::NOT_APPROVED;
            }
            $permit->setRawAttributes($fresh->getAttributes(), true);

            if (! $permit->approvals()->exists()) {
                return self::NOT_FLOW;
            }
            if ($permit->status !== PermitToWork::STATUS_APPROVED) {
                return self::NOT_APPROVED;
            }

            // Every requirement is checked again, not inferred from the status.
            $stillPending = $permit->approvals()->where('status', Approval::STATUS_PENDING)->exists();
            if ($stillPending || ! $permit->hse_verifier_id) {
                return self::NOT_READY;
            }
            if ($permit->start_datetime === null || $permit->start_datetime->greaterThan($now)) {
                return self::NOT_DUE;
            }
            if ($permit->end_datetime !== null && $permit->end_datetime->lessThanOrEqualTo($now)) {
                return self::WINDOW_PASSED;
            }

            $late = $permit->start_datetime->diffInMinutes($now) > 5;
            SystemActor::run(fn () => $permit->transitionTo(
                PermitToWork::STATUS_ACTIVE,
                null,
                $permit->documentLabel().' '.$permit->ptw_number.' became active automatically at its start time'
                    .($late ? ' (scheduled '.$permit->start_datetime->format('d M Y H:i').', activated '.$now->format('d M Y H:i').')' : '').'.',
                ['automatic' => true, 'scheduled_start' => $permit->start_datetime->toIso8601String()],
            ));
            $activated = true;

            return self::ACTIVATED;
        });

        if ($activated) {
            $this->tellTheCrew($permit);
        } elseif ($outcome === self::WINDOW_PASSED) {
            $this->tellWindowPassed($permit);
        }

        return $outcome;
    }

    /**
     * The scheduler's pass: every tenant, in its own context.
     *
     * @return array<string, int> outcome => count
     */
    public function activateDue(?Carbon $now = null): array
    {
        $now ??= now();
        $counts = [];
        $current = app(CurrentTenant::class);
        $previous = $current->get();

        try {
            foreach (Tenant::query()->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->get() as $tenant) {
                $current->set($tenant);

                PermitToWork::query()
                    ->where('status', PermitToWork::STATUS_APPROVED)
                    ->where('start_datetime', '<=', $now)
                    ->whereHas('approvals')
                    ->orderBy('id')
                    ->chunkById(100, function ($permits) use ($now, &$counts) {
                        foreach ($permits as $permit) {
                            try {
                                $outcome = $this->activateIfDue($permit, $now);
                            } catch (Throwable $e) {
                                // One permit failing never stops the others.
                                report($e);
                                $outcome = 'error';
                            }
                            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                        }
                    });
            }
        } finally {
            $current->set($previous);
        }

        return $counts;
    }

    /**
     * The requester is told by the transition itself; the PIC and the Final
     * Verifier are told here -- they are the people who need to know the work
     * may start. After the commit, and never able to undo it.
     */
    private function tellTheCrew(PermitToWork $permit): void
    {
        try {
            $people = collect([
                $permit->hse_verifier_id ? User::query()->find($permit->hse_verifier_id) : null,
                $permit->pic_employee_id ? User::query()->where('employee_id', $permit->pic_employee_id)->where('is_active', true)->first() : null,
            ])->filter()->unique('id')->reject(fn (User $user) => (int) $user->id === (int) $permit->requested_by);

            foreach ($people as $user) {
                $this->notifier->send(
                    'ptw.activated',
                    $user,
                    'Permit is now active',
                    $permit->ptw_number.' sekarang ACTIVE sesuai waktu mulai. Pekerjaan boleh dimulai.',
                    $permit->notificationUrl(),
                    $permit,
                    array_filter(['route' => $permit->notificationRouteName()]),
                    dedupeKey: 'ptw.activated.'.$permit->getKey(),
                );
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Once per permit: its time window closed before it could become active. */
    private function tellWindowPassed(PermitToWork $permit): void
    {
        try {
            $requester = $permit->requested_by ? User::query()->find($permit->requested_by) : null;
            if (! $requester) {
                return;
            }

            $this->notifier->send(
                'ptw.window_passed',
                $requester,
                'Permit time window has passed',
                $permit->ptw_number.' tidak diaktifkan karena waktu selesainya sudah lewat sebelum izin dapat aktif. Ajukan PTW baru bila pekerjaan masih diperlukan.',
                $permit->notificationUrl(),
                $permit,
                array_filter(['route' => $permit->notificationRouteName()]),
                dedupeKey: 'ptw.window_passed.'.$permit->getKey(),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
