<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTenantEntitlement;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression test for a real production outage (v1.11.2.3): every web
 * request -- including guest `/login`, before authentication could even
 * run -- threw `ArgumentCountError: Too few arguments to function
 * App\Http\Middleware\EnforceTenantEntitlement::handle(), 2 passed ...
 * and exactly 3 expected`.
 *
 * Root cause: `EnforceTenantEntitlement` is registered globally on the
 * `web` middleware group in `bootstrap/app.php` as a bare class string
 * (`$middleware->web(append: [EnforceTenantEntitlement::class, ...])`).
 * Laravel's Pipeline always calls `handle($request, $next)` -- exactly 2
 * arguments -- for middleware registered that way. A third `handle()`
 * parameter is only ever populated for ROUTE middleware referenced with
 * an explicit `:parameter` string (e.g. `role:admin`); it is never
 * resolved via the container just because it's type-hinted. The bug was
 * type-hinting `EntitlementService` as a third `handle()` parameter,
 * expecting Laravel to inject it the way constructor injection works.
 *
 * Deliberately no HTTP round-trip / RefreshDatabase / fixtures: this
 * project has no phpunit.xml and no php/composer binary was available in
 * the environment this fix was written in (see docs/CONVENTIONS.md and
 * the same disclosure on PermitToWorkTableMappingTest), so this asserts
 * the actual regression -- the method's required-parameter count -- via
 * reflection, which needs no database or booted application at all and
 * so is the part of this test I can be confident is correct without
 * being able to execute it.
 */
class EnforceTenantEntitlementSignatureTest extends TestCase
{
    public function test_handle_only_requires_request_and_next(): void
    {
        $method = new ReflectionMethod(EnforceTenantEntitlement::class, 'handle');

        // Laravel's Pipeline never passes more than ($request, $next) to
        // globally-registered middleware -- a handle() that requires a
        // third argument is unreachable and crashes every request.
        $this->assertSame(2, $method->getNumberOfRequiredParameters());
        $this->assertSame(2, $method->getNumberOfParameters());
    }

    public function test_entitlement_service_is_constructor_injected_not_method_injected(): void
    {
        $constructor = new ReflectionMethod(EnforceTenantEntitlement::class, '__construct');

        $this->assertSame(1, $constructor->getNumberOfParameters());
        $this->assertSame(
            'App\Services\EntitlementService',
            $constructor->getParameters()[0]->getType()?->getName()
        );
    }
}
