<?php

namespace App\Support;

use App\Http\Middleware\RestrictDemoTenant;
use App\Http\Middleware\RestrictDepartmentAccess;
use App\Http\Middleware\RestrictMyWorkUser;
use App\Models\User;
use App\Services\EntitlementService;

/**
 * v2.141.0 -- CAN THIS PERSON OPEN THAT ROUTE?
 *
 * The four gates a request meets, asked in one place so that anything which
 * OFFERS a link -- My Work, a notification -- offers it on exactly the terms
 * the server will let it through (ADR 061):
 *
 *   My Work account   RestrictMyWorkUser's allow-list
 *   Sandbox tenant    RestrictDemoTenant's blocked prefixes
 *   Department User   RestrictDepartmentAccess::allows()
 *   the plan          EntitlementService::tenantCanUseRoutePrefix()
 *
 * It answers an OFFER question. The middleware remains the boundary.
 */
class RouteReach
{
    public static function allows(?User $user, ?string $routeName): bool
    {
        if (! $user || ! $routeName) {
            return false;
        }

        $prefix = explode('.', $routeName)[0];

        if ($user->isMyWorkUser() && ! in_array($prefix, RestrictMyWorkUser::allowedPrefixes(), true)) {
            return false;
        }

        if ($user->tenant?->isDemo() && in_array($prefix, RestrictDemoTenant::blockedPrefixes(), true)) {
            return false;
        }

        if (! RestrictDepartmentAccess::allows($user, $routeName)) {
            return false;
        }

        return $user->tenant_id === null
            || app(EntitlementService::class)->tenantCanUseRoutePrefix($user->tenant, $routeName);
    }
}
