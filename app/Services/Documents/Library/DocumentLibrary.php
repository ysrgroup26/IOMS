<?php

namespace App\Services\Documents\Library;

use App\Models\User;
use App\Services\Documents\Library\Definitions\EmployeeRegisterDocument;
use App\Services\Documents\Library\Definitions\HseInspectionRegisterDocument;
use App\Services\Documents\Library\Definitions\IncidentInvestigationRegisterDocument;
use App\Services\Documents\Library\Definitions\IncidentRegisterDocument;
use App\Services\Documents\Library\Definitions\LeaveRegisterDocument;
use App\Services\Documents\Library\Definitions\ManHourRegisterDocument;
use App\Services\Documents\Library\Definitions\MaterialRequestRegisterDocument;
use App\Services\Documents\Library\Definitions\PermitToWorkRegisterDocument;
use App\Services\Documents\Library\Definitions\ProjectManpowerDocument;
use App\Services\Documents\Library\Definitions\StockMovementRegisterDocument;
use App\Services\Documents\Library\Definitions\StockPositionDocument;
use App\Services\EntitlementService;
use RuntimeException;

/**
 * v2.110.0 -- THE DOCUMENT LIBRARY, AS A REGISTRY.
 *
 * The brief asked for a template library across Management, HRD, HSE and
 * Warehouse & Logistics. This is it: a list of registered definitions,
 * each of which the renderer can serve in three formats. Adding a
 * document is one class plus one line here -- no route, no controller, no
 * export class, no Blade view.
 *
 * THE REGISTRY IS CODE, NOT CONFIGURATION, deliberately. A definition
 * carries a tenant-scoped QUERY and an authorization rule; expressing
 * those in a config array would mean either serialised closures or a
 * query language, and a mistake in either becomes a cross-tenant read.
 * A class is also the thing a reviewer can read.
 *
 * Definitions are resolved through the container, so a definition may
 * depend on a service without this class knowing about it.
 */
class DocumentLibrary
{
    /** @var list<class-string<DocumentDefinition>> */
    private const DEFINITIONS = [
        // Health, Safety & Environment
        HseInspectionRegisterDocument::class,
        PermitToWorkRegisterDocument::class,
        IncidentRegisterDocument::class,
        IncidentInvestigationRegisterDocument::class,

        // People / HRD
        EmployeeRegisterDocument::class,
        LeaveRegisterDocument::class,

        // Warehouse & Logistics
        StockPositionDocument::class,
        StockMovementRegisterDocument::class,
        MaterialRequestRegisterDocument::class,

        // Management
        ProjectManpowerDocument::class,
        ManHourRegisterDocument::class,
    ];

    /** @return list<DocumentDefinition> */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::DEFINITIONS);
    }

    /**
     * The documents this user may actually read.
     *
     * @return list<DocumentDefinition>
     */
    public function visibleTo(User $user): array
    {
        return array_values(array_filter($this->all(), fn (DocumentDefinition $d) => $this->planAllows($user, $d) && $d->authorize($user)));
    }

    /**
     * v2.143.0 (ADR 092) -- A DOCUMENT IS NEVER EASIER TO REACH THAN ITS SCREEN, ON THE PLAN AXIS TOO.
     *
     * Every definition's `authorize()` answers the ROLE question and, until
     * this release, every one returned true -- so a Starter organization,
     * whose plan opens neither People / HRD nor Warehouse Logistics, could
     * still export the leave register, stock position, stock movements,
     * Material Requests and project manpower from the library. The route
     * gate refused the screens; the library did not ask.
     *
     * The plan question is asked here, once, the same way the route gate
     * asks it: the screen a register mirrors must be reachable on this
     * tenant's plan (`tenantCanUseRoutePrefix`), and a register whose screen
     * is gated by a Module rather than a workspace asks for that Module.
     * A document with no entry here mirrors a screen every plan has.
     */
    private const SCREEN = [
        'employee-register' => 'employees.index',
        'leave-register' => 'leave-requests.index',
        'stock-position' => 'stock.index',
        'stock-movement-register' => 'stock.movements',
        'project-manpower' => 'management.workforce',   // the Management summary, not the (unsold) Projects department
        'man-hour-register' => 'man-hour.index',
    ];

    private const MODULE = [
        'material-request-register' => 'material_requests',
    ];

    private function planAllows(User $user, DocumentDefinition $definition): bool
    {
        if ($user->tenant_id === null) {
            return true; // Platform staff: no tenant plan to apply.
        }

        $entitlements = app(EntitlementService::class);
        $key = $definition->key();

        if (isset(self::SCREEN[$key]) && ! $entitlements->tenantCanUseRoutePrefix($user->tenant, self::SCREEN[$key])) {
            return false;
        }

        if (isset(self::MODULE[$key]) && ! $entitlements->tenantCanUseModule($user->tenant, self::MODULE[$key])) {
            return false;
        }

        return true;
    }

    /**
     * Resolves one document by key, FAILING CLOSED on both the key and
     * the authorization. A caller gets a definition only when the key
     * exists AND this user may read it; an unauthorized key is reported
     * as not found rather than as forbidden, so the library does not
     * confirm the existence of documents a user may not see.
     */
    public function resolveFor(User $user, string $key): DocumentDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->key() === $key && $this->planAllows($user, $definition) && $definition->authorize($user)) {
                return $definition;
            }
        }

        throw new RuntimeException('No document is registered under ['.$key.'] for this user.');
    }

    /**
     * The library as the UI needs it, grouped by workspace.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(User $user): array
    {
        return array_map(fn (DocumentDefinition $d) => [
            'key' => $d->key(),
            'title' => $d->title(),
            'description' => $d->description(),
            'workspace' => $d->workspace(),
            'formats' => $d->formats(),
            'columns' => array_column($d->columns(), 'label'),
        ], $this->visibleTo($user));
    }
}
