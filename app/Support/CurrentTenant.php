<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Milestone 2 (Tenancy Foundation). A single request-scoped holder for
 * "which Tenant is this request operating as," resolved once by
 * `App\Http\Middleware\ResolveTenant` and read everywhere else --
 * `App\Models\Company`'s global scope, Inertia shared props, the future
 * Platform Super Admin impersonation flow. Bound as a singleton in the
 * container so every consumer within one request sees the same resolved
 * value.
 *
 * Deliberately a plain holder, not itself doing resolution -- keeps the
 * "how do we figure out the tenant" logic in exactly one place (the
 * middleware) rather than scattered across every consumer.
 */
class CurrentTenant
{
    private ?Tenant $tenant = null;

    private bool $resolved = false;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->resolved = true;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    /** True once ResolveTenant has run for this request, regardless of whether a tenant was actually found. */
    public function isResolved(): bool
    {
        return $this->resolved;
    }
}
