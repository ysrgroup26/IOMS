<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * v2.123.0 -- MY WORK ACCESS CODES, IN ONE PLACE.
 *
 * Creating a My Work account, issuing its access code, resetting that code
 * and resolving a sign-in from an Employee ID all have to agree about the
 * same three things: how a code is generated, how it is normalised, and
 * where it is stored. Spreading that across a controller, a login handler
 * and a settings page is how one of them ends up comparing a code the other
 * never wrote.
 *
 * THE CODE IS THE PASSWORD. It is stored in `users.password`, hashed by the
 * model's existing `hashed` cast, and verified by the existing guard. There
 * is no second credential column and no second hashing path: a My Work
 * account is an account, and a parallel secret store would be two things to
 * rotate, two things to hash correctly, and two places a plain-text leak
 * could come from. See ADR 062.
 *
 * THE ALPHABET IS DELIBERATELY SMALL. A code is read off a phone screen or
 * a printed slip and typed by somebody wearing gloves, so I, O, 0 and 1 are
 * absent: every character is unambiguous in both cases and in every font a
 * yard actually prints with. 8 characters over 32 symbols is 2^40 -- far
 * beyond what the login throttle allows anyone to explore, while still
 * being short enough to say out loud.
 */
class MyWorkAccessService
{
    /** No I, O, 0 or 1 -- see the class note. */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const CODE_LENGTH = 8;

    /**
     * How a code is written down, and how it is read back.
     *
     * Grouped in fours when shown ("K7MP-4RTX"), accepted in any casing
     * and with or without the separator, so a worker who types what they
     * see and a worker who types what they remember both get in.
     */
    public static function format(string $code): string
    {
        return implode('-', str_split(strtoupper($code), 4));
    }

    public static function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    /** A fresh code, formatted the way it is shown to the administrator. */
    public function generate(): string
    {
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return self::format($code);
    }

    /**
     * Create the My Work login for an employee who is already in the
     * register, and return it with the one plain-text copy of its code.
     *
     * The caller is responsible for authorization and for proving the
     * employee belongs to the acting administrator's tenant -- the request
     * class does both, and re-deriving it here would be a second, divergent
     * answer to a settled question.
     *
     * The quota is re-counted under a row lock inside the transaction, the
     * same shape SettingsController::storeUser() uses, so two concurrent
     * creations cannot both pass a stale count.
     *
     * @return array{user: User, code: string}
     */
    public function createFor(Employee $employee, User $administrator, ?string $email = null): array
    {
        $tenant = $administrator->tenant;
        $code = $this->generate();

        $user = DB::transaction(function () use ($employee, $administrator, $email, $code, $tenant) {
            User::where('tenant_id', $tenant?->id)->lockForUpdate()->get();

            /*
             * The duplicate guard, asked under the same lock. The unique
             * index on users.employee_id is the real boundary; this exists
             * so the administrator gets a sentence instead of an integrity
             * error, and it is asked WITHOUT the tenant scope because an
             * account belonging to another tenant would otherwise look
             * absent right up until the index refused it.
             */
            $existing = User::withoutGlobalScopes()->where('employee_id', $employee->id)->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'employee_id' => $existing->tenant_id === $tenant?->id
                        ? sprintf('%s sudah memiliki akun My Work. Kelola akun yang ada, atau reset kode aksesnya.', $employee->full_name)
                        : 'Karyawan ini sudah terhubung ke sebuah akun IOMS.',
                ]);
            }

            $entitlements = app(EntitlementService::class);

            if (! $entitlements->canCreateUserOfType($tenant, User::TYPE_MY_WORK)) {
                throw ValidationException::withMessages([
                    'employee_id' => sprintf(
                        'Kuota My Work User paket Anda sudah penuh (%d dari %d terpakai). Tambah kapasitas My Work sebelum membuat akun baru.',
                        $entitlements->myWorkUsersUsedCount($tenant),
                        (int) $entitlements->myWorkSeatLimit($tenant),
                    ),
                ]);
            }

            $user = new User([
                /*
                 * The register is the source of identity, so nothing here
                 * is typed twice. `name` is a copy taken at creation for
                 * the places that read a user's own name (the header, an
                 * audit entry); the LIVE name always comes from the
                 * employee relation, and sign-in resolves against
                 * `employees.full_name`, never against this copy -- so a
                 * rename in the register can never strand a worker.
                 */
                'name' => $employee->full_name,
                'email' => $email,
                'password' => self::normalise($code),
                /*
                 * A role is still stored because `role` is the live
                 * authorization column for the whole application and a
                 * NULL there would be a new, untested state. It is the
                 * NARROWEST one: RestrictMyWorkUser is what actually
                 * bounds this class, and this account is not being given
                 * HSE or administrative authority by the back door.
                 */
                'role' => User::ROLE_HRD,
                'user_type' => User::TYPE_MY_WORK,
                'is_field_user' => true,
                'tenant_id' => $tenant?->id,
                'company_id' => $employee->company_id,
                'is_active' => true,
                'access_code_set_at' => now(),
            ]);

            // Not mass-assignable on purpose -- see User::employee().
            $user->forceFill(['employee_id' => $employee->id]);
            $user->save();

            return $user;
        });

        ActivityLog::record(
            'created',
            "My Work access was created for {$employee->full_name} ({$employee->employee_id}).",
            $user
        );

        return ['user' => $user->fresh(), 'code' => $code];
    }

    /**
     * Issue a new access code, invalidating the old one.
     *
     * Returns the one plain-text copy. Nothing stores it: if the
     * administrator loses it before sharing it, the answer is another
     * reset, not a lookup, because there is nothing to look up.
     */
    public function resetCode(User $user): string
    {
        $code = $this->generate();

        $user->forceFill([
            // The model's `hashed` cast does the hashing, exactly as it
            // does for a Platform User's password -- one hashing path.
            'password' => self::normalise($code),
            'access_code_set_at' => now(),
            // Any browser still holding "remember me" for this account is
            // no longer entitled to it once the code is rotated.
            'remember_token' => null,
        ])->save();

        ActivityLog::record(
            'updated',
            'My Work access code was reset for '.($user->employee?->full_name ?? $user->displayName()).'.',
            $user
        );

        return $code;
    }
}
