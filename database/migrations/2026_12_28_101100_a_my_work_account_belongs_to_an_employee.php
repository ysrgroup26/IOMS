<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.123.0 -- A MY WORK ACCOUNT IS AN EMPLOYEE'S LOGIN, NOT A SECOND RECORD
 * OF THEM.
 *
 * Until now the only way to create a My Work account was the Platform User
 * form: type a name, type an email, type a password, pick a role and a
 * department. Every one of those facts already exists in the Employee
 * Register for the person the account is for, and retyping them produces a
 * second, diverging copy of somebody who is already in the system -- with
 * no link back, so nothing can answer "does this worker have a login?".
 *
 * THREE COLUMNS, AND WHY EACH ONE.
 *
 *   employee_id          the link to the register. UNIQUE, which is how
 *                        "one login per worker" is enforced by the
 *                        database rather than by remembering to check.
 *                        NULL for every Platform User, now and always;
 *                        MySQL and SQLite both allow many NULLs under a
 *                        unique index, which is exactly the shape needed.
 *
 *   access_code_set_at   when the access code was last issued or reset.
 *                        An administrator who has just reset a code needs
 *                        to see that it happened; the code itself is never
 *                        stored, so this is the only trace.
 *
 *   email becomes NULLABLE   a yard worker may have no work email at all,
 *                        and inventing one (emp-0001@localhost) would put
 *                        a fiction in the column every notification reads.
 *                        The unique index stays: two accounts still cannot
 *                        share an address, and NULL is not an address.
 *
 * WHAT IS DELIBERATELY NOT HERE: a new credential column. The access code
 * is stored in `users.password`, hashed by the model's existing `hashed`
 * cast, verified by the existing guard. A My Work account is an account;
 * giving it a parallel secret store would mean two things to rotate, two
 * things to hash correctly, and two places for a plain-text leak. See
 * `docs/ADR/062-a-my-work-account-is-an-employees-login.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * nullOnDelete rather than cascade or restrict: erasing an
             * employee is answered by EmployeeEraser (ADR 055), which
             * deactivates the account and clears this pointer in
             * application code so MySQL and SQLite behave identically.
             * The database-level rule is the safety net under that, and
             * it must not silently delete a row the eraser is accounting
             * for.
             */
            $table->foreignId('employee_id')->nullable()->after('company_id')
                ->constrained('employees')->nullOnDelete();

            $table->timestamp('access_code_set_at')->nullable()->after('last_login_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('employee_id', 'users_employee_id_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Reversed in the opposite order, and the email column is only
         * made NOT NULL again when nothing would be lost by it -- an
         * account created without an address cannot be given one by a
         * rollback, and failing loudly is better than writing a fiction.
         */
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_employee_id_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn('access_code_set_at');
        });

        if (! \Illuminate\Support\Facades\DB::table('users')->whereNull('email')->exists()) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->nullable(false)->change();
            });
        }
    }
};
