<?php

namespace App\Services\Approvals;

use App\Models\ApprovalFlowStep;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * v2.141.0 -- A FLOW IS CHECKED BEFORE IT IS PUBLISHED.
 *
 * The visual editor can draw anything; this decides what may be saved, on
 * the server, for every way a flow can be written (the Settings endpoint,
 * a seeder, a test). Structure first, then the PTW rules the owner set:
 *
 *   - steps are numbered 1..N with no gaps (the engine advances to N+1, so
 *     a gap silently finalised a request early);
 *   - every row of a step shares its mode and kind; a `single` step has
 *     exactly one row;
 *   - every row names exactly one of: role, position, person;
 *   - Final Verification exists only on PTW flows, and a PTW flow ENDS with
 *     exactly one Final Verification stage after at least one approval
 *     stage (Created -> Approval 1..N -> Final Verification -> Active);
 *   - A8b: at least one person must be able to verify without having
 *     requested the permit or approved an earlier stage of it. Configured
 *     so that nobody could, the flow is REFUSED with the reason.
 */
class ApprovalFlowValidator
{
    public const PTW_MODULE = 'permit_to_work';

    public function __construct(
        private ApprovalCandidates $candidates,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $steps
     *
     * @throws ValidationException
     */
    public function assertValid(string $moduleKey, array $steps, ?int $tenantId): void
    {
        $errors = $this->errors($moduleKey, $steps, $tenantId);

        if ($errors !== []) {
            throw ValidationException::withMessages(['steps' => $errors]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return list<string>
     */
    public function errors(string $moduleKey, array $steps, ?int $tenantId): array
    {
        $isPtw = $moduleKey === self::PTW_MODULE;
        $rows = collect($steps)->map(fn (array $step) => (new ApprovalFlowStep)->forceFill([
            'step_number' => (int) ($step['step_number'] ?? 0),
            'kind' => $step['kind'] ?? ApprovalFlowStep::KIND_APPROVAL,
            'mode' => $step['mode'] ?? ApprovalFlowStep::MODE_SINGLE,
            'approver_role' => $step['approver_role'] ?? null,
            'approver_position_id' => $step['approver_position_id'] ?? null,
            'approver_user_id' => $step['approver_user_id'] ?? null,
        ]));

        if ($rows->isEmpty()) {
            return $isPtw ? ['Alur PTW memerlukan minimal satu tahap Approval dan satu tahap Final Verification.'] : [];
        }

        $errors = [];
        $byStep = $rows->groupBy('step_number')->sortKeys();
        $numbers = $byStep->keys()->map(fn ($n) => (int) $n)->values()->all();

        if ($numbers !== range(1, count($numbers))) {
            $errors[] = 'Nomor tahap harus berurutan mulai dari 1 tanpa lompatan (saat ini: '.implode(', ', $numbers).').';
        }

        foreach ($byStep as $number => $group) {
            if ($group->pluck('mode')->unique()->count() > 1) {
                $errors[] = "Tahap {$number}: semua penyetuju dalam satu tahap harus memakai mode yang sama.";
            }
            if ($group->pluck('kind')->unique()->count() > 1) {
                $errors[] = "Tahap {$number}: satu tahap tidak boleh mencampur Approval dan Final Verification.";
            }
            if ($group->first()->mode === ApprovalFlowStep::MODE_SINGLE && $group->count() > 1) {
                $errors[] = "Tahap {$number}: mode Single hanya untuk satu penyetuju. Gunakan Parallel untuk beberapa penyetuju.";
            }
            foreach ($group as $row) {
                $named = array_filter([$row->approver_role, $row->approver_position_id, $row->approver_user_id]);
                if (count($named) !== 1) {
                    $errors[] = "Tahap {$number}: setiap penyetuju harus berupa tepat satu role, posisi, atau orang.";
                    break;
                }
            }
        }

        $finalNumbers = $byStep->filter(fn ($group) => $group->first()->isFinalVerification())->keys()->map(fn ($n) => (int) $n)->values();

        if (! $isPtw) {
            if ($finalNumbers->isNotEmpty()) {
                $errors[] = 'Final Verification saat ini hanya tersedia untuk alur Permit To Work.';
            }

            return $errors;
        }

        // ---- PTW: Created -> Approval 1..N -> Final Verification -> Active
        $last = max($numbers);
        if ($finalNumbers->count() !== 1 || $finalNumbers->first() !== $last) {
            $errors[] = 'Alur PTW harus diakhiri tepat satu tahap Final Verification sebagai tahap terakhir sebelum PTW Active.';

            return $errors;
        }
        if ($last < 2) {
            $errors[] = 'Alur PTW memerlukan minimal satu tahap Approval sebelum Final Verification.';

            return $errors;
        }
        if ($errors !== []) {
            return $errors;
        }

        // Every stage must name somebody who exists.
        foreach ($byStep as $number => $group) {
            if ($this->candidates->forRows($group, $tenantId)->isEmpty()) {
                $errors[] = "Tahap {$number}: tidak ada pengguna aktif yang memegang role, posisi, atau identitas yang dipilih.";
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        return array_merge($errors, $this->separationOfDutiesErrors($byStep, $last, $tenantId));
    }

    /**
     * A8b at configuration time. Who will CERTAINLY have approved an earlier
     * stage is known only where a stage can be decided by exactly one
     * person; those people are removed from the verifiers. If nobody is
     * left, or the only one left could also be the requester, there is a
     * permit this flow could never verify -- refuse it, saying why.
     *
     * @param  Collection<int|string, Collection<int, ApprovalFlowStep>>  $byStep
     * @return list<string>
     */
    private function separationOfDutiesErrors(Collection $byStep, int $last, ?int $tenantId): array
    {
        $verifiers = $this->candidates->forRows($byStep->get($last), $tenantId);

        $certainApprovers = collect();
        foreach ($byStep as $number => $group) {
            if ((int) $number === $last) {
                continue;
            }
            $mode = $group->first()->mode;
            if ($mode === ApprovalFlowStep::MODE_PARALLEL_ALL) {
                foreach ($group as $row) {
                    $named = $this->candidates->forStep($row, $tenantId);
                    if ($named->count() === 1) {
                        $certainApprovers = $certainApprovers->union($named);
                    }
                }
            } else {
                $named = $this->candidates->forRows($group, $tenantId);
                if ($named->count() === 1) {
                    $certainApprovers = $certainApprovers->union($named);
                }
            }
        }

        $eligible = $verifiers->reject(fn (User $user) => $certainApprovers->has($user->id));

        if ($eligible->isEmpty()) {
            $names = $verifiers->pluck('name')->implode(', ');

            return ["Tidak ada Final Verifier yang memenuhi syarat: {$names} juga pasti menyetujui tahap sebelumnya, padahal orang yang sudah menyetujui tidak boleh menjadi Final Verifier PTW yang sama. Tambahkan penyetuju lain pada tahap sebelumnya atau Final Verifier lain."];
        }

        if ($eligible->count() === 1) {
            $only = $eligible->first();
            if ($only->canCreatePtw()) {
                return ["Hanya {$only->name} yang dapat menjadi Final Verifier, padahal ia juga dapat mengajukan PTW. PTW yang ia ajukan sendiri tidak akan pernah bisa diverifikasi. Tambahkan minimal satu Final Verifier lain."];
            }
        }

        return [];
    }
}
