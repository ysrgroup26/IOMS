<?php

namespace App\Support;

use Throwable;

/**
 * v2.68.0. Decides whether an exception's own message is fit to show a
 * user on the Errors/Show page, or whether that page should fall back to
 * its own copy for the status code.
 *
 * WHY THIS EXISTS. `bootstrap/app.php` forwarded `$exception->getMessage()`
 * verbatim for every non-500 status. Most of those messages are written by
 * the framework, not by this application, and they read like internals
 * because they are:
 *
 *     No query results for model [App\Models\Employee] 99999
 *     The route employees/99999 could not be found.
 *
 * The first was rendered to a signed-in user as the entire explanation of
 * a 404 -- it names an internal class and namespace, and tells the reader
 * nothing they can act on. Errors/Show already carries good fallback copy
 * for all seven statuses it handles; the message should only override that
 * when this codebase deliberately wrote one.
 *
 * This codebase authors messages on 403 only -- CheckRole,
 * RestrictDemoTenant and RestrictDepartmentAccess each explain something
 * the reader genuinely needs ("This page belongs to a different
 * department."). Every `abort(404)` in the app is bare on purpose, and
 * EmployeeCompetencyController's doc comment records why one of them is a
 * 404 rather than a 403 at all: not confirming whether a foreign id
 * exists. Suppressing framework 404 text keeps that intent intact instead
 * of quietly restating what was withheld.
 *
 * This is presentation only. It never changes a status code, never
 * decides access, and is not reachable from anywhere except the error
 * renderer.
 */
class ErrorMessagePresenter
{
    /**
     * Framework-generated message shapes that must never reach a reader.
     * Matched case-insensitively against the whole message.
     */
    private const FRAMEWORK_PATTERNS = [
        '/^No query results for model/i',
        '/^The route .* could not be found\.?$/i',
        '/^Route \[.*\] not defined/i',
        '/^Unauthenticated\.?$/i',
        '/^Server Error$/i',
        '/^Not Found$/i',
        '/^Forbidden$/i',
        '/^Too Many Attempts\.?$/i',
        '/^CSRF token mismatch\.?$/i',
        '/^Page Expired$/i',
    ];

    /**
     * The message Errors/Show should display, or null to use its own
     * per-status fallback copy.
     */
    public static function forStatus(int $status, Throwable $exception): ?string
    {
        // 500/503 never explain themselves to a user: the message is a
        // stack-trace artifact and may quote internal state.
        if (in_array($status, [500, 503], true)) {
            return null;
        }

        $message = trim($exception->getMessage());

        if ($message === '') {
            return null;
        }

        // A namespace separator only ever appears in a message the
        // framework built out of a class name.
        if (str_contains($message, '\\')) {
            return null;
        }

        foreach (self::FRAMEWORK_PATTERNS as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return null;
            }
        }

        return $message;
    }
}
