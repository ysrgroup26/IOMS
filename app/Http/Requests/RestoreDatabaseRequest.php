<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * v2.108.2 (SEC-010) -- THE GUARDS ON THE MOST DESTRUCTIVE ACTION IOMS HAS.
 *
 * Restoring replaces the database for EVERY tenant at once. There is no
 * undo, no per-tenant scope, and no partial version of it. So the request
 * has to clear four separate bars before the controller sees it:
 *
 *  1. THE CALLER IS A PLATFORM OPERATOR. `isPlatformAdmin()`, checked here
 *     as well as by the route's `role:platform_admin` middleware. Two
 *     checks for one rule is deliberate for this action: a route group is
 *     edited by hand and has been got wrong in this codebase before (a
 *     whole module was once nested in the wrong group), and the cost of
 *     this particular mistake is every customer's data.
 *
 *  2. THE WORD IS TYPED, and verified on the server. The browser dialog
 *     that used to be the only confirmation is not consent; it is a
 *     client-side courtesy anyone can skip.
 *
 *  3. THE FILE IS A SQL DUMP, by extension AND by looking at it. A `.sql`
 *     extension is a claim; the first bytes of the file are evidence.
 *
 *  4. THE OPERATOR NAMES THE DATABASE THEY ARE ABOUT TO REPLACE. Typing
 *     the target database's own name is the one check that distinguishes
 *     "restore this environment" from "restore the one I meant".
 */
class RestoreDatabaseRequest extends FormRequest
{
    /** Typed by hand, checked on the server. */
    public const CONFIRMATION = 'RESTORE';

    public function authorize(): bool
    {
        return (bool) $this->user()?->isPlatformAdmin();
    }

    public function rules(): array
    {
        return [
            'backup_file' => ['required', 'file', 'mimes:sql,txt', 'max:512000'],
            'confirmation' => ['required', 'string', 'in:'.self::CONFIRMATION],
            'database' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.in' => 'Ketik '.self::CONFIRMATION.' untuk memastikan pemulihan ini disengaja.',
            'backup_file.required' => 'Pilih berkas .sql hasil backup.',
            'backup_file.max' => 'Berkas backup melebihi batas ukuran yang diizinkan.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // The operator must name the database this deployment is
                // actually pointed at. Nothing else here can tell a staging
                // restore from a production one.
                if ($this->input('database') !== config('database.connections.mysql.database')) {
                    $validator->errors()->add(
                        'database',
                        'Nama database tidak cocok dengan database yang sedang digunakan deployment ini. Tidak ada data yang diubah.'
                    );

                    return;
                }

                /*
                 * A `.sql` extension is a claim about a file; this reads
                 * the file. A dump begins with SQL or with mysqldump's own
                 * banner comment, never with a ZIP/gzip/PE header -- which
                 * is what an archive or an executable renamed to .sql looks
                 * like, and what `mysql <` would then be asked to swallow.
                 */
                $head = (string) file_get_contents($this->file('backup_file')->getRealPath(), false, null, 0, 4096);

                $looksLikeSql = preg_match('/^\s*(--|\/\*|#|SET\b|CREATE\b|DROP\b|INSERT\b|USE\b|LOCK\b|ALTER\b|START\s+TRANSACTION\b)/i', $head) === 1;

                if (! $looksLikeSql) {
                    $validator->errors()->add(
                        'backup_file',
                        'Berkas ini tidak terbaca sebagai hasil mysqldump. Tidak ada data yang diubah.'
                    );
                }
            },
        ];
    }
}
