<?php

namespace Tests\Feature;

use App\Services\CalendarService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Tests\TestCase;

/**
 * Regression test for a real production outage (v1.11.2.4):
 * `App\Services\CalendarService::managementEvents(): Argument #1
 * ($companyIds) must be of type Illuminate\Support\Collection, array
 * given`, thrown from `DashboardController.php:164` and (found during
 * this fix, not previously reported) every department dashboard
 * controller's `departmentEvents()` call too.
 *
 * Root cause: `CalendarService` sat downstream of TWO already-established
 * tenant-scoping patterns in this codebase --
 * `Company::query()->pluck('id')` (a `Collection`, used by
 * `CalendarController`) and `DashboardStatsService::resolveCompanyIds()`
 * (a plain `array`, used by `DashboardController` and every department
 * dashboard controller) -- but was typed to accept only the first. The
 * fix widens `$companyIds` to `Collection|array` everywhere in
 * `CalendarService`, since every internal use is `whereIn('company_id',
 * $companyIds)`, which Eloquent already accepts either type for.
 *
 * Deliberately reflection-based, no HTTP round-trip / RefreshDatabase /
 * fixtures: this project has no phpunit.xml and no php/composer binary
 * was available in the environment this fix was written in (same
 * disclosure as PermitToWorkTableMappingTest and
 * EnforceTenantEntitlementSignatureTest). This asserts the actual
 * regression -- that the parameter type accepts a plain array, not only
 * a Collection -- without needing a booted application or database.
 */
class CalendarServiceCompanyIdsContractTest extends TestCase
{
    /** @return array<string, array<int, string>> */
    public static function companyIdsMethods(): array
    {
        return [
            'aggregate' => ['aggregate'],
            'managementEvents' => ['managementEvents'],
            'departmentEvents' => ['departmentEvents'],
        ];
    }

    #[DataProvider('companyIdsMethods')]
    public function test_public_methods_accept_a_plain_array_of_company_ids(string $method): void
    {
        $parameter = (new ReflectionMethod(CalendarService::class, $method))->getParameters()[0];
        $type = $parameter->getType();

        $this->assertSame('companyIds', $parameter->getName());
        $this->assertTrue(
            $this->typeAccepts($type, 'array'),
            "CalendarService::{$method}()'s \$companyIds must accept a plain array (DashboardController and every department dashboard controller pass DashboardStatsService::resolveCompanyIds()'s array return value directly, not a Collection)."
        );
        $this->assertTrue(
            $this->typeAccepts($type, \Illuminate\Support\Collection::class),
            "CalendarService::{$method}()'s \$companyIds must still accept a Collection (CalendarController passes Company::query()->pluck('id'))."
        );
    }

    private function typeAccepts(?\ReflectionType $type, string $wantedTypeName): bool
    {
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $inner) {
                if ($inner instanceof ReflectionNamedType && $inner->getName() === $wantedTypeName) {
                    return true;
                }
            }

            return false;
        }

        return $type instanceof ReflectionNamedType && $type->getName() === $wantedTypeName;
    }
}
