<?php

namespace App\Support;

/**
 * v2.141.0 -- "THE SYSTEM DID THIS", in one place.
 *
 * Some state changes are made by IOMS itself, not by a person: a permit on its
 * company's flow becoming Active at its start time (ADR 083). That can happen
 * inside somebody's request -- the Final Verifier's, when the start time has
 * already passed -- and the activity log and the audit trail would otherwise
 * credit that person with an action they did not take.
 *
 * Code that acts as the system wraps the change in SystemActor::run(); the
 * activity log (ActivityLog::record) and the audit trail (RecordsChanges)
 * then record no actor, which their read paths render as "System".
 */
final class SystemActor
{
    private static int $depth = 0;

    public static function run(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
