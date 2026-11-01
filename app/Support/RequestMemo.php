<?php

namespace App\Support;

use Closure;

/**
 * v2.141.0 -- ANSWER A QUESTION ONCE PER REQUEST.
 *
 * Measured on 2026-11-01: every page ran 60-70 queries before doing its own
 * work, 32 of them cache reads of company settings (logo, name, favicon ...)
 * read one at a time, and the same entitlement questions asked four or five
 * times. This remembers an answer for the rest of ONE request.
 *
 * It is ACTIVE ONLY BETWEEN `begin()` AND `end()`, which the
 * `RequestMemoScope` middleware calls around every HTTP request. Outside a
 * request -- the queue worker (a long-running process), scheduled commands,
 * a service called directly in a test -- `remember()` simply runs the
 * closure, so nothing can serve an answer that went stale in another request.
 * Code that changes an answer mid-request calls `forget()` or `flush()`.
 */
class RequestMemo
{
    private static bool $active = false;

    /** @var array<string, mixed> */
    private static array $values = [];

    public static function begin(): void
    {
        self::$values = [];
        self::$active = true;
    }

    public static function end(): void
    {
        self::$values = [];
        self::$active = false;
    }

    public static function remember(string $key, Closure $resolve): mixed
    {
        if (! self::$active) {
            return $resolve();
        }

        if (! array_key_exists($key, self::$values)) {
            self::$values[$key] = $resolve();
        }

        return self::$values[$key];
    }

    public static function forget(string $key): void
    {
        unset(self::$values[$key]);
    }

    public static function flush(): void
    {
        self::$values = [];
    }
}
