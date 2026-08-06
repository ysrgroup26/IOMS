<?php

namespace App\Http\Middleware;

use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

/**
 * Milestone 2 (Tenancy Foundation). Resolves the authenticated user's
 * tenant ONCE per request and binds it into CurrentTenant (read by
 * App\Models\Scopes\TenantScope) and into Spatie Permission's own team
 * context (so role/permission checks are automatically tenant-scoped
 * too, since config/permission.php's `team_foreign_key` is `tenant_id`).
 *
 * Runs globally (registered in bootstrap/app.php, same tier as
 * HandleInertiaRequests/IdentifyTenant) so it's resolved before any
 * controller or Inertia shared prop needs it. A guest request (no user)
 * resolves to "no tenant" -- CurrentTenant::id() returns null,
 * TenantScope fails closed (see its own doc comment), which is correct:
 * an unauthenticated request should never see any tenant's data.
 *
 * Deliberately separate from the pre-existing IdentifyTenant/TenantContext
 * -- those resolve which COMPANY (business unit) a request is scoped to
 * WITHIN a tenant (e.g. Super Admin's "All Companies" filter), a
 * genuinely different, smaller-grained concern that predates real
 * tenancy. Both run; neither replaces the other.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $current = app(CurrentTenant::class);

        $tenant = $user?->tenant;
        $current->set($tenant);

        // Spatie's model_has_roles/model_has_permissions pivot tables
        // require a non-null team id as part of their own primary key, so
        // a Platform Super Admin (tenant_id null, see
        // User::isPlatformAdmin()) can't use null here -- RolePermissionSeeder
        // assigns their role under a `0` sentinel (never a real tenant id;
        // distinct from TenantScope's own `-1` "unresolved" sentinel), so
        // that same `0` must be used here or ->hasRole()/->can() silently
        // fail for every Platform Super Admin at runtime. See
        // docs/ADR/008-tenancy-foundation.md.
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant?->id ?? ($user?->isPlatformAdmin() ? 0 : null));

        return $next($request);
    }
}
