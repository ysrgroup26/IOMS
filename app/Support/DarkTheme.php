<?php

namespace App\Support;

use App\Models\User;

/**
 * v2.139.0 -- WHO IS OFFERED MIDNIGHT FROSTED GLASS (ADR 082).
 *
 * The dark theme is a PILOT: offered to accounts whose organisation is on a
 * plan listed in `ioms.dark_theme.plans` (Starter only, until the owner
 * approves a wider rollout), and to nobody else. One answer, asked by both
 * places that need it -- the inline script in app.blade.php that applies a
 * theme before first paint, and the shared Inertia prop that decides
 * whether the top bar shows the switch -- so the two can never disagree
 * and a non-pilot account can never flash dark.
 *
 * It decides only what is OFFERED. It grants nothing, and no route,
 * permission or entitlement reads it.
 */
class DarkTheme
{
    public static function availableTo(?User $user): bool
    {
        if (! $user || $user->tenant_id === null) {
            return false;
        }

        $plans = (array) config('ioms.dark_theme.plans', []);

        if ($plans === []) {
            return false;
        }

        $slug = $user->tenant?->subscription?->package?->slug;

        return $slug !== null && in_array($slug, $plans, true);
    }
}
